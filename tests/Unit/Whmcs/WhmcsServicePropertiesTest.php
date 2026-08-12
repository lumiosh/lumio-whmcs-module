<?php

declare(strict_types=1);

namespace LumioWhmcsTests\Unit\Whmcs;

use Lumio\Whmcs\WhmcsServiceProperties;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/modules/servers/lumio/lib/Autoload.php';

final class WhmcsServicePropertiesTest extends TestCase
{
    public function testDedicatedIpIsAlsoPersistedOnTheStandardWhmcsServiceModel(): void
    {
        $model = new ServicePropertiesTestModel();
        $properties = new WhmcsServiceProperties(['model' => $model]);

        $properties->save([
            'Username' => 'macuser',
            'Dedicated IP' => '192.0.2.88',
            'Lumio Hostname' => 'device.example.net',
        ]);

        self::assertSame('192.0.2.88', $model->serviceProperties->values['Dedicated IP']);
        self::assertSame('192.0.2.88', $model->dedicatedIp);
        self::assertSame(1, $model->saveCalls);
    }
}

final class ServicePropertiesTestModel
{
    public ServicePropertiesTestManager $serviceProperties;
    public string $dedicatedIp = '';
    public int $saveCalls = 0;

    public function __construct()
    {
        $this->serviceProperties = new ServicePropertiesTestManager();
    }

    public function save(): bool
    {
        ++$this->saveCalls;
        return true;
    }
}

final class ServicePropertiesTestManager
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
