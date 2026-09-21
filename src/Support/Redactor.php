<?php

namespace DevZone\LogMonitor\Support;

/**
 * The one masking policy for everything that leaves the application: log
 * messages and context, exception messages, headers, query parameters,
 * URLs, bodies (through BodySanitizer) and SQL text.
 *
 * Runs before anything is written to the spool, so nothing it masks is
 * ever stored on disk or transmitted.
 *
 * Masking is by key name wherever a structure is known (arrays, JSON, form
 * data, XML elements and attributes, headers, query parameters) and by
 * rule inside free text (key=value and "key": "value" pairs, URLs, SOAP
 * elements, known value patterns). Free text is best effort; bodies are
 * parsed instead (see BodySanitizer) and anything that cannot be parsed is
 * not kept.
 */
final class Redactor
{
    const DEFAULT_REPLACEMENT = '[REDACTED]';

    /**
     * Key names whose values are always masked. Matched by words, so
     * "cardPin", "card_pin" and "CARD-PIN" all match "pin", and "x-api-key"
     * matches "api_key". Single words of 5+ letters also match inside
     * longer names ("userpassword", "phpsessid").
     */
    const DEFAULT_KEYS = [
        'password', 'passwd', 'pwd', 'passcode', 'passphrase',
        'secret', 'client_secret', 'secret_key',
        'token', 'auth', 'authorization', 'bearer', 'jwt', 'csrf', 'xsrf',
        'api_key', 'apikey', 'access_key', 'private_key',
        'credential', 'signature', 'sig', 'cookie',
        'session_id', 'session_token', 'session_key', 'laravel_session', 'sessid',
        'otp', 'one_time_password', 'mpin', 'tpin', 'pin',
        'card', 'card_number', 'pan', 'cvv', 'cvv2', 'cvc', 'security_code',
        'iban', 'account_no', 'account_number',
        'cnic', 'ssn',
    ];

    /**
     * Header and query parameter names masked on top of DEFAULT_KEYS. Too
     * generic for body keys ("code", "key", "hash"), but in a URL or a
     * header they nearly always carry a secret.
     */
    const DEFAULT_SECRET_NAMES = [
        'key', 'code', 'hash', 'hmac', 'nonce', 'sid', 'session', 'private', 'access_token', 'credentials',
    ];

