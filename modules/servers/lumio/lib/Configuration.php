<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Exception\ConfigurationException;

final class Configuration
{
    private const CYCLE_MAP = [
        'monthly' => 'month',
        'quarterly' => 'quarter',
        'semi-annually' => 'semiannual',
        'semiannually' => 'semiannual',
        'annually' => 'year',
        'biennially' => 'biennial',
        'triennially' => 'triennial',
    ];

    private const COST_CAP_OPTIONS = [
        'month' => 4,
        'quarter' => 5,
        'semiannual' => 6,
        'year' => 7,
        'biennial' => 8,
        'triennial' => 9,
    ];

    /** @param array<string, mixed> $params */
    public function __construct(private readonly array $params) {}

    public function baseUrl(): string
    {
        $storedBaseUrl = trim((string) ($this->params['serveraccesshash'] ?? ''));
        if ($storedBaseUrl === '') {
            $storedBaseUrl = trim((string) ($this->params['accesshash'] ?? ''));
        }
        if (str_contains($storedBaseUrl, '://')) {
            return $this->completeBaseUrl($storedBaseUrl);
        }

        $address = trim((string) ($this->params['serverhostname'] ?? ''));
        if (str_contains($address, '://')) {
            return $this->completeBaseUrl($address);
        }

        if (! $this->isTruthy($this->params['serversecure'] ?? null)) {
            throw new ConfigurationException('The Lumio server must use SSL/TLS (HTTPS)');
        }

        $hostname = $this->validatedHostname($address);
        $port = (int) ($this->params['serverport'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new ConfigurationException('The Lumio server port is invalid');
        }

        return $this->formatBaseUrl($hostname, $port);
    }

    private function completeBaseUrl(string $address): string
    {
        if (strlen($address) > 255
            || filter_var($address, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('The Lumio API base URL is invalid');
        }

        $parts = parse_url($address);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new ConfigurationException('Enter the complete HTTPS Lumio API base URL without credentials, a query, or a fragment');
        }

        $hostname = $this->validatedHostname(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            throw new ConfigurationException('The Lumio API base URL port is invalid');
        }

        $host = str_contains($hostname, ':') ? '[' . $hostname . ']' : $hostname;
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port) . $path;
    }

