<?php

namespace App\Services\Pterodactyl;

use App\Services\Config\ConfigService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class PterodactylClient
{
    private HttpClient $http;
    private string $baseUrl;
    private LoggerInterface $logger;

    public function __construct(ConfigService $config, LoggerInterface $logger)
    {
        $this->baseUrl = rtrim($config->get('pterodactyl.base_url'), '/');
        $apiKey = $config->get('pterodactyl.app_api_key');
        $this->logger = $logger;

        $this->http = new HttpClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'Application/vnd.pterodactyl.v1+json',
                'Content-Type' => 'application/json',
            ],
            'http_errors' => false,
            'timeout' => 30,
        ]);
    }

    private function logRequest(string $method, string $uri, array $options = []): void
    {
        $redacted = $options;
        // Avoid logging auth header; we set it globally.
        $this->logger->info('[Ptero] Request', [
            'method' => $method,
            'uri' => $uri,
            'options' => $redacted,
        ]);
    }

    private function logResponse(int $status, string $body): void
    {
        $this->logger->info('[Ptero] Response', [
            'status' => $status,
            'body' => $body,
        ]);
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        $this->logRequest($method, $uri, $options);
        try {
            $response = $this->http->request($method, $uri, $options);
        } catch (GuzzleException $e) {
            $this->logger->error('[Ptero] HTTP error', ['error' => $e->getMessage()]);
            throw $e;
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $this->logResponse($status, $body);

        $json = json_decode($body, true);
        return [
            'status' => $status,
            'body' => $body,
            'json' => $json,
        ];
    }

    // Users
    public function findUserByEmail(string $email): ?int
    {
        $res = $this->request('GET', '/api/application/users', [
            'query' => [
                'search' => $email,
            ],
        ]);

        if ($res['status'] >= 400 || !isset($res['json']['data'])) {
            return null;
        }
        foreach ($res['json']['data'] as $item) {
            if (($item['attributes']['email'] ?? '') === $email) {
                return (int) $item['attributes']['id'];
            }
        }
        return null;
    }

    public function createUser(string $email, string $firstName, string $lastName): int
    {
        $username = $this->formatUsernameFromEmail($email);
        $payload = [
            'email' => $email,
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            // No password: let panel send welcome/reset
        ];

        $res = $this->request('POST', '/api/application/users', [
            'json' => $payload,
        ]);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new \RuntimeException('Failed to create user: ' . $res['body']);
        }
        return (int) ($res['json']['attributes']['id'] ?? 0);
    }

    private function formatUsernameFromEmail(string $email): string
    {
        // Temporary rule: replace '@' with '_' and strip unsupported characters.
        // Allowed: letters, numbers, dashes, underscores, periods.
        $candidate = str_replace('@', '_', $email);
        $candidate = preg_replace('/[^A-Za-z0-9._-]/', '', $candidate) ?? '';
        // Ensure starts/ends with alphanumeric by trimming non-alnum at ends.
        $candidate = preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9]+$/', '', $candidate) ?? '';
        // Fallback if becomes empty
        if ($candidate === '') {
            $candidate = 'user' . substr(md5($email), 0, 8);
        }
        return $candidate;
    }

    // Eggs
    public function getEggDetails(int $nestId, int $eggId): array
    {
        $res = $this->request('GET', "/api/application/nests/{$nestId}/eggs/{$eggId}");
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new \RuntimeException('Failed to fetch egg details: ' . $res['body']);
        }
        return $res['json']['attributes'] ?? [];
    }

    // Nodes & allocations
    public function listNodeAllocations(int $nodeId, int $page = 1): array
    {
        $res = $this->request('GET', "/api/application/nodes/{$nodeId}/allocations", [
            'query' => [
                'page' => $page,
                'per_page' => 100,
            ],
        ]);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new \RuntimeException('Failed to list allocations: ' . $res['body']);
        }
        return $res['json'];
    }

    public function findFirstFreeAllocationInRange(int $nodeId, int $portMin, int $portMax): ?int
    {
        $page = 1;
        while (true) {
            $data = $this->listNodeAllocations($nodeId, $page);
            $items = $data['data'] ?? [];
            foreach ($items as $item) {
                $attr = $item['attributes'] ?? [];
                $port = (int) ($attr['port'] ?? 0);
                $assigned = (bool) ($attr['assigned'] ?? false);
                if (!$assigned && $port >= $portMin && $port <= $portMax) {
                    return (int) $attr['id'];
                }
            }
            $meta = $data['meta']['pagination'] ?? null;
            if (!$meta || $meta['current_page'] >= $meta['total_pages']) {
                break;
            }
            $page++;
        }
        return null;
    }

    // Servers
    public function createServer(array $payload): array
    {
        $res = $this->request('POST', '/api/application/servers', [
            'json' => $payload,
        ]);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new \RuntimeException('Failed to create server: ' . $res['body']);
        }
        return $res['json'];
    }
}


