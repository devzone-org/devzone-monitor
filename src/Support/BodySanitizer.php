<?php

namespace DevZone\LogMonitor\Support;

use DevZone\LogMonitor\Capture\BodyReader;

/**
 * Turns a request or response body into text that is safe to store, or
 * into a note saying why it was not kept.
 *
 * The whole body is parsed first and masked by key, and only then cut to
 * size, so a secret can never survive because a cut broke the structure
 * that would have identified it:
 *
 *   json   decoded, masked by key, re-encoded
 *   form   parsed (a=1&b=2), masked by key, stored as JSON
 *   xml    parsed with DOM (no network, no DOCTYPE, CDATA merged);
 *          sensitive elements lose their whole content, sensitive
 *          attributes their value, other text is scrubbed
 *   text   only when "text" is in body_formats: scrubbed as free text
 *
 * Bodies larger than max_parse_bytes, bodies that do not parse, HTML,
 * binary content and (by default) plain text are not kept: only a note of
 * their type and size.
 */
final class BodySanitizer
{
    const DEFAULT_FORMATS = ['json', 'form', 'xml'];

    /** Kept in place of values outside an only_fields allowlist. */
    const NOT_KEPT = '[not kept]';

    /** @var Redactor */
    private $redactor;

    /** @var int */
    private $maxParseBytes;

    /** @var int */
    private $maxValues;

    /** @var array<string, bool> */
    private $formats;

    /**
     * @param array<int, string> $formats json, form, xml, text
     */
    public function __construct(Redactor $redactor, int $maxParseBytes = 65536, int $maxValues = 5000, array $formats = self::DEFAULT_FORMATS)
    {
        $this->redactor = $redactor;
        $this->maxParseBytes = max(1024, $maxParseBytes);
        $this->maxValues = max(10, $maxValues);
        $this->formats = array_fill_keys(array_map('strtolower', array_filter($formats, 'is_string')), true);
    }

    /**
     * @param array<string, mixed> $redact The "redact" config section.
     */
    public static function fromConfig(Redactor $redactor, array $redact): self
    {
        return new self(
            $redactor,
            (int) ($redact['max_body_parse_bytes'] ?? 65536),
            (int) ($redact['max_body_values'] ?? 5000),
            isset($redact['body_formats']) && is_array($redact['body_formats']) ? $redact['body_formats'] : self::DEFAULT_FORMATS
        );
    }

    /**
     * How many bytes of a body are worth reading: one more than the parse
     * limit, so a reader can tell a body that fits from one that does not.
     */
    public function readLimit(): int
    {
        return $this->maxParseBytes + 1;
    }

