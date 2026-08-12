<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Exception\ApiException;
use Lumio\Whmcs\Exception\ConfigurationException;
use Lumio\Whmcs\Exception\TransportException;
use Lumio\Whmcs\Support\Sanitizer;

final class ConnectionTester
{
    private const REQUIRED_SCOPES = [
        'catalog:read',
        'wallet:read',
        'purchase:write',
        'service:read',
        'credentials:read',
        'renewal:write',
        'lifecycle:write',
    ];

    /**
     * @param array<string, mixed> $params
     * @return array{success: bool, error: string}
     */
    public function test(array $params): array
    {
        try {
            $configuration = new Configuration($params);
            $configuration->installationId();
            $account = ModuleFactory::api($params)->account();
            if (($account['api_version'] ?? null) !== 'v1' || ($account['purchasing_allowed'] ?? null) !== true) {
                return ['success' => false, 'error' => 'This Lumio account is not allowed to make automated purchases through API v1'];
            }
            $scopes = is_array($account['scopes'] ?? null)
                ? array_values(array_filter($account['scopes'], 'is_string'))
                : [];
            $missing = array_values(array_diff(self::REQUIRED_SCOPES, $scopes));
            if ($missing !== []) {
                return [
                    'success' => false,
                    'error' => 'The Lumio API Key is missing required permissions: ' . implode(', ', $missing),
                ];
            }
            return ['success' => true, 'error' => ''];
        } catch (ConfigurationException $exception) {
            return ['success' => false, 'error' => 'Configuration error: ' . Sanitizer::text($exception->getMessage())];
        } catch (ApiException $exception) {
            $suffix = $exception->requestId === null ? '' : '; Request-Id: ' . Sanitizer::text($exception->requestId, 128);
            return [
                'success' => false,
                'error' => 'Lumio rejected the connection (' . Sanitizer::errorCode($exception->errorCode) . ')' . $suffix,
            ];
        } catch (TransportException $exception) {
            return ['success' => false, 'error' => 'Unable to connect to Lumio: ' . Sanitizer::text($exception->getMessage())];
        } catch (\Throwable $exception) {
            return ['success' => false, 'error' => 'Lumio connection test failed: ' . Sanitizer::text($exception->getMessage())];
        }
    }
}
