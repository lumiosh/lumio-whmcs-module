<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Exception\ApiException;
use Lumio\Whmcs\Exception\ConfigurationException;
use Lumio\Whmcs\Exception\TransportException;
use Lumio\Whmcs\Http\CurlTransport;
use Lumio\Whmcs\Logging\WhmcsLogger;
use Lumio\Whmcs\Support\Sanitizer;
use RuntimeException;
use WHMCS\Database\Capsule;

final class AdminCatalogBootstrap
{
    private const BILLING_CYCLES = ['month', 'quarter', 'semiannual', 'year', 'biennial', 'triennial'];
    private const MAX_PRODUCTS = 200;
    private const MAX_OPTION_GROUPS = 20;
    private const MAX_OPTION_VALUES = 50;
    private const MAX_ADDONS = 50;
    private const MAX_MONEY_CENTS = 999_999_999;

    /** @var null|\Closure(int): array<mixed> */
    private readonly ?\Closure $catalogFetcher;

    /** @param null|\Closure(int): array<mixed> $catalogFetcher */
    public function __construct(?\Closure $catalogFetcher = null)
    {
        $this->catalogFetcher = $catalogFetcher;
    }

    /**
     * @return array{
     *     state: 'empty'|'error'|'ready'|'select_group',
     *     message: string,
     *     request_id: null|string,
     *     products: list<array<string, mixed>>
     * }
     */
    public function load(int $serverGroupId): array
    {
        if ($serverGroupId < 1) {
            return $this->result('select_group', 'Select and save a Server Group containing exactly one Lumio Server.');
        }

        try {
            $fetcher = $this->catalogFetcher;
            $catalog = $fetcher instanceof \Closure
                ? $fetcher($serverGroupId)
                : $this->fetchCatalog($serverGroupId);
            $products = $this->normalizeCatalog($catalog);
            if ($products === []) {
                return $this->result('empty', 'Lumio currently has no products available for integration sales.');
            }
            return $this->result('ready', sprintf('Loaded %d Lumio products.', count($products)), null, $products);
        } catch (ApiException $exception) {
            return $this->result(
                'error',
                'Lumio rejected the product catalog request (' . Sanitizer::errorCode($exception->errorCode) . ').',
                $exception->requestId,
            );
        } catch (TransportException $exception) {
            return $this->result('error', Sanitizer::text($exception->getMessage()), $exception->requestId);
        } catch (ConfigurationException $exception) {
            return $this->result('error', Sanitizer::text($exception->getMessage()));
        } catch (RuntimeException) {
            return $this->result('error', 'Unable to load the Lumio product catalog. Please try again.');
        } catch (\Throwable) {
            return $this->result('error', 'Unable to load the Lumio product catalog. Please try again.');
        }
    }

    /**
     * @param array<mixed> $catalog
     * @return list<array<string, mixed>>
     */
    public function normalizeCatalog(array $catalog): array
    {
        $this->assertList($catalog, self::MAX_PRODUCTS, 'Product catalog');
        $products = [];
        $seenSkus = [];
        foreach ($catalog as $item) {
            if (! is_array($item)) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid product.');
            }
            $sku = trim((string) ($item['sku'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $sku) !== 1 || isset($seenSkus[$sku])) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid or duplicate SKU.');
            }
            $seenSkus[$sku] = true;

            $name = $this->requiredText($item['name'] ?? null, 160, 'Product name');
            $prices = $this->normalizePrices($item['prices'] ?? null);
            $optionGroups = $this->normalizeOptionGroups($item['option_groups'] ?? null);
            $addons = $this->normalizeAddons($item['addons'] ?? null);
            $availability = trim((string) ($item['availability'] ?? ''));
            if ($availability === 'limited') {
                $availability = 'available';
            }
            if (! in_array($availability, ['available', 'unavailable'], true)) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid availability state.');
            }

