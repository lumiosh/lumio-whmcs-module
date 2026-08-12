<?php

declare(strict_types=1);

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Autoload.php';

use Lumio\Whmcs\CronRunner;
use Lumio\Whmcs\Logging\WhmcsLogger;
use Lumio\Whmcs\Persistence\StateRepository;
use Lumio\Whmcs\Support\Sanitizer;
use Lumio\Whmcs\WhmcsRuntime;
use Lumio\Whmcs\Version;

add_hook('AdminAreaFooterOutput', 1, static function (array $vars = []): string {
    $pageNames = [];
    foreach ([
        $vars['filename'] ?? '',
        $_SERVER['SCRIPT_NAME'] ?? '',
        $_SERVER['PHP_SELF'] ?? '',
        $_SERVER['REQUEST_URI'] ?? '',
    ] as $candidate) {
        if (! is_scalar($candidate) || trim((string) $candidate) === '') {
            continue;
        }
        $path = parse_url((string) $candidate, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            continue;
        }
        $pageName = strtolower(pathinfo(basename($path), PATHINFO_FILENAME));
        if ($pageName !== '') {
            $pageNames[$pageName] = true;
        }
    }

    $asset = isset($pageNames['configproducts'])
        ? 'admin-product-mapper.js'
        : (isset($pageNames['configservers']) ? 'admin-server-config.js' : null);
    if ($asset === null) {
        return '';
    }

    $path = __DIR__ . '/assets/' . $asset;
    $script = is_file($path) ? file_get_contents($path) : false;
    if (! is_string($script) || $script === '' || stripos($script, '</script') !== false) {
        return '';
    }
    return sprintf(
        '<script data-lumio-admin-asset="%s" data-lumio-module-version="%s">%s</script>',
        htmlspecialchars($asset, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars(Version::NUMBER, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        $script,
    );
});

add_hook('AfterModuleCreate', 1, static function (array $vars = []): void {
    $params = is_array($vars['params'] ?? null) ? $vars['params'] : [];
    if (strtolower(trim((string) ($params['moduletype'] ?? ''))) !== 'lumio') {
        return;
    }
    $serviceId = (int) ($params['serviceid'] ?? 0);
    if ($serviceId < 1) {
        return;
    }

    try {
        $states = new StateRepository();
        $states->ensureSchema();
        $state = $states->get($serviceId);
        if (($state['activation_reported_at'] ?? null) === null
            || ($state['activation_acknowledged_at'] ?? null) !== null) {
            return;
        }
        $states->save($serviceId, [
            'activation_acknowledged_at' => gmdate('Y-m-d H:i:s'),
            'poll_attempts' => 0,
            'next_poll_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
    } catch (\Throwable $exception) {
        (new WhmcsLogger())->activity(
            'AfterModuleCreate failed to acknowledge the Lumio activation state: ' . Sanitizer::text($exception->getMessage()),
        );
    }
});

add_hook('AfterCronJob', 1, static function (array $vars = []): void {
    unset($vars);
    (new CronRunner(
        new WhmcsRuntime(),
        new StateRepository(),
        new WhmcsLogger(),
    ))->run();
});
