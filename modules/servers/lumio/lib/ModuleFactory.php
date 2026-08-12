<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Http\CurlTransport;
use Lumio\Whmcs\Logging\WhmcsLogger;
use Lumio\Whmcs\Persistence\StateRepository;

final class ModuleFactory
{
    /** @param array<string, mixed> $params */
    public static function api(array $params): ApiClient
    {
        $configuration = new Configuration($params);
        $apiKey = $configuration->apiKey();
        $logger = new WhmcsLogger($apiKey);
        return new ApiClient(
            $configuration->baseUrl(),
            $apiKey,
            new CurlTransport(),
            $logger,
        );
    }

    /** @param array<string, mixed> $params */
    public static function workflow(array $params): ModuleWorkflow
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $configuration = new Configuration($params);
        $apiKey = $configuration->apiKey();
        $logger = new WhmcsLogger($apiKey);
        $api = new ApiClient(
            $configuration->baseUrl(),
            $apiKey,
            new CurlTransport(),
            $logger,
        );
        return new ModuleWorkflow(
            $serviceId,
            $configuration,
            $api,
            new StateRepository(),
            new WhmcsRuntime(),
            new WhmcsServiceProperties($params),
            $logger,
        );
    }
}
