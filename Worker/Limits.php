<?php

declare(strict_types=1);

namespace Magenx\AppServer\Worker;

/**
 * When a worker should exit so FrankenPHP replaces it with a fresh one.
 *
 * Recycling is the safety net for what _resetState() cannot fix: leaks,
 * and state that is deliberately process-wide (system config, DI config,
 * already-loaded classes). maxAge bounds how long an admin config change
 * can go unseen by a worker that keeps the old values in memory.
 */
class Limits
{
    public function __construct(
        public readonly int $maxRequests = 500,
        public readonly int $maxMemoryBytes = 512 * 1024 * 1024,
        public readonly int $maxAgeSeconds = 300,
    ) {
    }

    /**
     * Read MAGENX_WORKER_MAX_REQUESTS / _MAX_MEMORY_MB / _MAX_AGE (0 = no limit).
     *
     * Call before the first request: until then $_SERVER holds the worker's
     * own environment, including Caddyfile `env` directives.
     */
    public static function fromEnvironment(): self
    {
        $int = static fn (string $name, int $default): int =>
            is_numeric($value = $_SERVER[$name] ?? getenv($name)) ? max(0, (int) $value) : $default;

        return new self(
            $int('MAGENX_WORKER_MAX_REQUESTS', 500),
            $int('MAGENX_WORKER_MAX_MEMORY_MB', 512) * 1024 * 1024,
            $int('MAGENX_WORKER_MAX_AGE', 300),
        );
    }
}
