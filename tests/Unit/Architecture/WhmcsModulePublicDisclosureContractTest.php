<?php

declare(strict_types=1);

namespace LumioWhmcsTests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class WhmcsModulePublicDisclosureContractTest extends TestCase
{
    /** @var array<string, string> */
    private const FORBIDDEN_DISCLOSURES = [
        'private key block' => '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/',
        'embedded URL credentials' => '#https?://[^/\s:@]+:[^/\s@]+@#i',
        'literal Lumio API key' => '/lumio_live_[A-Za-z0-9_-]{20,32}\.[A-Za-z0-9_-]{40,64}/i',
        'absolute Windows path' => '/\b[A-Z]:\\\\/i',
        'Unix user path' => '#/(?:home|Users)/[^/\s]+#i',
        'server deployment path' => '#/(?:opt|srv|var/www)/[^/\s]+#i',
    ];

    /** @var list<string> */
    private const PUBLIC_MANIFEST = [
        'CHANGELOG.md',
        'LICENSE',
        'README.md',
        'assets/admin-product-mapper.js',
        'assets/admin-server-config.js',
        'hooks.php',
        'index.php',
        'lang/chinese.php',
        'lang/english.php',
        'lib/AdminCatalogBootstrap.php',
        'lib/ApiClient.php',
        'lib/Autoload.php',
        'lib/Configuration.php',
        'lib/ConnectionTester.php',
        'lib/Contract/ApiClientInterface.php',
        'lib/Contract/LoggerInterface.php',
        'lib/Contract/RuntimeInterface.php',
        'lib/Contract/ServicePropertiesInterface.php',
        'lib/Contract/StateRepositoryInterface.php',
        'lib/Contract/TransportInterface.php',
        'lib/CronRunner.php',
        'lib/Exception/ApiException.php',
        'lib/Exception/ConfigurationException.php',
        'lib/Exception/TransportException.php',
        'lib/Http/CurlTransport.php',
        'lib/Http/HttpResponse.php',
        'lib/Logging/NullLogger.php',
        'lib/Logging/WhmcsLogger.php',
        'lib/ModuleFactory.php',
        'lib/ModuleInspector.php',
        'lib/ModuleWorkflow.php',
        'lib/Persistence/StateRepository.php',
        'lib/Support/Sanitizer.php',
        'lib/Version.php',
        'lib/WhmcsRuntime.php',
        'lib/WhmcsServiceProperties.php',
        'lumio.php',
        'templates/clientarea.tpl',
    ];

    public function testPublicModuleUsesExactManifestAndDoesNotDiscloseInternalImplementation(): void
    {
        $publicRoot = dirname(__DIR__, 3) . '/modules/servers/lumio';
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($publicRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            self::assertFalse($entry->isLink(), $entry->getPathname() . ' must not be a link');
            if (! $entry->isFile()) {
                continue;
            }
            $actual[] = str_replace('\\', '/', substr(
                $entry->getPathname(),
                strlen($publicRoot) + 1,
            ));
        }

        $expected = self::PUBLIC_MANIFEST;
        sort($actual, \SORT_STRING);
        sort($expected, \SORT_STRING);
        self::assertSame($expected, $actual, $publicRoot . ' contains unapproved or missing files');

        foreach ($actual as $relative) {
            $path = $publicRoot . '/' . $relative;
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            foreach (self::FORBIDDEN_DISCLOSURES as $label => $pattern) {
                self::assertSame(0, preg_match($pattern, $contents), $path . ' discloses ' . $label);
            }
        }
    }
}
