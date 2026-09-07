<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\MyUcto;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use PHPUnit\Framework\TestCase;

final class MyUctoClientTest extends TestCase
{
    public function testHealthSuccess(): void
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok","version":"6.6.3"}'),
        ]);
        $data = $client->health();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('6.6.3', $data['version']);
    }

    public function testErrorEnvelopeMapsToException(): void
    {
        $client = $this->client([
            new Response(422, ['Content-Type' => 'application/json'], '{"error":{"code":"validation_failed","message":"Pole zip je povinné.","fields":{"zip":["Pole zip je povinné."]}}}'),
        ]);
        try {
            $client->createClient(['company_name' => 'X']);
            $this->fail('expected exception');
        } catch (MyUctoApiException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringContainsString('zip', $e->getMessage());
            $this->assertArrayHasKey('zip', $e->details);
        }
    }

    public function testRetriesOn429ThenSucceeds(): void
    {
        $client = $this->client([
            new Response(429, ['Retry-After' => '1'], '{"error":{"code":"rate_limited","message":"slow"}}'),
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-RateLimit-Limit' => '600',
                'X-RateLimit-Remaining' => '599',
            ], '{"status":"ok"}'),
        ]);
        $data = $client->health();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('599', $client->lastRateLimit()['remaining']);
        $this->assertSame('600', $client->lastRateLimit()['limit']);
    }

    public function testRetriesOnceOnNetworkError(): void
    {
        $client = $this->client([
            new ConnectException('timed out', new Request('GET', 'http://myucto-app/api/v1/health')),
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}'),
        ]);
        $this->assertSame('ok', $client->health()['status']);
    }

    public function testNetworkErrorExhaustedThrows(): void
    {
        $req = new Request('GET', 'http://myucto-app/api/v1/health');
        $client = $this->client([
            new ConnectException('timed out', $req),
            new ConnectException('still down', $req),
        ]);
        $this->expectException(MyUctoApiException::class);
        $this->expectExceptionMessage('nedostupné');
        $client->health();
    }

    public function testApplication500IsNotNetworkUnavailable(): void
    {
        $client = $this->client([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":{"code":"varsymbol_failed","message":"Chybí template pro proforma."}}'),
        ]);
        try {
            $client->issueInvoice(134);
            $this->fail('expected exception');
        } catch (MyUctoApiException $e) {
            $this->assertSame('varsymbol_failed', $e->errorCode);
            $this->assertSame(500, $e->httpStatus);
            $this->assertSame('Chybí template pro proforma.', $e->getMessage());
            $this->assertFalse($e->isUnavailable());
        }
    }

    public function testDisabledThrowsWithoutHttp(): void
    {
        $client = $this->client([], enabled: false);
        $this->expectException(MyUctoApiException::class);
        $this->expectExceptionMessage('vypnutá');
        $client->health();
    }

    public function testMissingTokenThrows(): void
    {
        $client = $this->client([], token: '');
        $this->expectException(MyUctoApiException::class);
        $client->health();
    }

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function client(array $queue, bool $enabled = true, string $token = 'mi_pat_test'): MyUctoClient
    {
        $mock = new MockHandler($queue);
        $http = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);
        $config = new Config([
            'tri' => [
                'myucto' => [
                    'enabled' => $enabled,
                    'base_url' => 'http://myucto-app',
                    'public_url' => 'https://ucto.triant.cz',
                    'token' => $token,
                ],
            ],
        ]);

        return new MyUctoClient($config, $http);
    }
}
