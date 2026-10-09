<?php

declare(strict_types=1);

namespace Magenx\AppServer\Worker;

/**
 * When a worker should exit so FrankenPHP replaces it with a fresh one.
 *
 * Recycling is the safety net for what _resetState() cannot fix: leaks,
 * and state that is deliberately process-wide (system config, DI config,
 * already-loaded classes). Admin config changes are detected separately
 * (GraphQlWorker::configChanged()); maxAge bounds everything else a worker
 * keeps in memory.
 *
 * fromEnvironment() spreads each worker's request and age limits by up to
 * +-20%: workers boot together, and identical limits would make them all
 * recycle, and reboot, at the same moment.
 */
class Limits
{
    public function __construct(
        public readonly int $maxRequests = 500,
        public readonly int $maxMemoryBytes = 512 * 1024 * 1024,
        public readonly int $maxAgeSeconds = 1800,
    ) {
    }

    /**
     * Read MAGENX_WORKER_MAX_REQUESTS / _MAX_MEMORY_MB / _MAX_AGE (0 = no limit), with jitter.
     *
     * Call before the first request: until then $_SERVER holds the worker's
     * own environment, including Caddyfile `env` directives.
     */
    public static function fromEnvironment(): self
    {
        $int = static fn (string $name, int $default): int =>
            is_numeric($value = $_SERVER[$name] ?? getenv($name)) ? max(0, (int) $value) : $default;

        $jitter = static fn (int $value): int => intdiv($value * random_int(80, 120), 100);

        return new self(
            $jitter($int('MAGENX_WORKER_MAX_REQUESTS', 500)),
            $int('MAGENX_WORKER_MAX_MEMORY_MB', 512) * 1024 * 1024,
            $jitter($int('MAGENX_WORKER_MAX_AGE', 1800)),
        );
    }
}
