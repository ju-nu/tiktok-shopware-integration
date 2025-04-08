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

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getArticleByNumber(string $articleNumber): ?array
    {
        return $this->retryRequest(function () use ($articleNumber) {
            $response = $this->client->get("articles?filter[0][property]=number&filter[0][value]=$articleNumber");
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['data'][0] ?? null;
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
                    $this->logger->warning("$action failed (attempt $attempts/$maxAttempts): {$e->getMessage}. Retrying in $wait ms.");
                    usleep($wait * 1000);
                } else {
                    throw $e; // Non-retryable error
                }
            }
        }

        throw new \Exception("Max retry attempts reached for $action");
    }
}