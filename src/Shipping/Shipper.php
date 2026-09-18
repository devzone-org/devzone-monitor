<?php

namespace DevZone\LogMonitor\Shipping;

use DevZone\LogMonitor\Spool\SpoolDirectory;
use DevZone\LogMonitor\Transport\Transport;

/**
 * One ship run: rotate current.ndjson, enforce the caps, then send batches
 * oldest first. A batch file is deleted only after every line in it has
 * been accepted; progress inside a batch is remembered so a retry never
 * resends lines the server already accepted.
 */
class Shipper
{
    /** @var SpoolDirectory */
    private $directory;

    /** @var Transport */
    private $transport;

    /** @var array<string, mixed> */
    private $config;

    /** @var array<string, mixed> Batch envelope fields (app, env, host, client, package). */
    private $envelope;

    /** @var callable(): float */
    private $clock;

    /**
     * @param array<string, mixed> $config   Full log-monitor config.
     * @param array<string, mixed> $envelope
     */
    public function __construct(SpoolDirectory $directory, Transport $transport, array $config, array $envelope, ?callable $clock = null)
    {
        $this->directory = $directory;
        $this->transport = $transport;
        $this->config = $config;
        $this->envelope = $envelope;
        $this->clock = $clock ?: function () {
            return microtime(true);
        };
    }

    /**
     * @return array{records: int, batches: int, requests: int, malformed: int, dropped_batches: int, quarantined: int, pending_batches: int, stopped: string|null}
     */
    public function run(bool $dryRun = false): array
    {
        $started = $this->now();
        $shipping = isset($this->config['shipping']) && is_array($this->config['shipping']) ? $this->config['shipping'] : [];
        $spool = isset($this->config['spool']) && is_array($this->config['spool']) ? $this->config['spool'] : [];
        $budget = (float) ($shipping['time_budget_seconds'] ?? 50);
        $perRequest = max(1, (int) ($shipping['records_per_request'] ?? 1000));
        $maxBytes = max(1024, (int) ($shipping['max_bytes_per_request'] ?? 2 * 1024 * 1024));
        $quarantineAfter = max(1, (int) ($shipping['quarantine_after'] ?? 5));

        $summary = [
            'records' => 0,
            'batches' => 0,
            'requests' => 0,
            'malformed' => 0,
            'dropped_batches' => 0,
            'quarantined' => 0,
            'pending_batches' => 0,
            'stopped' => null,
        ];

        if (!$dryRun) {
            $this->directory->rotateCurrent();
            $summary['dropped_batches'] = $this->directory->prune(
                (int) ($spool['max_total_bytes'] ?? 200 * 1024 * 1024),
                (int) ($spool['max_age_days'] ?? 7)
            );
        }

        foreach ($this->directory->batches() as $batch) {
            if ($budget > 0 && $this->now() - $started >= $budget) {
                $summary['stopped'] = 'time budget reached';
                break;
            }

            $content = $this->directory->read($batch);
            if ($content === null) {
                continue;
            }
            list($lines, $malformed) = self::validLines($content);
            $summary['malformed'] += $malformed;

            $progress = $this->directory->progress($batch);
            $remaining = array_slice($lines, $progress['sent']);

            if ($dryRun) {
                $summary['records'] += count($remaining);
                $summary['batches']++;
                continue;
            }

            $complete = true;
            foreach (self::chunks($remaining, $perRequest, $maxBytes) as $chunk) {
                $result = $this->transport->send($this->body($chunk));
                $summary['requests']++;

                if ($result['ok']) {
                    $progress['sent'] += count($chunk);
                    $progress['failures'] = 0;
                    $progress['last_error'] = null;
                    $summary['records'] += count($chunk);
                    $this->directory->saveProgress($batch, $progress);
                    continue;
                }

                $complete = false;
                $progress['failures']++;
                $progress['last_error'] = $result['error'];
                $this->directory->saveProgress($batch, $progress);

                if (!$result['retryable'] && $progress['failures'] >= $quarantineAfter) {
                    $this->directory->quarantine($batch);
                    $summary['quarantined']++;
                    break; // move on to the next batch
                }

                $summary['stopped'] = (string) $result['error'];
                break 2; // server down or rejecting: retry everything next run
            }

            if ($complete) {
                $this->directory->delete($batch);
                $summary['batches']++;
            }
        }

        $summary['pending_batches'] = count($this->directory->batches());

        if (!$dryRun) {
            $this->directory->saveLastRun([
                'at' => gmdate('Y-m-d\TH:i:s\Z'),
                'duration_ms' => (int) round(($this->now() - $started) * 1000),
            ] + $summary);
        }

        return $summary;
    }

    /**
     * @param array<int, string> $lines
     */
    public function body(array $lines): string
    {
        $meta = $this->envelope + [
            'v' => 2,
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'count' => count($lines),
        ];
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $json = is_string($json) ? $json : '{}';

        // Splice the already-encoded records in rather than decoding and
        // re-encoding every line.
        return substr($json, 0, -1) . ',"records":[' . implode(',', $lines) . ']}';
    }

    /**
     * @return array{0: array<int, string>, 1: int} [valid lines, malformed count]
     */
    public static function validLines(string $content): array
    {
        $lines = [];
        $malformed = 0;
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line[0] !== '{' || substr($line, -1) !== '}' || !is_array(json_decode($line, true))) {
                $malformed++;
                continue;
            }
            $lines[] = $line;
        }

        return [$lines, $malformed];
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array<int, string>>
     */
    public static function chunks(array $lines, int $perRequest, int $maxBytes): array
    {
        $chunks = [];
        $chunk = [];
        $bytes = 0;
        foreach ($lines as $line) {
            $size = strlen($line) + 1;
            if ($chunk !== [] && (count($chunk) >= $perRequest || $bytes + $size > $maxBytes)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }
            $chunk[] = $line;
            $bytes += $size;
        }
        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    private function now(): float
    {
        return (float) call_user_func($this->clock);
    }
}
