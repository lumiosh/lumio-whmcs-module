<?php

declare(strict_types=1);

namespace LumioWhmcsTests\Unit\Whmcs;

use Lumio\Whmcs\ConnectionTester;
use Lumio\Whmcs\Version;
use PHPUnit\Framework\TestCase;

if (! defined('WHMCS')) {
    define('WHMCS', true);
}
require_once dirname(__DIR__, 3) . '/modules/servers/lumio/lumio.php';

final class ModuleEntrypointContractTest extends TestCase
{
    public function testExposesExpectedWhmcsProvisioningFunctions(): void
    {
        foreach ([
            'lumio_MetaData',
            'lumio_ConfigOptions',
            'lumio_TestConnection',
            'lumio_CreateAccount',
            'lumio_SuspendAccount',
            'lumio_UnsuspendAccount',
            'lumio_TerminateAccount',
            'lumio_Renew',
            'lumio_ReconcileRenewal',
            'lumio_ClientArea',
            'lumio_AdminServicesTabFields',
        ] as $function) {
            self::assertTrue(function_exists($function), $function . ' should exist');
        }
    }

    public function testModuleRequiresServerAndDefinesTwelveStableProductOptions(): void
    {
        $metadata = \lumio_MetaData();
        self::assertSame('1.1', $metadata['APIVersion']);
        self::assertTrue($metadata['RequiresServer']);
        self::assertSame('443', $metadata['DefaultSSLPort']);

        $options = \lumio_ConfigOptions();
        self::assertCount(12, $options);
        self::assertSame('text', $options['Lumio Product SKU']['Type']);
        self::assertStringContainsString('lumio-option-marker', $options['Lumio Product SKU']['Description']);
        self::assertStringContainsString('data-lumio-option="9"', $options['Triennial Cost Cap (Cents)']['Description']);
        self::assertSame('yesno', $options['Compatibility Setting 10']['Type']);
        self::assertSame('yesno', $options['Immediate Termination Policy']['Type']);
        self::assertArrayHasKey('Compatibility Setting 11', $options);
        self::assertStringContainsString('data-lumio-option="12"', $options['Immediate Termination Policy']['Description']);
        self::assertSame(0, preg_match('/[\p{Han}]/u', json_encode($options, JSON_THROW_ON_ERROR)));
    }

    public function testClientAreaAddsSafeModuleOutputWithoutReplacingProductOverview(): void
    {
        $result = \lumio_ClientArea(['serviceid' => 0]);
        self::assertSame('templates/clientarea.tpl', $result['tabOverviewModuleOutputTemplate']);
        self::assertArrayHasKey('templateVariables', $result);
        self::assertArrayNotHasKey('tabOverviewReplacementTemplate', $result);
    }

    public function testClientAreaExposesTheOwnedServiceConnectionInformationWithoutAnApiKey(): void
    {
        $model = new ModuleEntrypointTestServiceModel();
        $model->serviceProperties->values = [
            'Dedicated IP' => '192.0.2.88',
            'Lumio Hostname' => 'device.example.net',
            'Lumio Connection Notes' => 'SSH 端口 22；VNC 端口 5900',
        ];

        $variables = \lumio_client_connection_variables([
            'model' => $model,
            'username' => 'macuser',
            'password' => 'service-password',
        ], ['delivery_state' => 'ready']);

        self::assertSame('192.0.2.88', $variables['lumioDedicatedIp']);
        self::assertSame('device.example.net', $variables['lumioHostname']);
        self::assertSame('macuser', $variables['lumioUsername']);
        self::assertSame('service-password', $variables['lumioPassword']);
        self::assertSame('', $variables['lumioConnectionNotes']);
        self::assertSame('22', $variables['lumioSshPort']);
        self::assertSame('5900', $variables['lumioVncPort']);
        self::assertTrue($variables['lumioHasConnectionInfo']);
        self::assertStringNotContainsString('lumio_live_', json_encode($variables, JSON_THROW_ON_ERROR));
    }

    public function testClientAreaDoesNotExposeGeneratedCredentialsBeforeDeliveryIsReady(): void
    {
        $variables = \lumio_client_connection_variables([
            'username' => 'generated-user',
            'password' => 'generated-password',
        ], ['delivery_state' => 'provisioning']);

        self::assertFalse($variables['lumioHasConnectionInfo']);
        self::assertSame('', $variables['lumioUsername']);
        self::assertSame('', $variables['lumioPassword']);
    }

    public function testClientAreaRestoresDeliveredConnectionInformationAfterResume(): void
    {
        $variables = \lumio_client_connection_variables([
            'username' => 'delivered-user',
            'password' => 'delivered-password',
            'dedicatedip' => '192.0.2.90',
        ], [
            'delivery_state' => 'active',
            'credentials_delivered' => '1',
        ]);

        self::assertTrue($variables['lumioHasConnectionInfo']);
        self::assertSame('192.0.2.90', $variables['lumioDedicatedIp']);
        self::assertSame('delivered-user', $variables['lumioUsername']);
        self::assertSame('delivered-password', $variables['lumioPassword']);
    }