    private function validatedHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === ''
            || strlen($hostname) > 253
            || str_contains($hostname, '://')
            || str_contains($hostname, '/')
            || str_contains($hostname, '@')
            || preg_match('/[\x00-\x20\x7f]/', $hostname) === 1
            || (! filter_var($hostname, FILTER_VALIDATE_IP)
                && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $hostname) !== 1)) {
            throw new ConfigurationException('The Lumio server hostname is invalid');
        }
        return $hostname;
    }

    private function formatBaseUrl(string $hostname, int $port): string
    {
        $host = str_contains($hostname, ':') ? '[' . $hostname . ']' : $hostname;
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port) . '/api/v1/integration';
    }

    public function apiKey(): string
    {
        $apiKey = trim((string) ($this->params['serverpassword'] ?? ''));
        if (preg_match('/^lumio_live_[A-Za-z0-9_-]{20,32}\.[A-Za-z0-9_-]{40,64}$/D', $apiKey) !== 1) {
            throw new ConfigurationException('The Lumio API Key format is invalid');
        }
        return $apiKey;
    }

    public function installationId(): string
    {
        $value = trim((string) ($this->params['serverusername'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/D', $value) !== 1) {
            throw new ConfigurationException('The WHMCS installation ID must contain 3 to 64 valid characters');
        }
        return strtolower($value);
    }

    public function productSku(): string
    {
        $sku = trim((string) ($this->params['configoption1'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $sku) !== 1) {
            throw new ConfigurationException('The Lumio product SKU is invalid');
        }
        return $sku;
    }

    public function billingCycle(): string
    {
        $raw = strtolower(trim($this->whmcsBillingCycle()));
        $cycle = self::CYCLE_MAP[$raw] ?? null;
        if ($cycle === null) {
            throw new ConfigurationException('This WHMCS billing cycle is not supported; use Monthly, Quarterly, Semi-Annually, Annually, Biennially, or Triennially');
        }
        return $cycle;
    }

    public function costCapCents(?string $cycle = null): int
    {
        $cycle ??= $this->billingCycle();
        $option = self::COST_CAP_OPTIONS[$cycle] ?? null;
        if ($option === null) {
            throw new ConfigurationException('The Lumio cost-cap billing cycle is invalid');
        }
        $raw = trim((string) ($this->params['configoption' . $option] ?? ''));
        if (preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $raw) !== 1) {
            throw new ConfigurationException('The Lumio cost cap for this billing cycle must be an integer from 0 to 999999999 cents');
        }
        return (int) $raw;
    }

    /** @return array<string, list<string>> */
    public function fixedConfiguration(): array
    {
        $raw = trim((string) ($this->params['configoption2'] ?? ''));
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException('The saved product configuration is invalid', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded) || count($decoded) > 20) {
            throw new ConfigurationException('The saved product configuration must contain valid option groups');
        }

        $result = [];
        foreach ($decoded as $groupId => $values) {
            $groupId = (string) $groupId;
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $groupId) !== 1
                || ! is_array($values)
                || ! array_is_list($values)
                || count($values) > 20) {
                throw new ConfigurationException('A saved product option group is invalid');
            }
            $codes = [];
            foreach ($values as $value) {
                if (! is_string($value)
                    || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $value) !== 1) {
                    throw new ConfigurationException('A saved product option code is invalid');
                }
                $codes[] = $value;
            }
            $result[$groupId] = array_values(array_unique($codes));
        }
        ksort($result, SORT_NATURAL);
        return $result;
    }

    /** @return list<int> */
    public function addonIds(): array
    {
        $raw = trim((string) ($this->params['configoption3'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $part) !== 1) {
                throw new ConfigurationException('Lumio add-on IDs must be comma-separated positive integers');
            }
            $id = (int) $part;
            if (in_array($id, $ids, true)) {
                throw new ConfigurationException('Lumio add-on IDs must not be duplicated');
            }
            $ids[] = $id;
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public function assertTerminationPolicyAccepted(): void
    {
        if (! $this->isTruthy($this->params['configoption12'] ?? null)) {
            throw new ConfigurationException(
                'The Immediate Termination Policy must be accepted in the WHMCS product module settings before selling Lumio services',
            );
        }
    }

    /** @return array<string, mixed> */
    public function purchasePayload(string $externalReference): array
    {
        return [
            'external_reference' => $externalReference,
            'product_sku' => $this->productSku(),
            'billing_cycle' => $this->billingCycle(),
            'quantity' => 1,
            'configuration' => $this->fixedConfiguration(),
            'addon_ids' => $this->addonIds(),
            'expected_total_cents' => $this->costCapCents(),
        ];
    }

    public function externalReference(int $serviceId, string $action, int|string $sequence): string
    {
        $reference = sprintf(
            'whmcs-%s-service-%d-%s-%s',
            $this->installationId(),
            $serviceId,
            $action,
            (string) $sequence,
        );
        if (strlen($reference) > 190
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,189}$/D', $reference) !== 1) {
            throw new ConfigurationException('The generated Lumio external reference is invalid');
        }
        return $reference;
    }

    public function idempotencyKey(string $externalReference): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,189}$/D', $externalReference) !== 1) {
            throw new ConfigurationException('An idempotency key cannot be generated for an invalid Lumio external reference');
        }
        $digest = rtrim(strtr(base64_encode(hash('sha256', $externalReference, true)), '+/', '-_'), '=');
        return 'whmcs-v1-' . $digest;
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    private function whmcsBillingCycle(): string
    {
        $model = $this->params['model'] ?? null;
        if (is_object($model)) {
            if (is_callable([$model, 'getBillingCycle'])) {
                $cycle = $model->getBillingCycle();
                if (is_string($cycle) && trim($cycle) !== '') {
                    return $cycle;
                }
            }

            $cycle = $model->billingCycle ?? null;
            if (is_string($cycle) && trim($cycle) !== '') {
                return $cycle;
            }
        }

        return (string) ($this->params['billingcycle'] ?? '');
    }
}
