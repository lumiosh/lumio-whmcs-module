<?php

declare(strict_types=1);

namespace {
    if (! function_exists('logModuleCall')) {
        /** @param mixed ...$arguments */
        function logModuleCall(...$arguments): void
        {
            $GLOBALS['lumio_test_module_calls'][] = $arguments;
        }
    }
}

namespace LumioWhmcsTests\Unit\Whmcs {
    use Lumio\Whmcs\Logging\WhmcsLogger;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 3) . '/modules/servers/lumio/lib/Autoload.php';

    final class WhmcsLoggerTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['lumio_test_module_calls'] = [];
        }

        public function testModuleLogUsesReadableSafeJsonForRequestAndResponse(): void
        {
            $apiKey = 'lumio_live_test.secret-value';
            (new WhmcsLogger($apiKey))->apiCall(
                '/services/100/credentials',
                [
                    'method' => 'GET',
                    'path' => '/services/100/credentials',
                    'authorization' => 'Bearer ' . $apiKey,
                ],
                [
                    'status' => 503,
                    'error' => 'TEMPORARY_UNAVAILABLE',
                    'request_id' => 'whmcs-request-1',
                    'password' => 'must-not-be-logged',
                ],
            );

            self::assertCount(1, $GLOBALS['lumio_test_module_calls']);
            $call = $GLOBALS['lumio_test_module_calls'][0];
            self::assertSame('lumio', $call[0]);
            self::assertIsString($call[2]);
            self::assertIsString($call[3]);
            self::assertSame([
                'method' => 'GET',
                'path' => '/services/100/credentials',
            ], json_decode($call[2], true, 32, JSON_THROW_ON_ERROR));
            self::assertSame([
                'status' => 503,
                'error' => 'TEMPORARY_UNAVAILABLE',
                'request_id' => 'whmcs-request-1',
            ], json_decode($call[3], true, 32, JSON_THROW_ON_ERROR));
            self::assertSame([$apiKey], $call[5]);
            self::assertStringNotContainsString($apiKey, $call[2] . $call[3]);
            self::assertStringNotContainsString('must-not-be-logged', $call[2] . $call[3]);
        }
    }
}