    public function testClientAreaUsesSelectedChineseAndFallsBackToEnglishForEveryOtherLanguage(): void
    {
        $hadSession = isset($_SESSION) && is_array($_SESSION);
        $session = $hadSession ? $_SESSION : null;
        if (! $hadSession) {
            $_SESSION = [];
        }

        try {
            unset($_SESSION['Language']);
            self::assertSame('服务器信息', \lumio_client_area_labels([
                'clientsdetails' => ['language' => 'chinese'],
            ])['panelTitle']);
            self::assertSame('Server Information', \lumio_client_area_labels([
                'clientsdetails' => ['language' => 'german'],
            ])['panelTitle']);

            $_SESSION['Language'] = 'chinese';
            self::assertSame('服务器信息', \lumio_client_area_labels([
                'clientsdetails' => ['language' => 'english'],
            ])['panelTitle']);

            $_SESSION['Language'] = 'french';
            self::assertSame('Server Information', \lumio_client_area_labels([
                'clientsdetails' => ['language' => 'chinese'],
            ])['panelTitle']);
        } finally {
            if ($hadSession) {
                $_SESSION = $session;
            } else {
                unset($_SESSION);
            }
        }
    }

    public function testClientAreaTemplateReusesTheConnectionCardWithoutInternalTrackingRows(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/modules/servers/lumio/templates/clientarea.tpl',
        );

