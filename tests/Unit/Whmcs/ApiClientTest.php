<?php

declare(strict_types=1);

namespace LumioWhmcsTests\Unit\Whmcs;

use Lumio\Whmcs\ApiClient;
use Lumio\Whmcs\Contract\LoggerInterface;
use Lumio\Whmcs\Contract\TransportInterface;
use Lumio\Whmcs\Exception\ApiException;
use Lumio\Whmcs\Exception\TransportException;
use Lumio\Whmcs\Http\HttpResponse;
use Lumio\Whmcs\Version;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/modules/servers/lumio/lib/Autoload.php';

final class ApiClientTest extends TestCase
{
    private const KEY = 'test-api-key-not-a-secret';

    public function testSuccessResponseUsesVersionedPathAndMetadataOnlyLogs(): void
    {
        $transport = new ApiClientTestTransport(new HttpResponse(
            200,
            ['content-type' => 'application/json; charset=utf-8'],
            json_encode(['code' => 200, 'message' => 'ok', 'data' => ['sku' => 'example product']], JSON_THROW_ON_ERROR),
        ));
        $logger = new ApiClientTestLogger();
        $client = new ApiClient('https://api.example.com/api/v1/integration', self::KEY, $transport, $logger);

        self::assertSame(['sku' => 'example product'], $client->product('example product'));
        self::assertSame('https://api.example.com/api/v1/integration/catalog/products/example%20product', $transport->url);
        self::assertContains('Authorization: Bearer ' . self::KEY, $transport->headers);
        self::assertContains('User-Agent: Lumio-WHMCS/' . Version::NUMBER, $transport->headers);
        self::assertCount(1, $logger->calls);
        $serializedLog = json_encode($logger->calls, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::KEY, $serializedLog);
        self::assertStringNotContainsString('Authorization', $serializedLog);
    }

    public function testCatalogUsesTheServerSideProductListEndpoint(): void
    {
        $transport = new ApiClientTestTransport(new HttpResponse(
            200,
            ['content-type' => 'application/json'],
            json_encode([
                'code' => 200,
                'message' => 'ok',
                'data' => [['sku' => 'example-product-a', 'name' => 'Example Product A']],
            ], JSON_THROW_ON_ERROR),
        ));
        $client = new ApiClient('https://api.example.com/api/v1/integration', self::KEY, $transport, new ApiClientTestLogger());

        self::assertSame(
            [['sku' => 'example-product-a', 'name' => 'Example Product A']],
            $client->catalog(),
        );
        self::assertSame('https://api.example.com/api/v1/integration/catalog/products', $transport->url);
        self::assertContains('Authorization: Bearer ' . self::KEY, $transport->headers);
    }

    public function testStructuredApiFailureCarriesSafeCodeRequestIdAndRetryDelay(): void
    {
        $transport = new ApiClientTestTransport(new HttpResponse(
            429,
            ['content-type' => 'application/json', 'retry-after' => '120'],
            json_encode([
                'code' => 429,
                'message' => 'too many requests',
                'data' => ['error' => 'RATE_LIMITED', 'request_id' => 'req-server-1'],
            ], JSON_THROW_ON_ERROR),
        ));
        $client = new ApiClient('https://api.example.com/api/v1/integration', self::KEY, $transport, new ApiClientTestLogger());

        try {
            $client->account();
            self::fail('Expected ApiException');
        } catch (ApiException $exception) {
            self::assertSame(429, $exception->httpStatus);
            self::assertSame('RATE_LIMITED', $exception->errorCode);
            self::assertSame('req-server-1', $exception->requestId);
            self::assertSame(120, $exception->retryAfter);
            self::assertSame('req-server-1', $client->lastRequestId());
        }
    }

    public function testReadsCurrentLumioServiceByNumericId(): void
    {
        $transport = new ApiClientTestTransport(new HttpResponse(
            200,
            ['content-type' => 'application/json'],
            json_encode(['code' => 200, 'message' => 'ok', 'data' => ['id' => 501, 'state' => 'active']], JSON_THROW_ON_ERROR),
        ));
        $client = new ApiClient('https://api.example.com/api/v1/integration', self::KEY, $transport, new ApiClientTestLogger());

        self::assertSame(['id' => 501, 'state' => 'active'], $client->service(501));
        self::assertSame('https://api.example.com/api/v1/integration/services/501', $transport->url);
    }

    public function testRejectsNonJsonSuccessResponse(): void
    {
        $transport = new ApiClientTestTransport(new HttpResponse(200, ['content-type' => 'text/html'], '<html>error</html>'));
        $client = new ApiClient('https://api.example.com/api/v1/integration', self::KEY, $transport, new ApiClientTestLogger());
        $this->expectException(TransportException::class);
        $client->account();
    }

    public function testInvalidServerRequestIdFallsBackToSafeClientRequestId(): void
    {
        $transport = new ApiClientTestTransport(new HttpResponse(
            500,
            ['content-type' => 'application/json'],
            json_encode([
                'code' => 500,
                'message' => 'failed',
                'data' => ['error' => 'INTERNAL_ERROR', 'request_id' => str_repeat('x', 500)],
            ], JSON_THROW_ON_ERROR),
        ));
        $client = new ApiClient('https://api.example.com/api/v1/integration', self::KEY, $transport, new ApiClientTestLogger());

        try {
            $client->account();
            self::fail('Expected ApiException');
        } catch (ApiException $exception) {
            self::assertMatchesRegularExpression('/^whmcs-[a-f0-9]{32}$/D', (string) $exception->requestId);
            self::assertSame($exception->requestId, $client->lastRequestId());
        }
    }

    public function testTransportFailureGetsARequestIdForSafeReconciliation(): void
    {
        $transport = new ApiClientTestTransport(new TransportException('timeout'));
        $client = new ApiClient('https://api.example.com/api/v1/integration', self::KEY, $transport, new ApiClientTestLogger());
        try {
            $client->purchase(['external_reference' => 'order-1'], 'order-1');
            self::fail('Expected TransportException');
        } catch (TransportException $exception) {
            self::assertNotNull($exception->requestId);
            self::assertStringStartsWith('whmcs-', (string) $exception->requestId);
        }
    }
}

final class ApiClientTestTransport implements TransportInterface
{
    public string $url = '';

    /** @var list<string> */
    public array $headers = [];

    public function __construct(private readonly HttpResponse|TransportException $result) {}

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->url = $url;
        $this->headers = $headers;
        if ($this->result instanceof TransportException) {
            throw $this->result;
        }
        return $this->result;
    }
}

final class ApiClientTestLogger implements LoggerInterface
{
    /** @var list<array{string, array<string, mixed>, array<string, mixed>}> */
    public array $calls = [];

    public function apiCall(string $action, array $request, array $response): void
    {
        $this->calls[] = [$action, $request, $response];
    }

    public function activity(string $message): void {}
}
