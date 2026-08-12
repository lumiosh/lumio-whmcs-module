<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Contract\ServicePropertiesInterface;
use RuntimeException;

final class WhmcsServiceProperties implements ServicePropertiesInterface
{
    private object $serviceModel;

    private \Closure $getProperty;

    private \Closure $saveProperties;

    /** @param array<string, mixed> $params */
    public function __construct(array $params)
    {
        $model = $params['model'] ?? null;
        if (! is_object($model) || ! isset($model->serviceProperties) || ! is_object($model->serviceProperties)) {
            throw new RuntimeException('WHMCS Service Properties are unavailable');
        }
        $properties = $model->serviceProperties;
        if (! is_callable([$properties, 'get']) || ! is_callable([$properties, 'save'])) {
            throw new RuntimeException('The WHMCS Service Properties interface is incomplete');
        }
        $this->serviceModel = $model;
        $this->getProperty = \Closure::fromCallable([$properties, 'get']);
        $this->saveProperties = \Closure::fromCallable([$properties, 'save']);
    }

    public function get(string $name): ?string
    {
        $value = ($this->getProperty)($name);
        if ($value === null || $value === '') {
            return null;
        }
        return is_scalar($value) ? (string) $value : null;
    }

    public function save(array $values): void
    {
        ($this->saveProperties)($values);

        $dedicatedIp = $values['Dedicated IP'] ?? null;
        if (! is_string($dedicatedIp) || filter_var($dedicatedIp, FILTER_VALIDATE_IP) === false) {
            return;
        }
        if (! is_callable([$this->serviceModel, 'save'])) {
            throw new RuntimeException('The WHMCS Service model cannot synchronize the Dedicated IP');
        }
        $this->serviceModel->dedicatedIp = $dedicatedIp;
        if ($this->serviceModel->save() !== true) {
            throw new RuntimeException('Failed to synchronize the standard WHMCS Dedicated IP');
        }
    }
}