    /**
     * @param string      $body        The body, or its first bytes.
     * @param int|null    $fullSize    Full size when known; larger than strlen($body) when only part was read.
     * @param array<int, string> $onlyFields When not empty, only values under these keys are kept.
     * @return array{text: string, redacted: bool, cut: ?string}
     */
    public function sanitize(string $body, ?string $contentType, int $maxBytes, ?int $fullSize = null, array $onlyFields = []): array
    {
        $size = max(strlen($body), (int) $fullSize);
        $format = self::format((string) $contentType, $body);

        if ($format === 'binary' || $format === 'html') {
            return $this->note($format . ' body', $size);
        }
        if ($format === 'text' && !isset($this->formats['text'])) {
            return $this->note('text body', $size);
        }
        if (!isset($this->formats[$format]) && $format !== 'text') {
            return $this->note($format . ' body', $size, 'format not enabled in redact.body_formats');
        }
        if ($size > $this->maxParseBytes || strlen($body) < $size) {
            return $this->note($format . ' body', $size, 'over the ' . BodyReader::formatBytes($this->maxParseBytes) . ' parse limit');
        }

        try {
            if ($format === 'json') {
                $decoded = json_decode($body, true, 128);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->note('json body', $size, 'could not be parsed');
                }
                if (!is_array($decoded)) {
                    // A bare string or number has no key an allowlist could approve.
                    if ($onlyFields !== []) {
                        return $this->note('json body', $size, 'no fields to match only_fields');
                    }
                    $clean = is_string($decoded) ? $this->redactor->redactString($decoded) : $decoded;

                    return $this->bounded((string) self::json($clean), $maxBytes, $clean !== $decoded);
                }

                return $this->structured($decoded, $maxBytes, $onlyFields, null, 'json body', $size);
            }

            if ($format === 'form') {
                parse_str($body, $fields);

                return $this->structured(is_array($fields) ? $fields : [], $maxBytes, $onlyFields, null, 'form body', $size);
            }

            if ($format === 'xml') {
                return $this->xml($body, $size, $maxBytes, $onlyFields);
            }

            if ($onlyFields !== []) {
                return $this->note('text body', $size, 'no fields to match only_fields');
            }
            $clean = $this->redactor->redactString($body);

            return $this->bounded($clean, $maxBytes, $clean !== $body);
        } catch (\OverflowException $e) {
            return $this->note($format . ' body', $size, 'more than ' . number_format($this->maxValues) . ' values');
        }
    }

    /**
     * An already parsed body ($request->input()): masked by key, filtered,
     * encoded as JSON and cut to size.
     *
     * @param array<mixed> $data
     * @param array<int, string> $onlyFields
     * @return array{text: string, redacted: bool, cut: ?string}
     */
    public function structured(array $data, int $maxBytes, array $onlyFields = [], ?string $note = null, string $what = 'body', ?int $size = null): array
    {
        // Already-parsed input gets the same byte limit as a raw body, checked
        // before any masking work is done on it.
        $bytes = self::sizeOf($data, $this->maxParseBytes, 0);
        if ($bytes > $this->maxParseBytes) {
            return $this->note($what, $size, 'over the ' . BodyReader::formatBytes($this->maxParseBytes) . ' parse limit');
        }
        try {
            $clean = $this->redactor->redactBounded($data, $this->maxValues);
        } catch (\OverflowException $e) {
            return $this->note($what, $size, 'more than ' . number_format($this->maxValues) . ' values');
        }
        $redacted = $clean != $data;
        if ($onlyFields !== []) {
            $clean = self::onlyFields($clean, self::allowlist($onlyFields), false);
        }

        return $this->bounded((string) self::json($clean), $maxBytes, $redacted, $note);
    }

    /**
     * @param array<int, string> $onlyFields
     * @return array{text: string, redacted: bool, cut: ?string}
     */
    private function xml(string $body, int $size, int $maxBytes, array $onlyFields): array
    {
        if (!class_exists(\DOMDocument::class)) {
            return $this->note('xml body', $size, 'the dom extension is not installed');
        }
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            return $this->note('xml body', $size, 'DOCTYPE not accepted');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();
            if (!$loaded || $document->documentElement === null) {
                return $this->note('xml body', $size, 'could not be parsed');
            }

            $state = ['masked' => false, 'budget' => $this->maxValues];
            $this->xmlElement($document->documentElement, $onlyFields !== [] ? self::allowlist($onlyFields) : [], false, $state);
            $xml = $document->saveXML($document->documentElement);
            if (!is_string($xml)) {
                return $this->note('xml body', $size, 'could not be parsed');
            }

            return $this->bounded($xml, $maxBytes, $state['masked']);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param array<string, bool> $only
     * @param array{masked: bool, budget: int} $state
     */
    private function xmlElement(\DOMElement $element, array $only, bool $allowed, array &$state): void
    {
        if (--$state['budget'] < 0) {
            throw new \OverflowException('value budget exhausted');
        }
        $name = (string) $element->localName;
        $replacement = $this->redactor->replacement();

        if ($element->hasAttributes()) {
            foreach (iterator_to_array($element->attributes) as $attribute) {
                /** @var \DOMAttr $attribute */
                $value = (string) $attribute->value;
                if ($this->redactor->isSensitiveKey((string) $attribute->localName)) {
                    $clean = $replacement;
                } elseif ($only !== [] && !$allowed && !isset($only[strtolower((string) $attribute->localName)])) {
                    $clean = self::NOT_KEPT;
                } else {
                    $clean = $this->redactor->redactString($value);
                }
                if ($clean !== $value) {
                    $state['masked'] = $state['masked'] || $clean !== self::NOT_KEPT;
                    $attribute->value = $clean;
                }
            }
        }

        if ($this->redactor->isSensitiveKey($name)) {
            while ($element->firstChild !== null) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($element->ownerDocument->createTextNode($replacement));
            $state['masked'] = true;

            return;
        }

        $allowed = $allowed || isset($only[strtolower($name)]);
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $this->xmlElement($child, $only, $allowed, $state);
            } elseif ($child instanceof \DOMText) {
                if (--$state['budget'] < 0) {
                    throw new \OverflowException('value budget exhausted');
                }
                $text = (string) $child->nodeValue;
                if (trim($text) === '') {
                    continue;
                }
                $clean = $only !== [] && !$allowed ? self::NOT_KEPT : $this->redactor->redactString($text);
                if ($clean !== $text) {
                    $state['masked'] = $state['masked'] || $clean !== self::NOT_KEPT;
                    $child->nodeValue = $clean;
                }
            } else {
                // Comments and processing instructions carry nothing we need.
                $element->removeChild($child);
            }
        }
    }

    /**
     * json | form | xml | html | binary | text
     */
    public static function format(string $contentType, string $body): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));
        if ($type !== '') {
            if (strpos($type, 'json') !== false) {
                return 'json';
            }
            if ($type === 'application/x-www-form-urlencoded') {
                return 'form';
            }
            if (strpos($type, 'html') !== false) {
                return 'html';
            }
            if (strpos($type, 'xml') !== false || strpos($type, 'soap') !== false) {
                return 'xml';
            }
            if (BodyReader::isBinaryType($type)) {
                return 'binary';
            }
        }
        if (strpos($body, "\0") !== false) {
            return 'binary';
        }

        $trimmed = ltrim($body);
        $first = $trimmed === '' ? '' : $trimmed[0];
        if ($first === '{' || $first === '[') {
            return 'json';
        }
        if ($first === '<') {
            return preg_match('/^<(?:!doctype\s+html|html|head|body)\b/i', $trimmed) === 1 ? 'html' : 'xml';
        }
        if (preg_match('/^[A-Za-z0-9_.\-\[\]%+]+=[^&\s]*(?:&[A-Za-z0-9_.\-\[\]%+]+=[^&\s]*)*$/', trim($body)) === 1) {
            return 'form';
        }

        return 'text';
    }

    /**
     * Rough size of parsed input (keys and scalar values), counting stops
     * as soon as it passes $limit.
     *
     * @param mixed $value
     */
    private static function sizeOf($value, int $limit, int $depth): int
    {
        if (is_string($value)) {
            return strlen($value);
        }
        if (!is_array($value)) {
            return is_scalar($value) ? 8 : 0;
        }
        if ($depth > Redactor::MAX_DEPTH) {
            return 0;
        }
        $total = 2;
        foreach ($value as $key => $item) {
            $total += strlen((string) $key) + 4 + self::sizeOf($item, $limit - $total, $depth + 1);
            if ($total > $limit) {
                return $total;
            }
        }

        return $total;
    }

    /**
     * @param mixed $value
     * @param array<string, bool> $only
     * @return mixed
     */
    private static function onlyFields($value, array $only, bool $allowed)
    {
        if (!is_array($value)) {
            return $allowed ? $value : self::NOT_KEPT;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::onlyFields($item, $only, $allowed || (is_string($key) && isset($only[strtolower($key)])));
        }

        return $value;
    }

    /**
     * @param array<int, string> $fields
     * @return array<string, bool>
     */
    private static function allowlist(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (is_string($field) && $field !== '') {
                $out[strtolower($field)] = true;
            }
        }

        return $out;
    }

    /**
     * @return array{text: string, redacted: bool, cut: ?string}
     */
    private function bounded(string $text, int $maxBytes, bool $redacted, ?string $note = null): array
    {
        $kept = Text::limitBytes($text, $maxBytes);
        $cut = strlen($kept) < strlen($text)
            ? 'kept ' . BodyReader::formatBytes(strlen($kept)) . ' of ' . BodyReader::formatBytes(strlen($text))
            : null;
        if ($cut !== null) {
            $kept .= "\n…[cut: {$cut}]";
        }
        if ($note !== null) {
            $cut = $cut !== null ? "{$cut}; {$note}" : $note;
        }

        return ['text' => $kept, 'redacted' => $redacted, 'cut' => $cut];
    }

    /**
     * @return array{text: string, redacted: bool, cut: string}
     */
    private function note(string $what, ?int $size, ?string $why = null): array
    {
        $text = '[' . $what . ' not kept' . ($size !== null ? ', ' . BodyReader::formatBytes($size) : '') . ($why !== null ? ': ' . $why : '') . ']';

        return ['text' => $text, 'redacted' => false, 'cut' => trim($text, '[]')];
    }

    /**
     * @param mixed $value
     */
    private static function json($value): ?string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : null;
    }
}
