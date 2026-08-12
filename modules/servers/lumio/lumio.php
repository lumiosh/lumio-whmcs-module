<?php

declare(strict_types=1);

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Autoload.php';

use Lumio\Whmcs\AdminCatalogBootstrap;
use Lumio\Whmcs\ConnectionTester;
use Lumio\Whmcs\Logging\WhmcsLogger;
use Lumio\Whmcs\ModuleFactory;
use Lumio\Whmcs\ModuleInspector;
use Lumio\Whmcs\ModuleWorkflow;
use Lumio\Whmcs\Support\Sanitizer;
use Lumio\Whmcs\Version;

/** @return array<string, mixed> */
function lumio_MetaData(): array
{
    return [
        'DisplayName' => 'Lumio',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => '443',
    ];
}

/** @return array<string, array<string, mixed>> */
function lumio_ConfigOptions(): array
{
    $marker = static fn (int $index): string => sprintf(
        '<span class="lumio-option-marker" data-lumio-option="%d" hidden aria-hidden="true"></span>',
        $index,
    );
    $catalogBootstrap = lumio_admin_catalog_bootstrap_markup();

    return [
        'Lumio Product SKU' => [
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Automatically populated by Lumio Product Mapping.'
                . $marker(1)
                . $catalogBootstrap,
        ],
        'Product Configuration' => [
            'Type' => 'textarea',
            'Rows' => '5',
            'Cols' => '60',
            'Description' => 'Automatically populated by Lumio Product Mapping.' . $marker(2),
        ],
        'Lumio Add-on IDs' => [
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Automatically populated by Lumio Product Mapping.' . $marker(3),
        ],
        'Monthly Cost Cap (Cents)' => ['Type' => 'text', 'Size' => '16', 'Description' => 'Automatically calculated from the selected monthly product price.' . $marker(4)],
        'Quarterly Cost Cap (Cents)' => ['Type' => 'text', 'Size' => '16', 'Description' => 'Automatically calculated from the selected quarterly product price.' . $marker(5)],
        'Semi-Annual Cost Cap (Cents)' => ['Type' => 'text', 'Size' => '16', 'Description' => 'Automatically calculated from the selected semi-annual product price.' . $marker(6)],
        'Annual Cost Cap (Cents)' => ['Type' => 'text', 'Size' => '16', 'Description' => 'Automatically calculated from the selected annual product price.' . $marker(7)],
        'Biennial Cost Cap (Cents)' => ['Type' => 'text', 'Size' => '16', 'Description' => 'Automatically calculated from the selected biennial product price.' . $marker(8)],
        'Triennial Cost Cap (Cents)' => ['Type' => 'text', 'Size' => '16', 'Description' => 'Automatically calculated from the selected triennial product price.' . $marker(9)],
        'Compatibility Setting 10' => [
            'Type' => 'yesno',
            'Description' => 'Unused compatibility field.' . $marker(10),
        ],
        'Compatibility Setting 11' => [
            'Type' => 'text',
            'Size' => '16',
            'Default' => '0',
            'Description' => 'Unused compatibility field.' . $marker(11),
        ],
        'Immediate Termination Policy' => [
            'Type' => 'yesno',
            'Description' => 'Required. Terminating a service immediately releases its inventory and forfeits the remaining paid period. Lumio does not issue an automatic refund; the reseller is responsible for any customer refund.' . $marker(12),
        ],
    ];
}

function lumio_admin_catalog_bootstrap_markup(): string
{
    static $markup = null;
    if (is_string($markup)) {
        return $markup;
    }
    $markup = '';
    if (! defined('ADMINAREA')
        || strtolower(trim((string) ($_POST['action'] ?? ''))) !== 'module-settings'
        || strtolower(trim((string) ($_POST['module'] ?? ''))) !== 'lumio') {
        return $markup;
    }

    $rawGroupId = $_POST['servergroup'] ?? '';
    $serverGroupId = is_int($rawGroupId)
        ? $rawGroupId
        : (is_string($rawGroupId) && preg_match('/^[1-9][0-9]{0,9}$/D', $rawGroupId) === 1
            ? (int) $rawGroupId
            : 0);
    try {
        $loader = new AdminCatalogBootstrap();
        $markup = $loader->markup($loader->load($serverGroupId));
    } catch (\Throwable) {
        $markup = '<script type="application/json" class="lumio-catalog-bootstrap">'
            . '{"state":"error","message":"The Lumio product catalog is temporarily unavailable. Please try again.","request_id":null,"products":[]}'
            . '</script>';
    }
    return $markup;
}

