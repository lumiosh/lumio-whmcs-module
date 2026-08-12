<?php

declare(strict_types=1);

namespace LumioWhmcsTests\Unit\Whmcs;

use Lumio\Whmcs\AdminCatalogBootstrap;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/modules/servers/lumio/lib/Autoload.php';

final class AdminCatalogBootstrapTest extends TestCase
{
    public function testRequiresASavedServerGroupWithoutCallingLumio(): void
    {
        $called = false;
        $loader = new AdminCatalogBootstrap(static function (int $groupId) use (&$called): array {
            $called = true;
            return [];
        });

        $result = $loader->load(0);

        self::assertFalse($called);
        self::assertSame('select_group', $result['state']);
        self::assertSame([], $result['products']);
    }

    public function testNormalizesOnlyPublicMappingDataNeededByTheWhmcsPage(): void
    {
        $loader = new AdminCatalogBootstrap(static fn (int $groupId): array => [[
            'sku' => 'example-product-a',
            'name' => 'Example Product A',
            'description' => 'not needed by the mapper',
            'internal_secret' => 'never-forward-this',
            'prices' => [[
                'billing_cycle' => 'month',
                'price_cents' => 10000,
                'setup_fee_cents' => 500,
                'base_traffic_bytes' => 1_000_000,
            ]],
            'option_groups' => [[
                'id' => 31,
                'name' => '选项组 A',
                'input_type' => 'select',
                'required' => true,
                'values' => [[
                    'code' => 'standard',
                    'label' => 'Standard',
                    'price_delta_cents' => 1000,
                ]],
            ]],
            'addons' => [[
                'id' => 41,
                'name' => '示例附加项',
                'billing_type' => 'recurring',
                'price_cents' => 600,
                'traffic_bytes' => 1_000_000,
                'required' => false,
            ]],
            'availability' => 'available',
            'inventory' => [
                'mode' => 'manual',
                'available_units' => 2,
                'as_of' => '2026-08-03T00:00:00+00:00',
            ],
        ]]);

        $result = $loader->load(7);

        self::assertSame('ready', $result['state']);
        self::assertSame([[
            'sku' => 'example-product-a',
            'name' => 'Example Product A',
            'prices' => [[
                'billing_cycle' => 'month',
                'price_cents' => 10000,
                'setup_fee_cents' => 500,
            ]],
            'option_groups' => [[
                'id' => 31,
                'name' => '选项组 A',
                'input_type' => 'select',
                'required' => true,
                'values' => [[
                    'code' => 'standard',
                    'label' => 'Standard',
                    'price_delta_cents' => 1000,
                ]],
            ]],
            'addons' => [[
                'id' => 41,
                'name' => '示例附加项',
                'billing_type' => 'recurring',
                'price_cents' => 600,
                'required' => false,
            ]],
            'availability' => 'available',
        ]], $result['products']);
        self::assertStringNotContainsString('never-forward-this', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('base_traffic_bytes', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('traffic_bytes', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('available_units', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testRejectsMalformedCatalogInsteadOfShowingPartialMappings(): void
    {
        $loader = new AdminCatalogBootstrap(static fn (int $groupId): array => [[
            'sku' => '../invalid',
            'name' => 'Invalid',
            'prices' => [],
            'option_groups' => [],
            'addons' => [],
            'availability' => 'available',
            'inventory' => ['mode' => 'unlimited', 'available_units' => null],
        ]]);

        $result = $loader->load(7);

        self::assertSame('error', $result['state']);
        self::assertSame([], $result['products']);
        self::assertStringContainsString('SKU', $result['message']);
    }

    public function testUnexpectedWhmcsFailureDoesNotExposeDatabaseOrCredentialDetails(): void
    {
        $loader = new AdminCatalogBootstrap(static function (int $groupId): array {
            throw new \RuntimeException('SQL failed with password=secret-value');
        });

        $result = $loader->load(7);

        self::assertSame('error', $result['state']);
        self::assertSame('Unable to load the Lumio product catalog. Please try again.', $result['message']);
        self::assertStringNotContainsString('SQL', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-value', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testJsonBootstrapCannotBreakOutOfItsNonExecutableScriptElement(): void
    {
        $loader = new AdminCatalogBootstrap();
        $markup = $loader->markup([
            'state' => 'error',
            'message' => '</script><img src=x onerror=alert(1)>',
            'request_id' => null,
            'products' => [],
        ]);

        self::assertStringStartsWith('<script type="application/json"', $markup);
        self::assertSame(1, substr_count($markup, '</script>'));
        self::assertStringNotContainsString('<img', $markup);
        self::assertStringContainsString('\\u003C/script\\u003E', $markup);
    }
}
