<?php

namespace DevZone\LogMonitor\Support;

/**
 * Strips secrets and personal data from log entries before they leave the host.
 *
 * Runs client-side, before shipping and before queueing, so nothing sensitive
 * is ever transmitted or written to Redis / the jobs table / failed_jobs.
 * Applies to messages, context, extra and exception traces alike.
 */
final class Redactor
{
    const DEFAULT_REPLACEMENT = '[REDACTED]';

    const DEFAULT_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'authorization',
        'cnic',
        'card',
        'cvv',
        'pin',
        'iban',
        'account_no',
    ];

    const DEFAULT_PATTERNS = [
        'cnic' => '/\b\d{5}-?\d{7}-?\d\b/',
        'card' => '/\b(?:\d[ -]?){15}\d\b/',
        'bcrypt' => '/\$2[abxy]?\$\d{2}\$[.\/A-Za-z0-9]{53}/',
        'email' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
    ];

    /** Nesting deeper than this is replaced wholesale rather than walked. */
    const MAX_DEPTH = 32;

    /** Single-token keys at least this long also match as substrings ("cnicnumber"). */
    const SUBSTRING_MIN_LENGTH = 5;

    /** @var array<int, array<int, string>> Tokenised sensitive keys. */
    private $keyTokens = [];

    /** @var array<int, string> */
    private $patterns = [];

    /** @var string */
    private $replacement;

    /** @var bool */
    private $stripSqlBindings;

    /** @var bool */
    private $stripTraceArguments;

    /** @var string|null Regex matching "key=value" / "key: value" inside strings. */
    private $keyValuePattern = null;

    /**
     * @param array<int, string> $keys
     * @param array<int|string, string> $patterns
     */
    public function __construct(
        array $keys = self::DEFAULT_KEYS,
        array $patterns = self::DEFAULT_PATTERNS,
        string $replacement = self::DEFAULT_REPLACEMENT,
        bool $stripSqlBindings = true,
        bool $stripTraceArguments = true
    ) {
        $this->replacement = $replacement;
        $this->stripSqlBindings = $stripSqlBindings;
        $this->stripTraceArguments = $stripTraceArguments;

        $cleanKeys = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $tokens = self::tokenize($key);
            if ($tokens === []) {
                continue;
            }
            $this->keyTokens[] = $tokens;
            $cleanKeys[] = strtolower(trim($key));
        }

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, '') !== false) {
                $this->patterns[] = $pattern;
            }
        }

        if ($cleanKeys !== []) {
            $alternation = implode('|', array_map(function ($key) {
                return preg_quote($key, '/');
            }, array_unique($cleanKeys)));

            // key[_suffix] [quote] (= or :) [quote] [Bearer ] value
            $this->keyValuePattern = '/(?<![a-z0-9])(' . $alternation . ')([a-z0-9_]*)'
                . '(["\']?\s*[=:]\s*["\']?)((?:bearer|basic)\s+)?([^\s,;&"\'\]\})]+)/i';
        }
    }

    /**
     * @param array<string, mixed> $config The "redact" section of config/log-monitor.php.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            isset($config['keys']) && is_array($config['keys']) ? $config['keys'] : self::DEFAULT_KEYS,
            isset($config['patterns']) && is_array($config['patterns']) ? $config['patterns'] : self::DEFAULT_PATTERNS,
            isset($config['replacement']) && is_string($config['replacement']) ? $config['replacement'] : self::DEFAULT_REPLACEMENT,
            !isset($config['sql_bindings']) || (bool) $config['sql_bindings'],
            !isset($config['trace_arguments']) || (bool) $config['trace_arguments']
        );
    }

    /**
     * Redact any value: arrays are walked recursively, strings are scrubbed,
     * everything else passes through untouched.
     *
     * @param mixed $value
     * @return mixed
     */
    public function redact($value)
    {
        return $this->walk($value, 0);
    }

    public function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if ($this->stripSqlBindings) {
            $value = $this->stripSqlBindings($value);
        }
        if ($this->stripTraceArguments) {
            $value = $this->stripTraceArguments($value);
        }
        if (strpos($value, '<') !== false && strpos($value, '</') !== false) {
            $value = $this->redactXmlElements($value);
        }
        if ($this->keyValuePattern !== null) {
            $replaced = @preg_replace($this->keyValuePattern, '$1$2$3$4' . $this->replacementForRegex(), $value);
            if (is_string($replaced)) {
                $value = $replaced;
            }
        }
        foreach ($this->patterns as $pattern) {
            $replaced = @preg_replace($pattern, $this->replacementForRegex(), $value);
            if (is_string($replaced)) {
                $value = $replaced;
            }
        }

        return $value;
    }

    /**
     * Only the regex patterns (emails, cards, CNICs, hashes), without the
     * key=value and SQL handling. Used for SQL text, whose literals are
     * already masked and whose column names must stay readable.
     */
    public function redactPatterns(string $value): string
    {
        foreach ($this->patterns as $pattern) {
            $replaced = @preg_replace($pattern, $this->replacementForRegex(), $value);
            if (is_string($replaced)) {
                $value = $replaced;
            }
        }

        return $value;
    }

    /**
     * Redact a body of unknown format: JSON is decoded and redacted by key,
     * anything else (form data, XML/SOAP, text) goes through redactString.
     */
    public function redactBody(string $body): string
    {
        $trimmed = ltrim($body);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $encoded = json_encode($this->redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if (is_string($encoded)) {
                    return $encoded;
                }
            }
        }

        return $this->redactString($body);
    }

    /**
     * A header or query parameter name that carries a secret: a sensitive
     * key (redact.keys: password, token, pin...) or one of $extra, matched
     * by words, so "x-api-key" matches "api_key" and "key", and
     * "X-Auth-Password" matches "password".
     *
     * @param array<int, string> $extra
     */
    public function isSecretName(string $name, array $extra = []): bool
    {
        if ($this->isSensitiveKey($name)) {
            return true;
        }
        $tokens = self::tokenize($name);
        foreach ($extra as $key) {
            if (self::containsSequence($tokens, self::tokenize((string) $key))) {
                return true;
            }
        }

        return false;
    }

    public function isSensitiveKey(string $key): bool
    {
        $tokens = self::tokenize($key);
        if ($tokens === []) {
            return false;
        }
        $joined = implode('', $tokens);

        foreach ($this->keyTokens as $needle) {
            if (self::containsSequence($tokens, $needle)) {
                return true;
            }
            if (count($needle) === 1
                && strlen($needle[0]) >= self::SUBSTRING_MIN_LENGTH
                && strpos($joined, $needle[0]) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function walk($value, int $depth)
    {
        if ($depth > self::MAX_DEPTH) {
            return $this->replacement;
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $out[$key] = $this->replacement;
                    continue;
                }
                $out[$key] = $this->walk($item, $depth + 1);
            }

            return $out;
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        return $value;
    }

    /**
     * Laravel's QueryException message ends with "(SQL: <statement with the
     * bindings interpolated>)". Keep the statement shape, drop the values.
     */
    private function stripSqlBindings(string $value): string
    {
        if (stripos($value, '(SQL: ') === false) {
            return $value;
        }

        $replacement = $this->replacement;
        $result = @preg_replace_callback(
            '/\(SQL: (.*)\)(\s*)$/s',
            function (array $m) use ($replacement) {
                $sql = @preg_replace('/\b(values|set|where|having)\b.*$/is', '$1 ' . self::escapeReplacement($replacement), $m[1]);

                return '(SQL: ' . (is_string($sql) ? $sql : $replacement) . ')' . $m[2];
            },
            $value
        );

        return is_string($result) ? $result : $value;
    }

    /**
     * getTraceAsString() lines look like
     *   #3 /app/Http/Controllers/AuthController.php(42): App\Auth->login('john', 'hunter2')
     * The argument list carries raw scalars, so replace it with "(...)".
     */
    /**
     * <Password>x</Password>, <ns:CardPin>1234</ns:CardPin>: replace the text
     * of elements whose (local) name is sensitive. Attributes are covered by
     * the key=value rule.
     */
    private function redactXmlElements(string $value): string
    {
        $replacement = $this->replacement;
        $result = @preg_replace_callback(
            '/<((?:[A-Za-z_][\w.-]*:)?([A-Za-z_][\w.-]*))(\s[^<>]*)?>([^<]*)<\/\1\s*>/',
            function (array $m) use ($replacement) {
                if ($m[4] === '' || !$this->isSensitiveKey($m[2])) {
                    return $m[0];
                }

                return '<' . $m[1] . $m[3] . '>' . $replacement . '</' . $m[1] . '>';
            },
            $value
        );

        return is_string($result) ? $result : $value;
    }

    private function stripTraceArguments(string $value): string
    {
        if (!preg_match('/^#\d+ /m', $value)) {
            return $value;
        }

        $result = @preg_replace(
            '/^(#\d+ (?:\[internal function\]|.+?\(\d+\)): [^\r\n(]+)\(.*\)$/m',
            '$1(...)',
            $value
        );

        return is_string($result) ? $result : $value;
    }

    private function replacementForRegex(): string
    {
        return self::escapeReplacement($this->replacement);
    }

    private static function escapeReplacement(string $replacement): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement);
    }

    /**
     * "cardPin", "card_pin", "card-pin", "CARD PIN" all become ["card", "pin"].
     *
     * @return array<int, string>
     */
    private static function tokenize(string $key): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key);
        $parts = preg_split('/[^a-z0-9]+/', strtolower((string) $spaced), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values($parts) : [];
    }

    /**
     * @param array<int, string> $haystack
     * @param array<int, string> $needle
     */
    private static function containsSequence(array $haystack, array $needle): bool
    {
        $n = count($needle);
        $h = count($haystack);
        if ($n === 0 || $n > $h) {
            return false;
        }
        for ($i = 0; $i <= $h - $n; $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return true;
            }
        }

        return false;
    }
}