/** @param array<string, mixed> $params @return array{success: bool, error: string} */
function lumio_TestConnection(array $params): array
{
    return (new ConnectionTester())->test($params);
}

/** @param array<string, mixed> $params */
function lumio_CreateAccount(array $params): string
{
    return lumio_run_workflow($params, static fn (ModuleWorkflow $workflow): string => $workflow->createAccount());
}

/** @param array<string, mixed> $params */
function lumio_SuspendAccount(array $params): string
{
    return lumio_run_workflow($params, static fn (ModuleWorkflow $workflow): string => $workflow->lifecycle('suspend'));
}

/** @param array<string, mixed> $params */
function lumio_UnsuspendAccount(array $params): string
{
    return lumio_run_workflow($params, static fn (ModuleWorkflow $workflow): string => $workflow->lifecycle('resume'));
}

/** @param array<string, mixed> $params */
function lumio_TerminateAccount(array $params): string
{
    return lumio_run_workflow($params, static fn (ModuleWorkflow $workflow): string => $workflow->lifecycle('terminate'));
}

/** @param array<string, mixed> $params */
function lumio_Renew(array $params): string
{
    return lumio_run_workflow($params, static fn (ModuleWorkflow $workflow): string => $workflow->renew());
}

/**
 * Reconciles a renewal that already has a persisted invoice, payload, external
 * reference, and idempotency key. This function is invoked only by the module
 * cron through WHMCS ModuleCustom and is intentionally not exposed as a button.
 *
 * @param array<string, mixed> $params
 */
function lumio_ReconcileRenewal(array $params): string
{
    return lumio_run_workflow($params, static fn (ModuleWorkflow $workflow): string => $workflow->renew(true));
}

/** @param array<string, mixed> $params @return array<string, mixed> */
function lumio_ClientArea(array $params): array
{
    $state = (new ModuleInspector())->state((int) ($params['serviceid'] ?? 0));
    $connection = lumio_client_connection_variables($params, $state);
    return [
        'tabOverviewModuleOutputTemplate' => 'templates/clientarea.tpl',
        'templateVariables' => [
            'lumioModuleVersion' => Version::NUMBER,
            'lumioLang' => lumio_client_area_labels($params),
            'lumioDeliveryState' => $state['delivery_state'],
            'lumioPublicError' => $state['last_error'],
        ] + $connection,
    ];
}

/** @param array<string, mixed> $params @return array<string, string> */
function lumio_client_area_labels(array $params = []): array
{
    $english = lumio_load_client_area_labels('english');
    if (lumio_client_language($params) !== 'chinese') {
        return $english;
    }

    return array_replace($english, lumio_load_client_area_labels('chinese'));
}

/** @return array<string, string> */
function lumio_load_client_area_labels(string $language): array
{
    static $cache = [];
    if (isset($cache[$language])) {
        return $cache[$language];
    }

    $file = __DIR__ . '/lang/' . $language . '.php';
    if (! is_file($file)) {
        return $cache[$language] = [];
    }

    $labels = require $file;
    return $cache[$language] = is_array($labels) ? $labels : [];
}

