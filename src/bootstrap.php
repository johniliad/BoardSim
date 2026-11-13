<?php

declare(strict_types=1);

/**
 * Zero-dependency PSR-4 autoloader for the BoardSim namespace.
 *
 * The production host offers PHP + MySQL only, so the application must run
 * without a Composer install step. composer.json is provided for CI and local
 * tooling, but nothing in src/ or bin/ requires vendor/autoload.php.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'BoardSim\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
