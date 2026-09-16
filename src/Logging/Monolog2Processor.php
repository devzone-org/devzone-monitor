<?php

namespace DevZone\LogMonitor\Logging;

/**
 * Monolog 2 processor. Records are plain arrays.
 *
 * Deliberately does not implement Monolog\Processor\ProcessorInterface: the
 * Monolog 2 and 3 signatures are incompatible, and a plain invokable is
 * accepted by both versions' pushProcessor().
 */
final class Monolog2Processor
{
    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function __invoke(array $record): array
    {
        $extra = isset($record['extra']) && is_array($record['extra']) ? $record['extra'] : [];
        $record['extra'] = array_merge($extra, AppContext::get());

        return $record;
    }
}
