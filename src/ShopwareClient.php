<?php

namespace TikTokShopwareSync;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

class ShopwareClient
{
    private Client $client;
    private array $config;
    private LoggerInterface $logger;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->client = new Client([
            'base_uri' => $config['api_url'],
            'auth' => [$config['username'], $config['api_key']],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    public function get(string $endpoint, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->retryRequest(function () use ($endpoint, $options) {
            return $this->client->get($endpoint, $options);
        }, "Fetching from $endpoint");
    }

    public function post(string $endpoint, array $options): \Psr\Http\Message\ResponseInterface
    {
        return $this->retryRequest(function () use ($endpoint, $options) {
            return $this->client->post($endpoint, $options);
        }, "Posting to $endpoint");
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getArticleByNumber(string $articleNumber): ?array
    {
        return $this->retryRequest(function () use ($articleNumber) {
            try {
                $response = $this->client->request('GET', "articles/$articleNumber", [
                    'query' => ['useNumberAsId' => 'true'],
                ]);
                $data = json_decode($response->getBody()->getContents(), true);
                return $data['data'] ?? null; // Direct endpoint returns single article in 'data'
            } catch (RequestException $e) {
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
                $this->logger->warning("Failed to fetch article $articleNumber: " . $e->getMessage() . " (Status: $statusCode)");
                if ($statusCode === 404) {
                    return null; // Article not found
                }
                throw $e; // Rethrow for retry logic
            }
        }, "Fetching article $articleNumber");
    }

    public function createOrder(array $data): array
    {
        return $this->retryRequest(function () use ($data) {
            $response = $this->client->post('orders', ['json' => $data]);
            return json_decode($response->getBody()->getContents(), true);
        }, "Creating order {$data['number']}");
    }

    private function retryRequest(callable $request, string $action): mixed
    {
        $attempts = 0;
        $maxAttempts = 5;

        while ($attempts < $maxAttempts) {
            try {
                return $request();
            } catch (RequestException $e) {
                $attempts++;
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;

                if ($statusCode === 429 || $statusCode >= 500) { // Rate limit or server error
                    $wait = pow(2, $attempts) * 1000; // Exponential backoff in milliseconds
                    $this->logger->warning("$action failed (attempt $attempts/$maxAttempts): " . $e->getMessage() . ". Retrying in $wait ms.");
                    usleep($wait * 1000);
                } else {
                    throw $e; // Non-retryable error (e.g., 404) handled in caller
                }
            }
        }

        throw new \Exception("Max retry attempts reached for $action");
    }
}