        self::assertStringContainsString('data-lumio-panel', $template);
        self::assertStringContainsString('$lumioLang.panelTitle', $template);
        self::assertStringContainsString('lumio-connection-list', $template);
        self::assertStringContainsString('data-password-mask', $template);
        self::assertStringContainsString('data-password-toggle', $template);
        self::assertStringContainsString('data-password-copy', $template);
        self::assertStringContainsString('$lumioLang.sshPort', $template);
        self::assertStringContainsString('$lumioLang.vncPort', $template);
        self::assertStringNotContainsString('服务编号', $template);
        self::assertStringNotContainsString('交付状态', $template);
        self::assertStringNotContainsString('查询编号', $template);
        self::assertStringNotContainsString('lumioServiceNumber', $template);
        self::assertStringNotContainsString('lumioDeliveryStateLabel', $template);
        self::assertStringNotContainsString('lumioRequestId', $template);
        self::assertStringNotContainsString('type="password"', $template);
        self::assertStringNotContainsString('Lumio Server', $template);
        self::assertStringNotContainsString('Lumio 服务器', $template);
    }

    public function testConnectionRequiresTheSevenScopesExposedByLumioApiV1(): void
    {
        $constant = (new \ReflectionClass(ConnectionTester::class))->getReflectionConstant('REQUIRED_SCOPES');
        self::assertNotFalse($constant);
        self::assertSame([
            'catalog:read',
            'wallet:read',
            'purchase:write',
            'service:read',
            'credentials:read',
            'renewal:write',
            'lifecycle:write',
        ], $constant->getValue());
    }

    public function testPublicModuleVersionMatchesItsDocumentation(): void
    {
        $root = dirname(__DIR__, 3) . '/modules/servers/lumio';
        $readme = file($root . '/README.md', \FILE_IGNORE_NEW_LINES);
        $changelog = file_get_contents($root . '/CHANGELOG.md');

        self::assertIsArray($readme);
        self::assertSame('# Lumio WHMCS Provisioning Module ' . Version::NUMBER, $readme[0]);
        self::assertIsString($changelog);
        self::assertStringContainsString('## ' . Version::NUMBER . ' -', $changelog);
    }

    public function testAdminPagesUseEnglishSafeAssetsAndAutomaticInstallationIds(): void
    {
        $root = dirname(__DIR__, 3) . '/modules/servers/lumio';
        $hooks = (string) file_get_contents($root . '/hooks.php');
        $mapper = (string) file_get_contents($root . '/assets/admin-product-mapper.js');
        $server = (string) file_get_contents($root . '/assets/admin-server-config.js');

        self::assertStringContainsString("add_hook('AdminAreaFooterOutput'", $hooks);
        self::assertStringContainsString("\$_SERVER['REQUEST_URI']", $hooks);
        self::assertStringContainsString('parse_url((string) $candidate, PHP_URL_PATH)', $hooks);
        self::assertStringContainsString("isset(\$pageNames['configproducts'])", $hooks);
        self::assertStringContainsString("isset(\$pageNames['configservers'])", $hooks);
        self::assertStringContainsString('lumio-catalog-bootstrap', $mapper);
        self::assertStringContainsString('data-lumio-option', $mapper);
        self::assertStringContainsString('Lumio Product Mapping', $mapper);
        self::assertStringContainsString("passwordLabel.textContent = 'API Key'", $server);
        self::assertStringContainsString("return 'whmcs-' + value", $server);
        self::assertStringContainsString('window.crypto.getRandomValues(bytes)', $server);
        self::assertStringContainsString('#inputServerModule', $server);
        self::assertStringContainsString('#inputServerUsername', $server);
        self::assertStringContainsString('#inputServerPassword', $server);
        self::assertStringContainsString('#addType', $server);
        self::assertStringContainsString('#newUsername', $server);
        self::assertStringContainsString('#newPassword', $server);
        self::assertStringContainsString('#newHash', $server);
        self::assertStringContainsString("var selectors = ['#preAddForm', '#frmServerConfig']", $server);
        self::assertStringContainsString('combinedControls(roots, [', $server);
        self::assertStringContainsString("], ['Password', 'API Key'], 'input')", $server);
        self::assertStringContainsString('hideControls(usernames)', $server);
        self::assertStringContainsString('hideControls(accessHashes)', $server);
        self::assertStringContainsString('clearLumioInstallationId(usernames)', $server);
        self::assertStringContainsString('lumioInstallationId = existingInstallationId(usernames)', $server);
        self::assertStringNotContainsString('document.querySelectorAll', $server);
        $moduleSelectorsStart = strpos($server, 'var moduleSelects = combinedControls');
        $usernameSelectorsStart = strpos($server, 'var usernames = combinedControls');
        self::assertNotFalse($moduleSelectorsStart);
        self::assertNotFalse($usernameSelectorsStart);
        $specificModuleSelector = strpos($server, '#inputServerType', $moduleSelectorsStart);
        $genericModuleSelector = strpos($server, 'select[name="type"]', $moduleSelectorsStart);
        $specificUsernameSelector = strpos($server, '#inputUsername', $usernameSelectorsStart);
        $genericUsernameSelector = strpos($server, 'input[name="username"]', $usernameSelectorsStart);
        $basicModuleSelector = strpos($server, '#addType', $moduleSelectorsStart);
        $basicUsernameSelector = strpos($server, '#newUsername', $usernameSelectorsStart);
        self::assertNotFalse($specificModuleSelector);
        self::assertNotFalse($genericModuleSelector);
        self::assertNotFalse($specificUsernameSelector);
        self::assertNotFalse($genericUsernameSelector);
        self::assertNotFalse($basicModuleSelector);
        self::assertNotFalse($basicUsernameSelector);
        self::assertLessThan($specificModuleSelector, $basicModuleSelector);
        self::assertLessThan($specificUsernameSelector, $basicUsernameSelector);
        self::assertLessThan($genericModuleSelector, $specificModuleSelector);
        self::assertLessThan($genericUsernameSelector, $specificUsernameSelector);
        self::assertStringContainsString('username.readOnly = true', $server);
        self::assertStringContainsString("row.style.display = 'none'", $server);
        self::assertFileExists(dirname(__DIR__, 2) . '/Js/AdminServerConfig.test.js');
        self::assertSame(0, preg_match('/[\p{Han}]/u', $mapper));
        self::assertSame(0, preg_match('/[\p{Han}]/u', $server));
        foreach ([$mapper, $server] as $script) {
            self::assertStringNotContainsString('</script', strtolower($script));
            self::assertStringNotContainsString('innerHTML', $script);
            self::assertStringNotContainsString('eval(', $script);
        }
    }

    public function testAdminAndRuntimeMessagesAreEnglishOnly(): void
    {
        $root = dirname(__DIR__, 3) . '/modules/servers/lumio';
        $files = [$root . '/hooks.php'];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib'));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            self::assertSame(0, preg_match('/[\p{Han}]/u', $contents), $file . ' contains non-English runtime text');
        }
    }

    public function testSuccessfulCreateIsDurablyAcknowledgedAndCannotBePolledAgain(): void
    {
        $root = dirname(__DIR__, 3) . '/modules/servers/lumio';
        $hooks = (string) file_get_contents($root . '/hooks.php');
        $runtime = (string) file_get_contents($root . '/lib/WhmcsRuntime.php');
        $stateRepository = (string) file_get_contents($root . '/lib/Persistence/StateRepository.php');

        self::assertStringContainsString("add_hook('AfterModuleCreate'", $hooks);
        self::assertStringContainsString("['moduletype']", $hooks);
        self::assertStringContainsString("'activation_acknowledged_at'", $hooks);
        self::assertStringContainsString("whereNull('lumio.activation_reported_at')", $runtime);
        self::assertStringContainsString("'activation_acknowledged_at'", $stateRepository);
    }

    public function testModulePackageContainsNoLiteralLiveApiKey(): void
    {
        $root = dirname(__DIR__, 3) . '/modules/servers/lumio';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            self::assertSame(
                0,
                preg_match('/lumio_live_[A-Za-z0-9_-]{20,32}\.[A-Za-z0-9_-]{40,64}/', $contents),
                $file->getPathname() . ' contains a literal API key',
            );
        }
    }
}

final class ModuleEntrypointTestServiceModel
{
    public ModuleEntrypointTestServiceProperties $serviceProperties;

    public function __construct()
    {
        $this->serviceProperties = new ModuleEntrypointTestServiceProperties();
    }
}

final class ModuleEntrypointTestServiceProperties
{
    /** @var array<string, string> */
    public array $values = [];

    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->values[$key] = (string) $value;
        }
    }
}
