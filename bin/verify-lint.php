<?php

declare(strict_types=1);

/**
 * Syntax-check every PHP file, so a parse error cannot hide in a code path that
 * the assertion suites never instantiate (which is exactly how a broken
 * MysqlRunStore stayed green: tests use in-memory stores, and the adapter is only
 * loaded on a MySQL host).
 *
 * Usage: php bin/verify-lint.php
 */

$root = dirname(__DIR__);
$directories = ['src', 'bin', 'tools', 'public', 'deploy'];
$failures = 0;

foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $output = (string) shell_exec(
            escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1'
        );

        if (str_contains($output, 'No syntax errors')) {
            continue;
        }

        $failures++;
        printf("%s\n", trim($output));
    }
}

printf("lint: %s\n", $failures === 0 ? 'PASS — every PHP file parses' : "{$failures} file(s) to fix");
exit($failures === 0 ? 0 : 1);