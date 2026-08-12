<?php

declare(strict_types=1);

namespace LumioWhmcsTests\Unit\Whmcs;

use Lumio\Whmcs\Configuration;
use Lumio\Whmcs\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/modules/servers/lumio/lib/Autoload.php';

final class ConfigurationTest extends TestCase
{
    #[DataProvider('billingCycles')]
    public function testMapsSupportedWhmcsBillingCycles(string $whmcsCycle, string $lumioCycle): void
    {
        $params = $this->params();
        $params['billingcycle'] = $whmcsCycle;
        self::assertSame($lumioCycle, (new Configuration($params))->billingCycle());
    }

    /** @return iterable<string, array{string, string}> */
    public static function billingCycles(): iterable
    {
        yield 'monthly' => ['Monthly', 'month'];
        yield 'quarterly' => ['Quarterly', 'quarter'];
        yield 'semi annually' => ['Semi-Annually', 'semiannual'];
        yield 'annually' => ['Annually', 'year'];
        yield 'biennially' => ['Biennially', 'biennial'];
        yield 'triennially' => ['Triennially', 'triennial'];
    }

    #[DataProvider('billingCycles')]
    public function testMapsBillingCycleFromWhmcsServiceModel(string $whmcsCycle, string $lumioCycle): void
    {
        $params = $this->params();
        unset($params['billingcycle']);
        $params['model'] = new class($whmcsCycle) {
            public function __construct(public string $billingCycle) {}

            public function getBillingCycle(): string
            {
                return $this->billingCycle;
            }
        };

        self::assertSame($lumioCycle, (new Configuration($params))->billingCycle());
    }

    public function testFallsBackToWhmcsServiceModelProperty(): void
    {
        $params = $this->params();
        unset($params['billingcycle']);
        $params['model'] = new class {
            public string $billingCycle = 'Monthly';
        };

        self::assertSame('month', (new Configuration($params))->billingCycle());
    }

    public function testBuildsValidatedPurchasePayloadAndReference(): void
    {
        $configuration = new Configuration($this->params());
        $reference = $configuration->externalReference(987, 'create', 1);
        self::assertSame('whmcs-shop-example-01-service-987-create-1', $reference);
        self::assertMatchesRegularExpression(
            '/^whmcs-v1-[A-Za-z0-9_-]{43}$/D',
            $configuration->idempotencyKey($reference),
        );
        self::assertSame([
            'external_reference' => $reference,
            'product_sku' => 'example-product-a',
            'billing_cycle' => 'month',
            'quantity' => 1,
            'configuration' => ['12' => ['standard'], '13' => ['option-b']],
            'addon_ids' => [3, 9],
            'expected_total_cents' => 10000,
        ], $configuration->purchasePayload($reference));
        self::assertSame('https://api.example.com/api/v1/integration', $configuration->baseUrl());
        $configuration->assertTerminationPolicyAccepted();
    }

    public function testAcceptsTheCompleteLumioApiBaseUrlFromTheServerField(): void
    {
        $params = $this->params();
        $params['serverhostname'] = 'https://api.example.com/api/v1/integration';
        $params['serversecure'] = false;
        $params['serverport'] = 80;

        self::assertSame(
            'https://api.example.com/api/v1/integration',
            (new Configuration($params))->baseUrl(),
        );
    }

    public function testNormalizesACompleteLumioApiBaseUrl(): void
    {
        $params = $this->params();
        $params['serverhostname'] = 'https://API.EXAMPLE.COM:443/api/v1/integration/';

        self::assertSame(
            'https://api.example.com/api/v1/integration',
            (new Configuration($params))->baseUrl(),
        );
    }

    public function testAcceptsAChangedLumioApiBasePathWithoutACodeUpdate(): void
    {
        $params = $this->params();
        $params['serverhostname'] = 'https://api.example.com/integration/v2';

        self::assertSame(
            'https://api.example.com/integration/v2',
            (new Configuration($params))->baseUrl(),
        );
    }

    public function testUsesTheCompleteBaseUrlStoredBehindTheWhmcsHostnameField(): void
    {
        $params = $this->params();
        $params['serverhostname'] = 'api.example.com';
        $params['serveraccesshash'] = 'https://api.example.com/integration/v2';

        self::assertSame(
            'https://api.example.com/integration/v2',
            (new Configuration($params))->baseUrl(),
        );
    }

    public function testFallsBackToTheLegacyAccessHashParameterName(): void
    {
        $params = $this->params();
        $params['serverhostname'] = 'api.example.com';
        $params['serveraccesshash'] = '';
        $params['accesshash'] = 'https://api.example.com/integration/v2';

        self::assertSame(
            'https://api.example.com/integration/v2',
            (new Configuration($params))->baseUrl(),
        );
    }

    #[DataProvider('invalidParams')]
    public function testRejectsUnsafeOrIncompleteConfiguration(string $key, mixed $value): void
    {
        $params = $this->params();
        $params[$key] = $value;
        $this->expectException(ConfigurationException::class);
        $configuration = new Configuration($params);
        if ($key === 'configoption12') {
            $configuration->assertTerminationPolicyAccepted();
            return;
        }
        match ($key) {
            'serverhostname', 'serversecure', 'serverport' => $configuration->baseUrl(),
            'serverpassword' => $configuration->apiKey(),
            'serverusername' => $configuration->installationId(),
            'billingcycle' => $configuration->billingCycle(),
            'configoption1' => $configuration->productSku(),
            'configoption2' => $configuration->fixedConfiguration(),
            'configoption3' => $configuration->addonIds(),
            'configoption4' => $configuration->costCapCents(),
        };
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidParams(): iterable
    {
        yield 'http disabled' => ['serversecure', false];
        yield 'http base url' => ['serverhostname', 'http://api.example.com/api/v1/integration'];
        yield 'base url with credentials' => ['serverhostname', 'https://key@api.example.com/api/v1/integration'];
        yield 'base url with query' => ['serverhostname', 'https://api.example.com/api/v1/integration?key=value'];
        yield 'hostname contains credentials' => ['serverhostname', 'key@api.example.com'];
        yield 'invalid port' => ['serverport', 70000];
        yield 'invalid api key' => ['serverpassword', 'not-a-key'];
        yield 'invalid installation id' => ['serverusername', 'a'];
        yield 'unsupported one-time billing' => ['billingcycle', 'One Time'];
        yield 'invalid sku' => ['configoption1', '../secret'];
        yield 'invalid fixed config json' => ['configoption2', '{broken'];
        yield 'duplicate addon' => ['configoption3', '3,3'];
        yield 'invalid cost cap' => ['configoption4', '-1'];
        yield 'termination policy not accepted' => ['configoption12', 'off'];
    }

    /** @return array<string, mixed> */
    private function params(): array
    {
        return [
            'serverhostname' => 'api.example.com',
            'serverusername' => 'shop-example-01',
            'serverpassword' => 'lumio_live_' . str_repeat('a', 24) . '.' . str_repeat('b', 43),
            'serversecure' => true,
            'serverport' => 443,
            'billingcycle' => 'Monthly',
            'configoption1' => 'example-product-a',
            'configoption2' => '{"12":["standard"],"13":["option-b"]}',
            'configoption3' => '9,3',
            'configoption4' => '10000',
            'configoption10' => 'on',
            'configoption11' => '2',
            'configoption12' => 'on',
        ];
    }
}
