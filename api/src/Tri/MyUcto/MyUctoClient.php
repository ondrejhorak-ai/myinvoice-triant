<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use MyInvoice\Infrastructure\Config\Config;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP klient na MyÚčto REST API v1 (PAT bearer).
 * Interní HTTP volání posílá X-Forwarded-Proto: https — image MyÚčta jinak 301 na HTTPS.
 */
final class MyUctoClient
{
    private const TIMEOUT_SEC = 30;
    private const MAX_429_RETRIES = 3;
    private const MAX_NETWORK_RETRIES = 1;

    private Client $http;

    /** @var array<string, string> */
    private array $lastHeaders = [];

    public function __construct(
        private readonly Config $config,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'timeout' => self::TIMEOUT_SEC,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    /**
     * Hlavičky z poslední odpovědi (rate-limit, API verze).
     *
     * @return array{limit:string, remaining:string, reset:string, retry_after:string, api_version:string}
     */
    public function lastRateLimit(): array
    {
        return [
            'limit' => $this->lastHeaders['x-ratelimit-limit'] ?? '',
            'remaining' => $this->lastHeaders['x-ratelimit-remaining'] ?? '',
            'reset' => $this->lastHeaders['x-ratelimit-reset'] ?? '',
            'retry_after' => $this->lastHeaders['retry-after'] ?? '',
            'api_version' => $this->lastHeaders['x-api-version'] ?? '',
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('tri.myucto.enabled', false)
            && $this->token() !== '';
    }

    public function publicUrl(): string
    {
        return rtrim((string) $this->config->get('tri.myucto.public_url', ''), '/');
    }

    public function health(): array
    {
        return $this->json('GET', '/api/v1/health');
    }

    public function apiMe(): array
    {
        return $this->json('GET', '/api/v1/auth/api-me');
    }

    /** @param array<string, mixed> $query */
    public function listClients(array $query = []): array
    {
        return $this->json('GET', '/api/v1/clients', null, $query);
    }

    /** @param array<string, mixed> $body */
    public function createClient(array $body): array
    {
        return $this->json('POST', '/api/v1/clients', $body);
    }

    public function getClient(int $id): array
    {
        return $this->json('GET', '/api/v1/clients/' . $id);
    }

    /** @param array<string, mixed> $body */
    public function updateClient(int $id, array $body): array
    {
        return $this->json('PUT', '/api/v1/clients/' . $id, $body);
    }

    public function archiveClient(int $id): array
    {
        return $this->json('POST', '/api/v1/clients/' . $id . '/archive');
    }

    public function unarchiveClient(int $id): array
    {
        return $this->json('POST', '/api/v1/clients/' . $id . '/unarchive');
    }

    /** @param array<string, mixed> $body */
    public function lookupAres(array $body): array
    {
        return $this->json('POST', '/api/v1/clients/lookup-ares', $body);
    }

    /** @param array<string, mixed> $query */
    public function listProjects(array $query = []): array
    {
        return $this->json('GET', '/api/v1/projects', null, $query);
    }

    /** @param array<string, mixed> $body */
    public function createProject(array $body): array
    {
        return $this->json('POST', '/api/v1/projects', $body);
    }

    public function getProject(int $id): array
    {
        return $this->json('GET', '/api/v1/projects/' . $id);
    }

    /** @param array<string, mixed> $body */
    public function updateProject(int $id, array $body): array
    {
        return $this->json('PUT', '/api/v1/projects/' . $id, $body);
    }

    /** @param array<string, mixed> $query */
    public function listInvoices(array $query = []): array
    {
        return $this->json('GET', '/api/v1/invoices', null, $query);
    }

    public function getInvoice(int $id): array
    {
        return $this->json('GET', '/api/v1/invoices/' . $id);
    }

    /** @param array<string, mixed> $body */
    public function createInvoice(array $body): array
    {
        return $this->json('POST', '/api/v1/invoices', $body);
    }

    /** @param array<string, mixed> $body */
    public function updateInvoice(int $id, array $body): array
    {
        return $this->json('PUT', '/api/v1/invoices/' . $id, $body);
    }

    public function deleteInvoice(int $id): array
    {
        return $this->json('DELETE', '/api/v1/invoices/' . $id);
    }

    /** @param array<string, mixed> $query */
    public function previewVarsymbol(array $query = []): array
    {
        return $this->json('GET', '/api/v1/invoices/preview-varsymbol', null, $query);
    }

    public function issueInvoice(int $id): array
    {
        return $this->json('POST', '/api/v1/invoices/' . $id . '/issue');
    }

    public function getRecipients(int $id): array
    {
        return $this->json('GET', '/api/v1/invoices/' . $id . '/recipients');
    }

    /** @param array<string, mixed> $body */
    public function sendInvoice(int $id, array $body = []): array
    {
        return $this->json('POST', '/api/v1/invoices/' . $id . '/send', $body);
    }

    public function sendReminder(int $id): array
    {
        return $this->json('POST', '/api/v1/invoices/' . $id . '/reminder');
    }

    /** @return array{body:string, contentType:string} */
    public function getInvoicePdf(int $id): array
    {
        $res = $this->send('GET', '/api/v1/invoices/' . $id . '/pdf');
        return [
            'body' => (string) $res->getBody(),
            'contentType' => $res->getHeaderLine('Content-Type') ?: 'application/pdf',
        ];
    }

    public function getPublicLink(int $id): array
    {
        return $this->json('POST', '/api/v1/invoices/' . $id . '/public-link');
    }

    /** @param array<string, mixed> $body */
    public function markPaid(int $id, array $body = []): array
    {
        return $this->json('POST', '/api/v1/invoices/' . $id . '/mark-paid', $body);
    }

    public function unmarkPaid(int $id): array
    {
        return $this->json('POST', '/api/v1/invoices/' . $id . '/unmark-paid');
    }

    public function listPayments(int $id): array
    {
        return $this->json('GET', '/api/v1/invoices/' . $id . '/payments');
    }

    /** @param array<string, mixed> $body */
    public function addPayment(int $id, array $body): array
    {
        return $this->json('POST', '/api/v1/invoices/' . $id . '/payments', $body);
    }

    public function deletePayment(int $invoiceId, int $paymentId): array
    {
        return $this->json('DELETE', '/api/v1/invoices/' . $invoiceId . '/payments/' . $paymentId);
    }

    public function cloneInvoice(int $id): array
    {
        return $this->json('POST', '/api/v1/invoices/' . $id . '/clone');
    }

    /** @param array<string, mixed> $body */
    public function linkAdvance(int $id, array $body): array
    {
        return $this->json('POST', '/api/v1/invoices/' . $id . '/link-advance', $body);
    }

    public function codebook(string $name): array
    {
        return $this->json('GET', '/api/v1/codebooks/' . rawurlencode($name));
    }

    public function vatRates(): array
    {
        return $this->json('GET', '/api/v1/settings/vat-rates');
    }

    public function currencies(): array
    {
        return $this->json('GET', '/api/v1/settings/currencies');
    }

    public function brandingProfiles(): array
    {
        return $this->json('GET', '/api/v1/branding-profiles');
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     */
    public function json(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $res = $this->send($method, $path, $body, $query);
        $raw = (string) $res->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['_raw' => $raw];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     */
    private function send(string $method, string $path, ?array $body = null, array $query = []): ResponseInterface
    {
        if (!(bool) $this->config->get('tri.myucto.enabled', false)) {
            throw new MyUctoApiException('disabled', 'Integrace s MyÚčtem je vypnutá (TRI_MYUCTO_ENABLED).', 503);
        }
        $token = $this->token();
        if ($token === '') {
            throw new MyUctoApiException('unconfigured', 'Chybí TRI_MYUCTO_TOKEN.', 503);
        }
        $base = rtrim((string) $this->config->get('tri.myucto.base_url', ''), '/');
        if ($base === '') {
            throw new MyUctoApiException('unconfigured', 'Chybí TRI_MYUCTO_BASE_URL.', 503);
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'X-Forwarded-Proto' => 'https',
            ],
            'query' => $query,
        ];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['json'] = $body;
        }

        $url = $base . $path;
        $networkAttempts = 0;
        $rateAttempts = 0;

        while (true) {
            try {
                $res = $this->http->request($method, $url, $options);
            } catch (ConnectException $e) {
                if ($networkAttempts < self::MAX_NETWORK_RETRIES) {
                    $networkAttempts++;
                    continue;
                }
                throw new MyUctoApiException('network', 'MyÚčto nedostupné: ' . $e->getMessage(), 0, $e);
            } catch (GuzzleException $e) {
                $status = $e instanceof RequestException && $e->hasResponse()
                    ? $e->getResponse()->getStatusCode()
                    : 0;
                throw new MyUctoApiException('network', 'MyÚčto chyba spojení: ' . $e->getMessage(), $status, $e);
            }

            $this->rememberHeaders($res);
            $status = $res->getStatusCode();
            if ($status === 429 && $rateAttempts < self::MAX_429_RETRIES) {
                $rateAttempts++;
                continue;
            }

            if ($status >= 400) {
                $this->throwFromResponse($res);
            }

            return $res;
        }
    }

    private function throwFromResponse(ResponseInterface $res): never
    {
        $raw = (string) $res->getBody();
        $decoded = json_decode($raw, true);
        $err = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        $code = 'http_error';
        $message = 'MyÚčto vrátilo HTTP ' . $res->getStatusCode();
        $details = [];
        if (is_array($err)) {
            $code = (string) ($err['code'] ?? $code);
            $message = (string) ($err['message'] ?? $message);
            if (isset($err['fields']) && is_array($err['fields'])) {
                $details = $err['fields'];
            } elseif (isset($err['details']) && is_array($err['details'])) {
                $details = $err['details'];
            }
        }
        throw new MyUctoApiException($code, $message, $res->getStatusCode(), null, $details);
    }

    private function rememberHeaders(ResponseInterface $res): void
    {
        $this->lastHeaders = [];
        foreach (['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Retry-After', 'X-API-Version'] as $name) {
            $this->lastHeaders[strtolower($name)] = $res->getHeaderLine($name);
        }
    }

    private function token(): string
    {
        return trim((string) $this->config->get('tri.myucto.token', ''));
    }
}