    /** Headers always masked, whatever their value. */
    const DEFAULT_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-xsrf-token', 'x-csrf-token', 'x-api-key', 'x-auth-token',
    ];

    const DEFAULT_PATTERNS = [
        'cnic' => '/\b\d{5}-?\d{7}-?\d\b/',
        'card' => '/\b(?:\d[ -]?){15}\d\b/',
        'bcrypt' => '/\$2[abxy]?\$\d{2}\$[.\/A-Za-z0-9]{53}/',
        'email' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        'jwt' => '/\beyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}/',
        'bearer' => '/(?<=\bbearer |\bbasic )[A-Za-z0-9._~+\/=-]{8,}/i',
        'private_key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s',
    ];

    /**
     * Path segments replaced with {token} in URLs, besides the built-in rule
     * (long mixed letter/digit or hex segments look like tokens).
     */
    const DEFAULT_PATH_PATTERNS = [];

    /**
     * Path words that are usually followed by a credential: /signed/{x},
     * /password/reset/{x}, /invite/{x}. The next segment is replaced when it
     * has a digit or 12+ characters (so /verify/email stays readable).
     */
    const PATH_KEYWORDS = [
        'signed', 'signature', 'sig', 'token', 'tokens', 'secret', 'key', 'keys', 'otp', 'reset', 'verify',
        'verification', 'confirm', 'confirmation', 'invite', 'invitation', 'invitations', 'activate',
        'activation', 'magic', 'magic-link', 'login', 'unsubscribe', 'share', 'shared', 'download', 'auth',
    ];

    /**
     * Per-host path rules for outgoing URLs, matched by exact host or
     * "*.suffix": 'path' => 'omit' stores no path or query at all;
     * 'path_patterns' => [regex, ...] replaces matches with {token}.
     * Services that put the credential itself in the path are omitted.
     */
    const DEFAULT_HOSTS = [
        'hooks.slack.com' => ['path' => 'omit'],
        'hooks.zapier.com' => ['path' => 'omit'],
        'outlook.office.com' => ['path' => 'omit'],
        '*.webhook.office.com' => ['path' => 'omit'],
    ];

    const PATH_NOT_KEPT = '[path not kept]';

    /** Nesting deeper than this is replaced wholesale rather than walked. */
    const MAX_DEPTH = 32;

    /** Single-word keys at least this long also match as substrings ("cnicnumber"). */
    const SUBSTRING_MIN_LENGTH = 5;

    /** Free text longer than this is not scanned for XML elements. */
    const MAX_SCAN_BYTES = 1048576;

    /**
     * A key followed by = or : in free text. The key may be quoted (also
     * JSON-escaped: \"key\"), camelCase, bracketed (user[password]) or
     * URL-encoded (user%5Bpassword%5D). "::" and "://" are not separators.
     */
    const KEY_CANDIDATE = '/(?<![A-Za-z0-9_%.\[\]\-])(\\\\?["\']?)([A-Za-z_][A-Za-z0-9_.\[\]%\-]{0,100})\1\s*(?:=|:(?![:\/]))\s*/';

    /** @var array<int, array<int, string>> Tokenised sensitive keys. */
    private $keyTokens = [];

    /** @var array<int, array<int, string>> Tokenised extra names for headers and query parameters. */
    private $secretNameTokens = [];

    /** @var array<string, bool> */
    private $headers = [];

    /** @var array<int, string> */
    private $patterns = [];

    /** @var array<int, string> */
    private $pathPatterns = [];

    /** @var array<string, array{omit: bool, patterns: array<int, string>}> */
    private $hosts = [];

    /** @var string */
    private $replacement;

    /** @var bool */
    private $stripSqlBindings;

    /** @var bool */
    private $stripTraceArguments;

    /** @var array<string, bool> key => sensitive, bounded */
    private $keyCache = [];

    /** @var int|null Values left to walk before giving up (null: no budget). */
    private $budget = null;

    /**
     * Keys and patterns given here are the whole list; fromConfig() adds
     * the configured ones to the defaults instead.
     *
     * @param array<int, string> $keys
     * @param array<int|string, string> $patterns
     * @param array<int, string> $secretNames
     * @param array<int, string> $headers
     * @param array<int, string> $pathPatterns
     * @param array<string, array<string, mixed>> $hosts
     */
    public function __construct(
        array $keys = self::DEFAULT_KEYS,
        array $patterns = self::DEFAULT_PATTERNS,
        string $replacement = self::DEFAULT_REPLACEMENT,
        bool $stripSqlBindings = true,
        bool $stripTraceArguments = true,
        array $secretNames = self::DEFAULT_SECRET_NAMES,
        array $headers = self::DEFAULT_HEADERS,
        array $pathPatterns = self::DEFAULT_PATH_PATTERNS,
        array $hosts = self::DEFAULT_HOSTS
    ) {
        $this->replacement = $replacement;
        $this->stripSqlBindings = $stripSqlBindings;
        $this->stripTraceArguments = $stripTraceArguments;

        foreach ($keys as $key) {
            if (is_string($key) && ($tokens = self::tokenize($key)) !== []) {
                $this->keyTokens[] = $tokens;
            }
        }
        foreach ($secretNames as $name) {
            if (is_string($name) && ($tokens = self::tokenize($name)) !== []) {
                $this->secretNameTokens[] = $tokens;
            }
        }
        foreach ($headers as $header) {
            if (is_string($header) && trim($header) !== '') {
                $this->headers[strtolower(trim($header))] = true;
            }
        }
        $this->patterns = self::validPatterns($patterns);
        $this->pathPatterns = self::validPatterns($pathPatterns);
        foreach ($hosts as $host => $rule) {
            if (!is_string($host) || trim($host) === '' || !is_array($rule)) {
                continue;
            }
            $this->hosts[strtolower(trim($host))] = [
                'omit' => ($rule['path'] ?? null) === 'omit',
                'patterns' => self::validPatterns(isset($rule['path_patterns']) && is_array($rule['path_patterns']) ? $rule['path_patterns'] : []),
            ];
        }
    }

    /**
     * Configured keys, patterns, query_keys, headers and path_patterns are
     * added to the built-in lists; set replace_default_keys or
     * replace_default_patterns to use only the configured ones.
     *
     * @param array<string, mixed> $config The "redact" section of config/log-monitor.php.
     */
    public static function fromConfig(array $config): self
    {
        $list = function (string $name) use ($config): array {
            return isset($config[$name]) && is_array($config[$name]) ? $config[$name] : [];
        };

        $keys = $list('keys');
        if (empty($config['replace_default_keys'])) {
            $keys = array_merge(self::DEFAULT_KEYS, $keys);
        }
        $patterns = $list('patterns');
        if (empty($config['replace_default_patterns'])) {
            $patterns = array_merge(self::DEFAULT_PATTERNS, $patterns);
        }

        return new self(
            array_values(array_unique(array_filter($keys, 'is_string'))),
            $patterns,
            isset($config['replacement']) && is_string($config['replacement']) ? $config['replacement'] : self::DEFAULT_REPLACEMENT,
            !isset($config['sql_bindings']) || (bool) $config['sql_bindings'],
            !isset($config['trace_arguments']) || (bool) $config['trace_arguments'],
            array_merge(self::DEFAULT_SECRET_NAMES, $list('query_keys')),
            array_merge(self::DEFAULT_HEADERS, $list('headers')),
            array_merge(self::DEFAULT_PATH_PATTERNS, $list('path_patterns')),
            array_merge(self::DEFAULT_HOSTS, $list('hosts'))
        );
    }

    public function replacement(): string
    {
        return $this->replacement;
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

    /**
     * Like redact(), but gives up once more than $maxValues values have
     * been visited, so a huge structure costs a bounded amount of work.
     *
     * @param mixed $value
     * @return mixed
     * @throws \OverflowException when the budget runs out
     */
    public function redactBounded($value, int $maxValues)
    {
        $this->budget = max(1, $maxValues);
        try {
            return $this->walk($value, 0);
        } finally {
            $this->budget = null;
        }
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
        if (strpos($value, '://') !== false) {
            $value = $this->redactUrlsInText($value);
        }
        if (strpos($value, '<') !== false && strlen($value) <= self::MAX_SCAN_BYTES) {
            $value = $this->redactXmlElements($value);
        }
        if (strpbrk($value, '=:') !== false) {
            $value = $this->redactKeyValues($value);
        }

        return $this->redactPatterns($value);
    }

    /**
     * Only the regex patterns (emails, cards, CNICs, hashes), without the
     * key=value and URL handling. Used for SQL text, whose literals are
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
     * A URL safe to store: credentials in it masked, token-like path
     * segments replaced with {token}, query parameters masked by name and
     * value. Fragments are dropped.
     */
    public function redactUrl(string $url): string
    {
        $url = preg_replace('/#.*$/s', '', $url) ?? '';
        if (!preg_match('~^([a-z][a-z0-9+.\-]*://)([^/?#]*)([^?#]*)(?:\?(.*))?$~is', $url, $m)) {
            // Relative: a path, maybe with a query.
            $parts = explode('?', $url, 2);

            return $this->redactPath($parts[0]) . (isset($parts[1]) && $parts[1] !== '' ? '?' . $this->redactQueryString($parts[1]) : '');
        }

        $authority = $m[2];
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = $this->replacement . '@' . substr($authority, $at + 1);
        }
        $host = preg_replace('/:\d+$/', '', $at !== false ? substr($m[2], $at + 1) : $m[2]);
        if ($this->omitsPath((string) $host)) {
            return $m[1] . $authority . '/' . self::PATH_NOT_KEPT;
        }
        $query = isset($m[4]) && $m[4] !== '' ? '?' . $this->redactQueryString($m[4]) : '';

        return $m[1] . $authority . $this->redactPath($m[3], (string) $host) . $query;
    }

    /**
     * Whether a host's rule says to store no path (nor query) at all.
     */
    public function omitsPath(?string $host): bool
    {
        $rule = $this->hostRule($host);

        return $rule !== null && $rule['omit'];
    }

    /**
     * @return array{omit: bool, patterns: array<int, string>}|null
     */
    private function hostRule(?string $host): ?array
    {
        if ($host === null || $host === '' || $this->hosts === []) {
            return null;
        }
        $host = strtolower($host);
        if (isset($this->hosts[$host])) {
            return $this->hosts[$host];
        }
        foreach ($this->hosts as $pattern => $rule) {
            if (strpos($pattern, '*.') === 0 && substr($host, -(strlen($pattern) - 1)) === substr($pattern, 1)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Replace path segments that look like tokens (reset links, signed
     * URLs, API keys in paths) with {token}. Route parameters ({id}) and
     * ordinary words and ids stay.
     */
    public function redactPath(string $path, ?string $host = null): string
    {
        if ($path === '' || $path === '/') {
            return $path;
        }
        $rule = $this->hostRule($host);
        if ($rule !== null && $rule['omit']) {
            return '/' . self::PATH_NOT_KEPT;
        }

        $segments = explode('/', $path);
        $previousSensitive = false;
        foreach ($segments as $i => $segment) {
            if ($segment === '' || $segment[0] === '{') {
                $previousSensitive = false;
                continue;
            }
            $decoded = rawurldecode($segment);
            if (self::looksLikeToken($decoded)
                || ($previousSensitive && (preg_match('/\d/', $decoded) === 1 || strlen($decoded) >= 12))) {
                $segments[$i] = '{token}';
                $previousSensitive = false;
                continue;
            }
            $previousSensitive = in_array(strtolower($decoded), self::PATH_KEYWORDS, true)
                || $this->isSensitiveKey($decoded)
                || $this->isSecretName($decoded);
            $clean = $this->redactPatterns($decoded);
            if ($clean !== $decoded) {
                $segments[$i] = '{redacted}';
            }
        }
        $path = implode('/', $segments);

        foreach (array_merge($this->pathPatterns, $rule !== null ? $rule['patterns'] : []) as $pattern) {
            $replaced = @preg_replace($pattern, '{token}', $path);
            if (is_string($replaced)) {
                $path = $replaced;
            }
        }

        return $path;
    }

    /**
     * a=1&token=x&b[]=2: values of secret names masked, the rest scrubbed.
     */
    public function redactQueryString(string $query): string
    {
        $out = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $name = rawurldecode(str_replace('+', ' ', $parts[0]));
            if (!isset($parts[1])) {
                $out[] = $parts[0];
            } elseif ($this->isSecretName($name)) {
                $out[] = $parts[0] . '=' . $this->replacement;
            } else {
                $value = rawurldecode(str_replace('+', ' ', $parts[1]));
                $clean = $this->redactPatterns($value);
                $out[] = $parts[0] . '=' . ($clean === $value ? $parts[1] : $clean);
            }
        }

        return implode('&', $out);
    }

    /**
     * A header whose value is always masked: listed in redact.headers or
     * named like a secret.
     */
    public function isSecretHeader(string $name): bool
    {
        return isset($this->headers[strtolower(trim($name))]) || $this->isSecretName($name);
    }

    /**
     * A header or query parameter name that carries a secret: a sensitive
     * key (password, token, pin...) or one of the extra names (key, code,
     * hash... plus redact.query_keys and $extra), matched by words, so
     * "x-api-key" matches "key" and "X-Auth-Password" matches "password".
     *
     * @param array<int, string> $extra
     */
    public function isSecretName(string $name, array $extra = []): bool
    {
        if ($this->isSensitiveKey($name)) {
            return true;
        }
        $tokens = self::tokenize($name);
        foreach ($this->secretNameTokens as $needle) {
            if (self::containsSequence($tokens, $needle)) {
                return true;
            }
        }
        foreach ($extra as $key) {
            if (self::containsSequence($tokens, self::tokenize((string) $key))) {
                return true;
            }
        }

        return false;
    }

    public function isSensitiveKey(string $key): bool
    {
        if (isset($this->keyCache[$key])) {
            return $this->keyCache[$key];
        }
        $tokens = self::tokenize($key);
        $sensitive = false;
        if ($tokens !== []) {
            $joined = implode('', $tokens);
            foreach ($this->keyTokens as $needle) {
                if (self::containsSequence($tokens, $needle)
                    || (count($needle) === 1 && strlen($needle[0]) >= self::SUBSTRING_MIN_LENGTH && strpos($joined, $needle[0]) !== false)) {
                    $sensitive = true;
                    break;
                }
            }
        }
        if (count($this->keyCache) >= 2000) {
            $this->keyCache = [];
        }

        return $this->keyCache[$key] = $sensitive;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function walk($value, int $depth)
    {
        if ($this->budget !== null && --$this->budget < 0) {
            throw new \OverflowException('value budget exhausted');
        }
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
     * password=x, "password": "x y", \"otp\":\"123\", userPassword: x,
     * user%5Bpassword%5D=x, Authorization: Bearer x. The value runs to its
     * closing quote (to the end of the text when the quote never closes,
     * as in a cut body) or, unquoted, to the next separator.
     */
    private function redactKeyValues(string $value): string
    {
        if (!@preg_match_all(self::KEY_CANDIDATE, $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $value;
        }

        $out = '';
        $pos = 0;
        $length = strlen($value);
        foreach ($matches as $match) {
            $start = $match[0][1];
            if ($start < $pos) {
                continue; // inside a value already masked
            }
            $key = rawurldecode($match[2][0]);
            if (!$this->isSensitiveKey($key)) {
                continue;
            }
            $valueStart = $start + strlen($match[0][0]);
            if (preg_match('/\G(?:bearer|basic|digest|token)\s+/i', $value, $scheme, 0, $valueStart) === 1) {
                $valueStart += strlen($scheme[0]);
            }
            if ($valueStart >= $length || substr_compare($value, $this->replacement, $valueStart, strlen($this->replacement)) === 0) {
                continue; // nothing there, or already masked
            }

            $quote = '';
            if ($value[$valueStart] === '"' || $value[$valueStart] === "'") {
                $quote = $value[$valueStart];
            } elseif ($value[$valueStart] === '\\' && $valueStart + 1 < $length && ($value[$valueStart + 1] === '"' || $value[$valueStart + 1] === "'")) {
                $quote = '\\' . $value[$valueStart + 1];
            }

            if ($quote !== '') {
                $close = self::closingQuote($value, $valueStart + strlen($quote), $quote);
                $out .= substr($value, $pos, $valueStart - $pos) . $quote . $this->replacement;
                if ($close === null) {
                    $pos = $length; // never closed: everything after it is the value
                } else {
                    $out .= $quote;
                    $pos = $close + strlen($quote);
                }
                continue;
            }

            $valueLength = strcspn($value, " \t\r\n,;&\"'<>)]}", $valueStart);
            if ($valueLength === 0) {
                continue;
            }
            $out .= substr($value, $pos, $valueStart - $pos) . $this->replacement;
            $pos = $valueStart + $valueLength;
        }

        return $out . substr($value, $pos);
    }

    private static function closingQuote(string $value, int $from, string $quote): ?int
    {
        $length = strlen($value);
        if ($quote[0] === '\\') {
            $close = strpos($value, $quote, $from);

            return $close === false ? null : $close;
        }
        for ($i = $from; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($char === $quote) {
                return $i;
            }
        }

        return null;
    }

    /**
     * URLs inside text (exception messages, headers such as Referer and
     * Location, log lines): credentials, token paths and secret query
     * values masked.
     */
    private function redactUrlsInText(string $value): string
    {
        $result = @preg_replace_callback(
            '~\b[a-z][a-z0-9+.\-]*://[^\s"\'<>`]+~i',
            function (array $m) {
                $trail = '';
                $url = $m[0];
                if (preg_match('/[.,;:!?)\]}]+$/', $url, $t)) {
                    $trail = $t[0];
                    $url = substr($url, 0, -strlen($trail));
                }

                return $this->redactUrl($url) . $trail;
            },
            $value
        );

        return is_string($result) ? $result : $value;
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
     * <Password>x</Password>, <ns:CardPin><![CDATA[1234]]></ns:CardPin>,
     * <Credentials><User>a</User><Pass>b</Pass></Credentials>: everything
     * between a sensitive element's opening tag and its closing tag (or the
     * end of the text, when the closing tag is missing) is replaced.
     * Attributes are covered by the key=value rule.
     */
    private function redactXmlElements(string $value): string
    {
        if (!@preg_match_all('/<([A-Za-z_][\w.\-]*:)?([A-Za-z_][\w.\-]*)(?:\s[^<>]*)?>/', $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $value;
        }

        $out = '';
        $pos = 0;
        foreach ($matches as $tag) {
            $start = $tag[0][1];
            if ($start < $pos || substr($tag[0][0], -2) === '/>' || !$this->isSensitiveKey($tag[2][0])) {
                continue;
            }
            $name = ($tag[1][1] >= 0 ? $tag[1][0] : '') . $tag[2][0];
            $contentStart = $start + strlen($tag[0][0]);
            $close = strpos($value, '</' . $name, $contentStart);
            $contentEnd = $close === false ? strlen($value) : $close;
            if ($contentEnd === $contentStart) {
                continue;
            }
            $out .= substr($value, $pos, $contentStart - $pos) . $this->replacement;
            $pos = $contentEnd;
        }

        return $out . substr($value, $pos);
    }

    /**
     * getTraceAsString() lines look like
     *   #3 /app/Http/Controllers/AuthController.php(42): App\Auth->login('john', 'hunter2')
     * The argument list carries raw scalars, so replace it with "(...)".
     */
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

    /**
     * A path segment that is a credential rather than a name or an id:
     * 16+ characters mixing letters and digits (reset tokens, signed-URL
     * hashes, UUIDs, API keys), 16+ hex digits, or a JWT.
     */
    public static function looksLikeToken(string $segment): bool
    {
        $length = strlen($segment);
        // id:secret, as in /bot123456:AAH-xyz.../sendMessage
        if (preg_match('/^[^:\/]*:[A-Za-z0-9_\-.~]{12,}$/', $segment) === 1) {
            return true;
        }
        if ($length < 16) {
            return false;
        }
        // Random letters: many capitals scattered through, unlike camelCase words.
        if ($length >= 20 && preg_match('/^[A-Za-z_\-]+$/', $segment) === 1
            && preg_match_all('/[A-Z]/', $segment) >= 0.3 * $length && preg_match('/[a-z]/', $segment) === 1) {
            return true;
        }
        if (preg_match('/^eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', $segment) === 1) {
            return true;
        }
        if (preg_match('/^[0-9a-f\-]+$/i', $segment) === 1 && preg_match('/[0-9]/', $segment) === 1) {
            return true;
        }
        if (preg_match('/^[A-Za-z0-9_\-.~+=%]+$/', $segment) === 1
            && preg_match('/[0-9]/', $segment) === 1
            && preg_match('/[A-Za-z]/', $segment) === 1) {
            return true;
        }

        return $length >= 40 && strpos($segment, ' ') === false;
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
     * @param array<int|string, mixed> $patterns
     * @return array<int, string>
     */
    private static function validPatterns(array $patterns): array
    {
        $valid = [];
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, '') !== false) {
                $valid[] = $pattern;
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * "cardPin", "card_pin", "card-pin", "CARD PIN", "user[pin]" all end in
     * the words ["card", "pin"] / ["user", "pin"].
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