            $products[] = [
                'sku' => $sku,
                'name' => $name,
                'prices' => $prices,
                'option_groups' => $optionGroups,
                'addons' => $addons,
                'availability' => $availability,
            ];
        }
        return $products;
    }

    /**
     * @param array<string, mixed> $bootstrap
     */
    public function markup(array $bootstrap): string
    {
        try {
            $json = json_encode(
                $bootstrap,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('The Lumio product catalog could not be rendered safely.', 0, $exception);
        }
        return '<script type="application/json" class="lumio-catalog-bootstrap">' . $json . '</script>';
    }

    /** @return array<mixed> */
    private function fetchCatalog(int $serverGroupId): array
    {
        $serverIds = Capsule::table('tblservergroupsrel')
            ->where('groupid', $serverGroupId)
            ->orderBy('serverid')
            ->pluck('serverid')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $serverIds = array_values(array_unique(array_filter($serverIds, static fn (int $id): bool => $id > 0)));
        if ($serverIds === []) {
            throw new ConfigurationException('The selected Server Group does not contain a configured Lumio Server.');
        }

        $servers = Capsule::table('tblservers')
            ->whereIn('id', $serverIds)
            ->where('type', 'lumio')
            ->where('disabled', 0)
            ->orderBy('id')
            ->get();
        if ($servers->count() !== 1) {
            throw new ConfigurationException('The selected Server Group must contain exactly one enabled Lumio Server.');
        }
        $server = $servers->first();
        if (! is_object($server)) {
            throw new ConfigurationException('Unable to read the selected Lumio Server.');
        }

        $params = [
            'serverhostname' => (string) ($server->hostname ?? ''),
            'serverusername' => (string) ($server->username ?? ''),
            'serverpassword' => $this->decryptServerPassword((string) ($server->password ?? '')),
            'serveraccesshash' => (string) ($server->accesshash ?? ''),
            'serversecure' => $server->secure ?? null,
            'serverport' => (int) ($server->port ?? 443),
        ];
        $configuration = new Configuration($params);
        $apiKey = $configuration->apiKey();
        return (new ApiClient(
            $configuration->baseUrl(),
            $apiKey,
            new CurlTransport(5, 15),
            new WhmcsLogger($apiKey),
        ))->catalog();
    }

    private function decryptServerPassword(string $encrypted): string
    {
        if ($encrypted === '' || ! function_exists('localAPI')) {
            throw new ConfigurationException('Unable to read the encrypted Lumio API Key from WHMCS.');
        }
        $result = localAPI('DecryptPassword', ['password2' => $encrypted]);
        if (! is_array($result)
            || strtolower((string) ($result['result'] ?? '')) !== 'success'
            || ! is_string($result['password'] ?? null)
            || trim($result['password']) === '') {
            throw new ConfigurationException('WHMCS could not decrypt the Lumio API Key.');
        }
        return trim($result['password']);
    }

    /** @return list<array{billing_cycle: string, price_cents: int, setup_fee_cents: int}> */
    private function normalizePrices(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new ConfigurationException('The Lumio product catalog is missing prices.');
        }
        $this->assertList($raw, count(self::BILLING_CYCLES), 'Product prices');
        $prices = [];
        $seenCycles = [];
        foreach ($raw as $price) {
            if (! is_array($price)) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid price.');
            }
            $cycle = trim((string) ($price['billing_cycle'] ?? ''));
            if (! in_array($cycle, self::BILLING_CYCLES, true) || isset($seenCycles[$cycle])) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid or duplicate billing cycle.');
            }
            $seenCycles[$cycle] = true;
            $prices[] = [
                'billing_cycle' => $cycle,
                'price_cents' => $this->requiredInteger($price['price_cents'] ?? null, 0, self::MAX_MONEY_CENTS, 'Product price'),
                'setup_fee_cents' => $this->requiredInteger($price['setup_fee_cents'] ?? null, 0, self::MAX_MONEY_CENTS, 'Setup fee'),
            ];
        }
        return $prices;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeOptionGroups(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new ConfigurationException('The Lumio product catalog is missing product options.');
        }
        $this->assertList($raw, self::MAX_OPTION_GROUPS, 'Product option groups');
        $groups = [];
        $seenIds = [];
        foreach ($raw as $group) {
            if (! is_array($group)) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid option group.');
            }
            $id = $this->requiredInteger($group['id'] ?? null, 1, PHP_INT_MAX, 'Option group ID');
            if (isset($seenIds[$id])) {
                throw new ConfigurationException('The Lumio product catalog contains a duplicate option group.');
            }
            $seenIds[$id] = true;
            $inputType = trim((string) ($group['input_type'] ?? ''));
            if (! in_array($inputType, ['select', 'radio', 'checkbox'], true) || ! is_bool($group['required'] ?? null)) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid option group type.');
            }
            $values = $group['values'] ?? null;
            if (! is_array($values)) {
                throw new ConfigurationException('The Lumio product catalog is missing option values.');
            }
            $this->assertList($values, self::MAX_OPTION_VALUES, 'Option values');
            $normalizedValues = [];
            $seenCodes = [];
            foreach ($values as $value) {
                if (! is_array($value)) {
                    throw new ConfigurationException('The Lumio product catalog contains an invalid option value.');
                }
                $code = trim((string) ($value['code'] ?? ''));
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $code) !== 1 || isset($seenCodes[$code])) {
                    throw new ConfigurationException('The Lumio product catalog contains an invalid or duplicate option code.');
                }
                $seenCodes[$code] = true;
                $normalizedValues[] = [
                    'code' => $code,
                    'label' => $this->requiredText($value['label'] ?? null, 160, 'Option value name'),
                    'price_delta_cents' => $this->requiredInteger(
                        $value['price_delta_cents'] ?? null,
                        -self::MAX_MONEY_CENTS,
                        self::MAX_MONEY_CENTS,
                        'Option price adjustment',
                    ),
                ];
            }
            $groups[] = [
                'id' => $id,
                'name' => $this->requiredText($group['name'] ?? null, 160, 'Option group name'),
                'input_type' => $inputType,
                'required' => $group['required'],
                'values' => $normalizedValues,
            ];
        }
        return $groups;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeAddons(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new ConfigurationException('The Lumio product catalog is missing add-ons.');
        }
        $this->assertList($raw, self::MAX_ADDONS, 'Add-ons');
        $addons = [];
        $seenIds = [];
        foreach ($raw as $addon) {
            if (! is_array($addon)) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid add-on.');
            }
            $id = $this->requiredInteger($addon['id'] ?? null, 1, PHP_INT_MAX, 'Add-on ID');
            if (isset($seenIds[$id]) || ! is_bool($addon['required'] ?? null)) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid or duplicate add-on.');
            }
            $seenIds[$id] = true;
            $billingType = trim((string) ($addon['billing_type'] ?? ''));
            if (! in_array($billingType, ['one_time', 'recurring'], true)) {
                throw new ConfigurationException('The Lumio product catalog contains an invalid add-on billing type.');
            }
            $addons[] = [
                'id' => $id,
                'name' => $this->requiredText($addon['name'] ?? null, 160, 'Add-on name'),
                'billing_type' => $billingType,
                'price_cents' => $this->requiredInteger($addon['price_cents'] ?? null, 0, self::MAX_MONEY_CENTS, 'Add-on price'),
                'required' => $addon['required'],
            ];
        }
        return $addons;
    }

    /** @param array<mixed> $value */
    private function assertList(array $value, int $max, string $label): void
    {
        if (! array_is_list($value) || count($value) > $max) {
            throw new ConfigurationException($label . ' has an invalid count or structure.');
        }
    }

    private function requiredText(mixed $value, int $maxLength, string $label): string
    {
        if (! is_string($value)) {
            throw new ConfigurationException($label . ' is invalid.');
        }
        $value = Sanitizer::text($value, $maxLength);
        if ($value === '') {
            throw new ConfigurationException($label . ' must not be empty.');
        }
        return $value;
    }

    private function requiredInteger(mixed $value, int $min, int $max, string $label): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new ConfigurationException($label . ' is invalid.');
        }
        return $value;
    }

    /**
     * @param 'empty'|'error'|'ready'|'select_group' $state
     * @param list<array<string, mixed>> $products
     * @return array{
     *     state: 'empty'|'error'|'ready'|'select_group',
     *     message: string,
     *     request_id: null|string,
     *     products: list<array<string, mixed>>
     * }
     */
    private function result(
        string $state,
        string $message,
        ?string $requestId = null,
        array $products = [],
    ): array {
        $requestId = is_string($requestId) ? Sanitizer::text($requestId, 128) : null;
        return [
            'state' => $state,
            'message' => Sanitizer::text($message),
            'request_id' => $requestId === '' ? null : $requestId,
            'products' => $products,
        ];
    }
}
