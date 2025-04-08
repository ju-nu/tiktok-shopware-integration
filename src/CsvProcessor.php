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
        if ($handle === false) {
            $this->logger->error("Failed to open file: $filePath");
            return;
        }
    
        // Read headers
        $headers = fgetcsv($handle, 0, ',');
        if ($headers === false || empty($headers)) {
            $this->logger->error("No headers found in file: $filePath");
            fclose($handle);
            return;
        }
    
        // Remove BOM and trim headers
        $headers[0] = str_replace("\ufeff", '', $headers[0]);
        $headers = array_map('trim', $headers);
        $this->logger->debug("Headers: " . implode(', ', $headers));
    
        // Check if 'Order ID' is in headers
        if (!in_array('Order ID', $headers)) {
            $this->logger->error("Header 'Order ID' not found in: " . implode(', ', $headers));
            fclose($handle);
            return;
        }
    
        // Process rows
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            // Trim data to handle extra spaces
            $data = array_map('trim', $data);
    
            // Validate column count
            if (count($data) !== count($headers)) {
                $this->logger->warning("Row column count (" . count($data) . ") does not match header count (" . count($headers) . "): " . implode(', ', $data));
                continue;
            }
    
            // Combine headers and data
            $row = array_combine($headers, $data);
            if ($row === false) {
                $this->logger->error("Failed to combine headers with data: " . implode(', ', $data));
                continue;
            }
    
            // Ensure 'Order ID' exists and is not empty
            if (!isset($row['Order ID']) || empty(trim($row['Order ID']))) {
                $this->logger->error("Missing or empty 'Order ID' in row: " . implode(', ', $data));
                continue;
            }
    
            // Use trimmed Order ID as key
            $orderId = trim($row['Order ID']);
            $orders[$orderId][] = $row;
        }
        fclose($handle);
    
        // Debug the final $orders structure
        $this->logger->debug("Orders array: " . json_encode(array_map(fn($rows) => count($rows), $orders), JSON_PRETTY_PRINT));
    
        // Process each order
        foreach ($orders as $orderId => $orderRows) {
            $this->createShopwareOrder($orderId, $orderRows);
        }
    
        unlink($filePath);
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
            'orderStatusId' => 5, // "Zur Lieferung bereit" (Ready to be shipped)
            'paymentStatusId' => 12, // "Komplett bezahlt" (Payed)
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