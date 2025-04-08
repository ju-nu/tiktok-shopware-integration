<?php

namespace TikTokShopwareSync;

use Psr\Log\LoggerInterface;

class CsvProcessor
{
    private ShopwareClient $shopwareClient;
    private LoggerInterface $logger;

    public function __construct(ShopwareClient $shopwareClient, LoggerInterface $logger)
    {
        $this->shopwareClient = $shopwareClient;
        $this->logger = $logger;
    }

    public function processFile(string $filePath): void
    {
        $this->logger->info("Processing file: $filePath");

        if (!file_exists($filePath)) {
            $this->logger->error("File not found: $filePath");
            return;
        }

        $orders = [];
        $handle = fopen($filePath, 'r');
        $headers = fgetcsv($handle, 0, ','); // Get headers
        $headers[0] = str_replace("\ufeff", '', $headers[0]); // Remove BOM from "Order ID"

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $row = array_combine($headers, $data);
            $orderId = $row['Order ID'];
            $orders[$orderId][] = $row;
        }
        fclose($handle);

        foreach ($orders as $orderId => $orderRows) {
            $this->createShopwareOrder($orderId, $orderRows);
        }

        unlink($filePath); // Remove processed file
        $this->logger->info("Finished processing file: $filePath");
    }

    private function createShopwareOrder(string $orderId, array $orderRows): void
    {
        $this->logger->info("Creating order for TikTok Order ID: $orderId");

        $firstRow = $orderRows[0];
        $recipient = $this->splitRecipientName($firstRow['Recipient']);
        $orderData = [
            'number' => $orderId,
            'customerId' => null, // TikTok doesn't provide customer ID; could create guest customer
            'paymentId' => $this->shopwareClient->getConfig()['payment_method_id'],
            'dispatchId' => $this->shopwareClient->getConfig()['shipping_method_id'],
            'orderStatusId' => 1, // "Ready to be shipped"
            'paymentStatusId' => 12, // "Payed"
            'invoiceAmount' => (float)str_replace(' EUR', '', $firstRow['Order Amount']),
            'invoiceShipping' => (float)str_replace(' EUR', '', $firstRow['Shipping Fee After Discount']),
            'currency' => 'EUR',
            'currencyFactor' => 1.0,
            'billing' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'street' => $firstRow['Street Name'],
                'streetNumber' => $firstRow['House Name or Number'],
                'zipcode' => $firstRow['Zipcode'],
                'city' => $firstRow['City'],
                'countryId' => $this->shopwareClient->getConfig()['country_id'],
            ],
            'shipping' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'street' => $firstRow['Street Name'],
                'streetNumber' => $firstRow['House Name or Number'],
                'zipcode' => $firstRow['Zipcode'],
                'city' => $firstRow['City'],
                'countryId' => $this->shopwareClient->getConfig()['country_id'],
            ],
            'attribute' => [
                'attribute1' => $orderId, // Store TikTok Order ID in custom field
            ],
            'details' => [],
        ];

        // Add order items
        foreach ($orderRows as $row) {
            $article = $this->shopwareClient->getArticleByNumber($row['Seller SKU']);
            if (!$article) {
                $this->logger->error("Article not found for SKU: {$row['Seller SKU']}");
                continue;
            }

            $unitPrice = (float)str_replace(' EUR', '', $row['SKU Unit Original Price']);
            $quantity = (int)$row['Quantity'];
            $orderData['details'][] = [
                'articleId' => $article['id'],
                'articleNumber' => $row['Seller SKU'],
                'name' => $row['Product Name'],
                'quantity' => $quantity,
                'price' => $unitPrice,
                'taxId' => $article['mainDetail']['taxId'],
            ];
        }

        // Add shipping discount if applicable
        $shippingFeeDiscount = (float)str_replace(' EUR', '', $firstRow['Shipping Fee Platform Discount']);
        if ($shippingFeeDiscount > 0) {
            $orderData['details'][] = [
                'articleNumber' => 'SHIPPING_DISCOUNT',
                'name' => 'Shipping Discount (TikTok)',
                'quantity' => 1,
                'price' => -$shippingFeeDiscount,
                'taxId' => $article['mainDetail']['taxId'], // Use tax from first product
            ];
        }

        try {
            $response = $this->shopwareClient->createOrder($orderData);
            $this->logger->info("Order created successfully: Shopware Order ID {$response['id']}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to create order $orderId: " . $e->getMessage());
        }
    }

    private function splitRecipientName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName));
        $lastName = array_pop($parts);
        $firstName = implode(' ', $parts);
        return [
            'firstName' => $firstName ?: 'Unknown',
            'lastName' => $lastName ?: 'Unknown',
        ];
    }
}