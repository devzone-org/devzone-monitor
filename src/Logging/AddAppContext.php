<?php

namespace DevZone\LogMonitor\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as MonologLogger;

/**
 * Laravel logging "tap".
 *
 *   'daily' => [
 *       'driver' => 'daily',
 *       'path'   => storage_path('logs/laravel.log'),
 *       'days'   => 14,
 *       'tap'    => [\DevZone\LogMonitor\Logging\AddAppContext::class],
 *   ],
 *
 * For every handler on the channel it switches the formatter to JSON (one
 * object per line, stack traces included) and pushes a processor that adds the
 * application context to "extra". The processor implementation is chosen at
 * runtime to match the installed Monolog major version.
 */
final class AddAppContext
{
    /**
     * @param \Illuminate\Log\Logger|MonologLogger $logger
     */
    public function __invoke($logger): void
    {
        $monolog = $logger;
        if (!$monolog instanceof MonologLogger && is_object($logger) && method_exists($logger, 'getLogger')) {
            $monolog = $logger->getLogger();
        }
        if (!$monolog instanceof MonologLogger) {
            return;
        }

        $processor = self::makeProcessor();

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(self::makeFormatter());
            }
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
            }
        }
    }

    public static function makeFormatter(): JsonFormatter
    {
        $formatter = new JsonFormatter();
        $formatter->includeStacktraces(true);

        return $formatter;
    }

    /**
     * @return callable
     */
    public static function makeProcessor()
    {
        $class = self::processorClass();

        return new $class();
    }

    /**
     * @return class-string
     */
    public static function processorClass(): string
    {
        return class_exists(\Monolog\LogRecord::class)
            ? Monolog3Processor::class
            : Monolog2Processor::class;
    }
}
