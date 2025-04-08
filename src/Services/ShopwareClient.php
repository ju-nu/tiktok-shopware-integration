<?php

namespace App\Services;

use App\Config\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class ShopwareClient
{
    private Client $client;
    private Logger $logger;
    private int $retryAttempts = 3;
    private int $baseDelay = 1000; // ms

    public function __construct()
    {
        $this->logger = new Logger('shopware');
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../Logs/app.log', Logger::INFO));

        $this->client = new Client([
            'base_uri' => Config::get('SHOPWARE_API_URL'),
            'auth' => [Config::get('SHOPWARE_API_USERNAME'), Config::get('SHOPWARE_API_KEY')],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    public function getPaymentMethodId(string $name): ?int
    {
        return $this->retry(function () use ($name) {
            $response = $this->client->get('payments');
            $payments = json_decode($response->getBody(), true)['data'];
            foreach ($payments as $payment) {
                if ($payment['description'] === $name) {
                    return $payment['id'];
                }
            }
            return null;
        }, 'Fetching payment method');
    }

    public function getArticleByNumber(string $number): ?array
    {
        return $this->retry(function () use ($number) {
            $response = $this->client->get("articles?filter[number]=$number");
            $data = json_decode($response->getBody(), true);
            return $data['data'][0] ?? null;
        }, "Fetching article $number");
    }

    public function createOrder(array $orderData): void
    {
        $this->retry(function () use ($orderData) {
            $this->client->post('orders', ['json' => $orderData]);
            $this->logger->info("Order created: " . $orderData['number']);
        }, 'Creating order');
    }

    private function retry(callable $callback, string $action): mixed
    {
        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            try {
                return $callback();
            } catch (RequestException $e) {
                if ($e->getResponse() && $e->getResponse()->getStatusCode() === 429) {
                    $delay = $this->baseDelay * (2 ** ($attempt - 1));
                    $this->logger->warning("$action rate limited, retrying in {$delay}ms (attempt $attempt)");
                    usleep($delay * 1000);
                } else {
                    $this->logger->error("$action failed: " . $e->getMessage());
                    throw $e;
                }
            }
        }
        throw new \Exception("$action failed after $this->retryAttempts attempts");
    }
}