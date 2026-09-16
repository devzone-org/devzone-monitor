<?php

namespace DevZone\LogMonitor\Support;

/**
 * Turns one JSON log line (Monolog 2 or 3 JsonFormatter output) into the
 * entry shape shipped to the monitoring server.
 */
final class EntryBuilder
{
    const DEFAULT_MESSAGE_MAX_LENGTH = 4000;
    const FINGERPRINT_MAX_LENGTH = 200;

    const LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    /** @var Redactor */
    private $redactor;

    /** @var int */
    private $messageMaxLength;

    public function __construct(Redactor $redactor, int $messageMaxLength = self::DEFAULT_MESSAGE_MAX_LENGTH)
    {
        $this->redactor = $redactor;
        $this->messageMaxLength = max(1, $messageMaxLength);
    }

    /**
     * Numeric severity for a level name, or null when unknown.
     */
    public static function severity(string $level): ?int
    {
        $level = strtolower(trim($level));

        return self::LEVELS[$level] ?? null;
    }

    /**
     * Level name for a numeric severity (rounds down to the nearest known level).
     */
    public static function levelName(int $severity): string
    {
        $name = 'debug';
        foreach (self::LEVELS as $candidate => $value) {
            if ($severity >= $value) {
                $name = $candidate;
            }
        }

        return $name;
    }

    /**
     * @return array<string, mixed>|null Null for blank, malformed or non-log lines.
     */
    public function fromLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || $line[0] !== '{') {
            return null;
        }

        $data = json_decode($line, true, 64);
        if (!is_array($data) || !array_key_exists('message', $data)) {
            return null;
        }

        $resolved = self::resolveLevel($data);
        if ($resolved === null) {
            return null;
        }
        list($level, $severity) = $resolved;

        $message = self::stringify($data['message']);
        $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : [];
        $extra = isset($data['extra']) && is_array($data['extra']) ? $data['extra'] : [];

        // Redact first, truncate second: cutting before redacting could split a
        // pattern and leave half a secret behind.
        $message = $this->redactor->redactString($message);
        $context = $this->redactor->redact($context);
        $extra = $this->redactor->redact($extra);

        return [
            'logged_at' => self::loggedAt($data['datetime'] ?? null),
            'level' => $level,
            'severity' => $severity,
            'message' => self::truncate($message, $this->messageMaxLength),
            'context' => $context,
            'extra' => $extra,
            'fingerprint' => self::fingerprint($level, $message),
        ];
    }

    /**
     * Monolog 3 emits "datetime" as an ISO string. Monolog 2 normally does too,
     * but a DateTime that reaches json_encode un-normalised is serialised as
     * {"date": "...", "timezone_type": 3, "timezone": "UTC"}. Handle both.
     *
     * @param mixed $raw
     */
    public static function loggedAt($raw): string
    {
        try {
            if (is_array($raw) && isset($raw['date']) && is_string($raw['date'])) {
                $zone = null;
                if (isset($raw['timezone']) && is_string($raw['timezone']) && $raw['timezone'] !== '') {
                    try {
                        $zone = new \DateTimeZone($raw['timezone']);
                    } catch (\Throwable $e) {
                        $zone = null;
                    }
                }
                $date = new \DateTimeImmutable($raw['date'], $zone);
            } elseif (is_string($raw) && trim($raw) !== '') {
                $date = new \DateTimeImmutable($raw);
            } else {
                $date = new \DateTimeImmutable('now');
            }
        } catch (\Throwable $e) {
            $date = new \DateTimeImmutable('now');
        }

        return $date->format('Y-m-d\TH:i:s.vP');
    }

    public static function fingerprint(string $level, string $message): string
    {
        return md5(strtolower($level) . '|' . self::normalise($message));
    }

    /**
     * Digits become "N", hex addresses / hashes become "HEX", whitespace is
     * collapsed and the result is capped so repeat occurrences of the same
     * bug share a fingerprint regardless of ids, pointers or timestamps.
     */
    public static function normalise(string $message): string
    {
        $normalised = preg_replace('/0x[0-9a-fA-F]+/', 'HEX', $message);
        $normalised = preg_replace('/\b(?=[0-9a-fA-F]*[a-fA-F])[0-9a-fA-F]{8,}\b/', 'HEX', (string) $normalised);
        $normalised = preg_replace('/\d+/', 'N', (string) $normalised);
        $normalised = preg_replace('/\s+/', ' ', trim((string) $normalised));

        return self::truncate((string) $normalised, self::FINGERPRINT_MAX_LENGTH);
    }

    public static function truncate(string $value, int $length): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $length ? mb_substr($value, 0, $length, 'UTF-8') : $value;
        }

        return strlen($value) > $length ? substr($value, 0, $length) : $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: string, 1: int}|null
     */
    private static function resolveLevel(array $data): ?array
    {
        $name = '';
        if (isset($data['level_name']) && is_string($data['level_name'])) {
            $name = strtolower(trim($data['level_name']));
        }

        $severity = null;
        if (isset($data['level'])) {
            if (is_int($data['level']) || (is_string($data['level']) && ctype_digit($data['level']))) {
                $severity = (int) $data['level'];
            } elseif (is_string($data['level']) && self::severity($data['level']) !== null) {
                $severity = self::severity($data['level']);
                if ($name === '') {
                    $name = strtolower(trim($data['level']));
                }
            }
        }

        if ($severity === null && $name !== '') {
            $severity = self::severity($name);
        }
        if ($severity === null) {
            return null;
        }
        if ($name === '' || self::severity($name) === null) {
            $name = self::levelName($severity);
        }

        return [$name, $severity];
    }

    /**
     * @param mixed $value
     */
    private static function stringify($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }
}
