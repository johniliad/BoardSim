<?php

declare(strict_types=1);

namespace BoardSim\Infrastructure;

use PDO;
use PDOException;

/**
 * Connect to MySQL without ever throwing.
 *
 * The front controller must survive a missing database: `/v1/health` is the
 * deployment's status page on a host with no shell, so it has to answer even
 * when the database is not there yet. This factory returns the failure as a
 * short code instead of an exception, and logs the driver message for the host's
 * error log — where it belongs, not in a public response.
 *
 * Failure codes, deliberately few and non-sensitive:
 *
 *   missing_config    no DSN was resolved at all (config.local.php absent/empty)
 *   unknown_database  connected to the server, database does not exist
 *   access_denied     credentials rejected
 *   unreachable       host/socket refused the connection
 *   unavailable       anything else
 */
final class DatabaseHandle
{
    /**
     * @param array{dsn: string, user: string, pass: string} $config
     *
     * @return array{0: PDO|null, 1: string|null} the handle, or a failure code
     */
    public static function connect(array $config): array
    {
        if (trim((string) $config['dsn']) === '') {
            return [null, 'missing_config'];
        }

        try {
            $pdo = new PDO(
                (string) $config['dsn'],
                (string) $config['user'],
                (string) $config['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // A DSN can be accepted lazily; force the handshake here so a wrong
            // database name fails on health, not on the first write.
            $pdo->query('SELECT 1');

            return [$pdo, null];
        } catch (PDOException $e) {
            error_log('BoardSim database: ' . $e->getMessage());

            return [null, self::classify($e)];
        }
    }

    private static function classify(PDOException $e): string
    {
        $driverCode = $e->errorInfo[1] ?? null;
        $message = $e->getMessage();

        if ($driverCode === 1049 || str_contains($message, 'Unknown database')) {
            return 'unknown_database';
        }

        if ($driverCode === 1045 || str_contains($message, 'Access denied')) {
            return 'access_denied';
        }

        if (str_contains($message, 'Connection refused')
            || str_contains($message, 'No such file or directory')
            || str_contains($message, "Can't connect")) {
            return 'unreachable';
        }

        return 'unavailable';
    }
}