/** @param array<string, mixed> $params */
function lumio_client_language(array $params = []): string
{
    $clientDetails = is_array($params['clientsdetails'] ?? null) ? $params['clientsdetails'] : [];
    $candidates = [
        $_SESSION['Language'] ?? null,
        $params['language'] ?? null,
        $clientDetails['language'] ?? null,
        $GLOBALS['CONFIG']['Language'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (! is_scalar($candidate)) {
            continue;
        }

        $language = strtolower(trim((string) $candidate));
        $language = preg_replace('/\.php$/', '', $language) ?? $language;
        $language = str_replace([' ', '-'], '_', $language);
        if ($language === '') {
            continue;
        }

        return $language === 'chinese'
            || str_starts_with($language, 'zh')
            || str_contains($language, 'chinese')
            ? 'chinese'
            : 'english';
    }

    return 'english';
}

/**
 * @param array<string, mixed> $params
 * @param array<string, mixed> $state
 * @return array<string, bool|string>
 */
function lumio_client_connection_variables(array $params, array $state): array
{
    $empty = [
        'lumioDedicatedIp' => '',
        'lumioHostname' => '',
        'lumioUsername' => '',
        'lumioPassword' => '',
        'lumioConnectionNotes' => '',
        'lumioSshPort' => '',
        'lumioVncPort' => '',
        'lumioHasConnectionInfo' => false,
    ];
    $deliveryState = (string) ($state['delivery_state'] ?? '');
    $credentialsDelivered = ($state['credentials_delivered'] ?? '') === '1' || $deliveryState === 'ready';
    if (! $credentialsDelivered || ! in_array($deliveryState, ['ready', 'active'], true)) {
        return $empty;
    }
    $property = static function (string $name) use ($params): ?string {
        try {
            return (new \Lumio\Whmcs\WhmcsServiceProperties($params))->get($name);
        } catch (\Throwable) {
            return null;
        }
    };
    $dedicatedIp = trim((string) ($params['dedicatedip'] ?? ''));
    if (filter_var($dedicatedIp, FILTER_VALIDATE_IP) === false) {
        $dedicatedIp = trim((string) ($property('Dedicated IP') ?? ''));
    }
    if (filter_var($dedicatedIp, FILTER_VALIDATE_IP) === false) {
        $dedicatedIp = '';
    }
    $hostname = Sanitizer::text((string) ($property('Lumio Hostname') ?? ''), 253);
    $username = trim((string) ($params['username'] ?? ''));
    if ($username === '') {
        $username = trim((string) ($property('Username') ?? ''));
    }
    $password = (string) ($params['password'] ?? '');
    if ($password === '') {
        $password = (string) ($property('Password') ?? '');
    }
    $connectionNotes = Sanitizer::text((string) ($property('Lumio Connection Notes') ?? ''), 240);
    $ports = lumio_client_connection_ports($connectionNotes);
    if (($ports['ssh'] !== '' || $ports['vnc'] !== '') && $ports['remaining'] === '') {
        $connectionNotes = '';
    }
    return [
        'lumioDedicatedIp' => $dedicatedIp,
        'lumioHostname' => $hostname,
        'lumioUsername' => $username,
        'lumioPassword' => $password,
        'lumioConnectionNotes' => $connectionNotes,
        'lumioSshPort' => $ports['ssh'],
        'lumioVncPort' => $ports['vnc'],
        'lumioHasConnectionInfo' => $dedicatedIp !== ''
            || $hostname !== ''
            || $username !== ''
            || $password !== ''
            || $connectionNotes !== ''
            || $ports['ssh'] !== ''
            || $ports['vnc'] !== '',
    ];
}

/** @return array{ssh: string, vnc: string, remaining: string} */
function lumio_client_connection_ports(string $notes): array
{
    $patterns = [
        'ssh' => '/\bSSH\s*(?:port|端口)?\s*[:：]?\s*([0-9]{1,5})/iu',
        'vnc' => '/\bVNC\s*(?:port|端口)?\s*[:：]?\s*([0-9]{1,5})/iu',
    ];
    $result = ['ssh' => '', 'vnc' => '', 'remaining' => $notes];
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $notes, $match) !== 1) {
            continue;
        }
        $port = (int) ($match[1] ?? 0);
        if ($port >= 1 && $port <= 65535) {
            $result[$name] = (string) $port;
            $result['remaining'] = (string) preg_replace($pattern, '', $result['remaining']);
        }
    }
    $result['remaining'] = trim($result['remaining'], " \t\n\r\0\x0B;；,，|/");
    return $result;
}

/** @param array<string, mixed> $params @return array<string, string> */
function lumio_AdminServicesTabFields(array $params): array
{
    $state = (new ModuleInspector())->state((int) ($params['serviceid'] ?? 0));
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return [
        'Lumio Service Number' => $escape($state['service_number']),
        'Lumio Service ID' => $escape($state['service_id']),
        'Lumio Delivery State' => $escape($state['delivery_state']),
        'Lumio Pending Action' => $escape($state['pending_action']),
        'Lumio Operation ID' => $escape($state['operation_id']),
        'Lumio External Reference' => $escape($state['external_reference']),
        'Lumio Public Error Code' => $escape($state['last_error']),
        'Lumio Request-Id' => $escape($state['last_request_id']),
    ];
}

/**
 * @param array<string, mixed> $params
 * @param callable(ModuleWorkflow): string $callback
 */
function lumio_run_workflow(array $params, callable $callback): string
{
    try {
        return $callback(ModuleFactory::workflow($params));
    } catch (\Throwable $exception) {
        $message = Sanitizer::text($exception->getMessage());
        (new WhmcsLogger())->activity('Module initialization failed: ' . $message);
        return 'Lumio module initialization failed: ' . $message;
    }
}
