<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Lumio\\Whmcs\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === '') {
        return;
    }

    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
