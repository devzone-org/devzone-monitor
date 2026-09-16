<?php

namespace DevZone\LogMonitor\Logging;

use Monolog\LogRecord;

/**
 * Monolog 3 processor. Records are immutable LogRecord objects.
 *
 * Uses named-argument syntax, which is PHP 8 only. This class lives in its own
 * file and is only ever referenced via ::class (which does not trigger the
 * autoloader) until AddAppContext has confirmed Monolog\LogRecord exists, so
 * the file is never compiled on PHP 7.x / Monolog 2 installations.
 */
final class Monolog3Processor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: array_merge($record->extra, AppContext::get()));
    }
}
