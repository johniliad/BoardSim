<?php

declare(strict_types=1);

namespace BoardSim\Infrastructure;

use RuntimeException;

/**
 * Loads process environment variables from a file, for values that cannot live in
 * the source tree (database credentials) but must be visible to both the web app
 * and the cron-run retention job.
 *
 * The file is plain KEY=VALUE, one per line, `#` for comments. No quoting rules to
 * get wrong, which matters because a MySQL DSN contains `;` that a PHP INI parser
 * would misread.
 *
 * Precedence: a variable already present in the process environment wins. The file
 * fills only the gaps, so an Apache `SetEnv` or a systemd `Environment=` override
 * is respected.
 */
final class EnvFile
{
    /**
     * Read `config.local.php` once and load the credentials it points at.
     *
     * Returns the parsed array (or `[]` if absent) so the caller never has to
     * `include` the file a second time — which previously meant `config.local.php`
     * ran twice per request, a latent footgun if it ever grew a side effect.
     *
     * Priority: BOARDSIM_ENV_FILE, if the process sets it; otherwise the
     * `secrets_file` pointer in `config.local.php`. Any other keys the file carries
     * (a legacy deployment storing `dsn`/`user`/`pass` directly) are returned for
     * the caller to use as a fallback, so one read serves both mechanisms.
     *
     * @return array<string, mixed>
     */
    public static function bootstrap(string $projectRoot): array
    {
        $override = getenv('BOARDSIM_ENV_FILE');

        if ($override !== false && $override !== '') {
            self::load($override);

            return [];
        }

        $configFile = rtrim($projectRoot, '/\\') . '/config.local.php';

        if (!is_file($configFile)) {
            return [];
        }

        $local = include $configFile;
        $local = is_array($local) ? $local : [];

        if (($local['secrets_file'] ?? '') !== '') {
            self::load((string) $local['secrets_file']);
        }

        return $local;
    }

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("Credential file is missing: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException("Cannot read credential file: {$path}");
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $position = strpos($line, '=');

            if ($position === false) {
                continue;
            }

            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
        }
    }
}