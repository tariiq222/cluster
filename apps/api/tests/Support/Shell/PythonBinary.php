<?php

declare(strict_types=1);

namespace Tests\Support\Shell;

/**
 * Centralizes resolution of the Python interpreter used by inventory, docs,
 * and production-bundle scripts. The Makefile publishes a single PYTHON_BINARY
 * variable so CI and local runs agree on which interpreter is used; tests
 * should mirror that resolution instead of hard-coding `python3`.
 *
 * Order of resolution:
 *   1. Explicit PYTHON_BINARY environment variable (matches Makefile override).
 *   2. Makefile-resolved value if the binary advertises one via
 *      `make python-bin` (used by the test runner when it shells out).
 *   3. `command -v python3` then `command -v python`.
 *
 * Tests MUST NOT shell out to a different interpreter than this helper
 * returns, otherwise PR-CI Python drift can re-appear.
 */
final class PythonBinary
{
    private static ?string $cached = null;

    public static function resolve(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $candidates = [];
        $explicit = getenv('PYTHON_BINARY');
        if (is_string($explicit) && $explicit !== '') {
            $candidates[] = $explicit;
        }

        $resolved = self::probeViaMake();
        if ($resolved !== null) {
            $candidates[] = $resolved;
        }

        $candidates[] = 'python3';
        $candidates[] = 'python';

        foreach ($candidates as $candidate) {
            $path = trim((string) $candidate);
            if ($path === '') {
                continue;
            }
            $check = @proc_open(
                [$path, '-c', 'import sys; sys.stdout.write("%d.%d" % sys.version_info[:2])'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (! is_resource($check)) {
                continue;
            }
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($check);
            if ($exit === 0 && is_string($stdout) && preg_match('/^\d+\.\d+$/', trim($stdout)) === 1) {
                self::$cached = $path;

                return self::$cached;
            }
        }

        throw new PythonBinaryUnavailable(
            'Unable to locate a working Python 3 interpreter. Set PYTHON_BINARY or update PATH.',
        );
    }

    public static function version(): string
    {
        $binary = self::resolve();
        $output = [];
        $exit = 0;
        exec(escapeshellarg($binary).' -c "import sys; print(\"%d.%d\" % sys.version_info[:2])"', $output, $exit);

        if ($exit !== 0 || $output === []) {
            return '0.0';
        }

        return trim((string) $output[0]);
    }

    private static function probeViaMake(): ?string
    {
        // Only call Laravel helpers when the application container is bound.
        // Standalone invocations (e.g. require_once in CLI smoke tests) must
        // not crash on a missing base_path().
        if (! function_exists('base_path')) {
            return null;
        }
        try {
            $root = realpath(base_path('../..')) ?: base_path('../..');
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($root) || ! is_dir($root)) {
            return null;
        }

        $make = proc_open(
            ['make', '-sC', $root, 'python-bin'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (! is_resource($make)) {
            return null;
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($make);
        if ($exit !== 0 || ! is_string($stdout)) {
            return null;
        }

        $first = trim(explode(' ', $stdout, 2)[0] ?? '');

        return $first !== '' ? $first : null;
    }
}
