<?php

namespace App\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CsvProcessor
{
    private ShopwareClient $shopwareClient;
    private Logger $logger;

    public function __construct()
    {
        $this->shopwareClient = new ShopwareClient();
        $this->logger = new Logger('csv_processor');
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../Logs/app.log', Logger::INFO));
    }

    public function process(string $filePath): void
    {
        $this->logger->info("Processing CSV: $filePath");

        $orders = $this->parseCsv($filePath);
        foreach ($orders as $orderId => $items) {
            $this->createShopwareOrder($orderId, $items);
        }

        unlink($filePath); // Remove processed file
        $this->logger->info("Finished processing CSV: $filePath");
    }

    private function parseCsv(string $filePath): array
    {
        $orders = [];
        if (($handle = fopen($filePath, 'r')) !== false) {
            // Get headers and remove BOM if present
            $headers = fgetcsv($handle, 0, ',');
            if ($headers === false) {
                $this->logger->error("Failed to read headers from CSV: $filePath");
                fclose($handle);
                return [];
            }
            $headers = array_map(function ($header) {
                return trim($header, "\ufeff"); // Remove BOM
            }, $headers);

            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                if (count($row) !== count($headers)) {
                    $this->logger->warning("Row column count mismatch: " . implode(',', $row));
                    continue;
                }
                $data = array_combine($headers, $row);
                if ($data === false) {
                    $this->logger->warning("Failed to combine headers with row: " . implode(',', $row));
                    continue;
                }
                if (!isset($data['Order ID'])) {
                    $this->logger->error("Missing 'Order ID' in row: " . implode(',', $row));
                    continue;
                }
                $orderId = $data['Order ID'];
                $orders[$orderId][] = $data;
            }
            fclose($handle);
        } else {
            $this->logger->error("Failed to open CSV file: $filePath");
        }
        return $orders;
    }

    private function createShopwareOrder(string $orderId, array $items): void
    {
        // Rest of the method remains unchanged
        $paymentId = $this->shopwareClient->getPaymentMethodId('TikTok');
        if (!$paymentId) {
            $this->logger->error("Payment method 'TikTok' not found");
            return;
        }

        $firstItem = $items[0];
        [$firstName, $lastName] = $this->splitName($firstItem['Recipient']);

        $orderDetails = [];
        $total = 0;
        foreach ($items as $item) {
            $article = $this->shopwareClient->getArticleByNumber($item['Seller SKU']);
            if (!$article) {
                $this->logger->error("Article not found for SKU: " . $item['Seller SKU']);
                continue;
            }

            $price = (float)str_replace([' EUR', ','], ['', '.'], $item['SKU Subtotal After Discount']);
            $quantity = (int)$item['Quantity'];
            $total += $price * $quantity;

            $orderDetails[] = [
                'articleId' => $article['id'],
                'articleNumber' => $article['number'],
                'name' => $item['Product Name'],
                'quantity' => $quantity,
                'price' => $price / $quantity,
                'taxId' => $article['mainDetail']['taxId'],
            ];
        }

        $shippingFee = (float)str_replace([' EUR', ','], ['', '.'], $firstItem['Original Shipping Fee']);
        $shippingDiscount = (float)str_replace([' EUR', ','], ['', '.'], $firstItem['Shipping Fee Platform Discount']);
        if ($shippingFee > 0) {
            $total += $shippingFee;
            $orderDetails[] = [
                'articleId' => null,
                'articleNumber' => 'SHIPPING',
                'name' => 'Shipping Cost',
                'quantity' => 1,
                'price' => $shippingFee,
                'taxId' => 1,
            ];
        }
        if ($shippingDiscount > 0) {
            $total -= $shippingDiscount;
            $orderDetails[] = [
                'articleId' => null,
                'articleNumber' => 'SHIPPING_DISCOUNT',
                'name' => 'Shipping Discount',
                'quantity' => 1,
                'price' => -$shippingDiscount,
                'taxId' => 1,
            ];
        }

        $orderData = [
            'number' => $orderId,
            'customer' => ['email' => $firstItem['Email']],
            'billing' => [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'street' => $firstItem['Street Name'],
                'zipCode' => $firstItem['Zipcode'],
                'city' => $firstItem['City'],
                'countryId' => 2,
            ],
            'shipping' => [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'street' => $firstItem['Street Name'],
                'zipCode' => $firstItem['Zipcode'],
                'city' => $firstItem['City'],
                'countryId' => 2,
            ],
            'payment' => ['id' => $paymentId],
            'orderStatus' => 1,
            'paymentStatus' => 12,
            'details' => $orderDetails,
            'invoiceAmount' => $total,
            'attribute' => ['attribute1' => $orderId],
        ];

        $this->shopwareClient->createOrder($orderData);
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName));
        $lastName = array_pop($parts);
        $firstName = implode(' ', $parts);
        return [$firstName ?: 'Unknown', $lastName ?: 'Unknown'];
    }
}