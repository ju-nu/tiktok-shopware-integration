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
    
        // Log raw headers
        $this->logger->debug("Raw headers: " . implode(', ', $headers));
        $this->logger->debug("Raw first header bytes: " . bin2hex($headers[0]));
    
        // Clean headers
        $headers[0] = str_replace("\ufeff", '', $headers[0]); // Remove UTF-8 BOM
        $headers = array_map(function ($header) {
            // Remove all whitespace and control characters, normalize to ASCII
            return preg_replace('/[\p{C}\s]+/u', '', trim($header));
        }, $headers);
    
        // Log cleaned headers
        $this->logger->debug("Cleaned headers: " . implode(', ', $headers));
        $this->logger->debug("Cleaned first header bytes: " . bin2hex($headers[0]));
    
        // Check for 'Order ID'
        if ($headers[0] !== 'OrderID') {
            $this->logger->error("Expected 'OrderID' as first header, found: '{$headers[0]}'");
            fclose($handle);
            return;
        }
    
        // Process rows
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $data = array_map('trim', $data);
    
            if (count($data) !== count($headers)) {
                $this->logger->warning("Row column count (" . count($data) . ") does not match header count (" . count($headers) . "): " . implode(', ', $data));
                continue;
            }
    
            $row = array_combine($headers, $data);
            if ($row === false) {
                $this->logger->error("Failed to combine headers with data: " . implode(', ', $data));
                continue;
            }
    
            if (!isset($row['OrderID']) || empty(trim($row['OrderID']))) {
                $this->logger->error("Missing or empty 'OrderID' in row: " . implode(', ', $data));
                continue;
            }
    
            $orderId = trim($row['OrderID']);
            $orders[$orderId][] = $row;
        }
        fclose($handle);
    
        $this->logger->debug("Orders array: " . json_encode(array_map(fn($rows) => count($rows), $orders), JSON_PRETTY_PRINT));
    
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
        $recipient = $this->splitRecipientName($firstRow['Recipient'] ?? 'Unknown Unknown');
    
        // Validate required fields with defaults (using normalized keys)
        $requiredFields = [
            'OrderAmount' => '0,00 EUR',
            'ShippingFeeAfterDiscount' => '0,00 EUR',
            'StreetName' => 'Unknown',
            'HouseNameorNumber' => '',
            'Zipcode' => '00000',
            'City' => 'Unknown',
            'SellerSKU' => '',
            'SKUUnitOriginalPrice' => '0,00 EUR',
            'Quantity' => '1',
            'ProductName' => 'Unknown Product',
            'ShippingFeePlatformDiscount' => '0,00 EUR',
        ];
        foreach ($requiredFields as $key => $default) {
            if (!isset($firstRow[$key]) || empty(trim($firstRow[$key]))) {
                $this->logger->warning("Missing or empty '$key' in order $orderId, using default: '$default'");
                $firstRow[$key] = $default;
            }
        }
    
        $orderData = [
            'number' => $orderId,
            'customerId' => null,
            'paymentId' => $this->shopwareClient->getConfig()['payment_method_id'],
            'dispatchId' => $this->shopwareClient->getConfig()['shipping_method_id'],
            'orderStatusId' => 5,
            'paymentStatusId' => 12,
            'invoiceAmount' => (float)str_replace(' EUR', '', $firstRow['OrderAmount']),
            'invoiceShipping' => (float)str_replace(' EUR', '', $firstRow['ShippingFeeAfterDiscount']),
            'currency' => 'EUR',
            'currencyFactor' => 1.0,
            'billing' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'street' => $firstRow['StreetName'],
                'streetNumber' => $firstRow['HouseNameorNumber'],
                'zipcode' => $firstRow['Zipcode'],
                'city' => $firstRow['City'],
                'countryId' => $this->shopwareClient->getConfig()['country_id'],
            ],
            'shipping' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'street' => $firstRow['StreetName'],
                'streetNumber' => $firstRow['HouseNameorNumber'],
                'zipcode' => $firstRow['Zipcode'],
                'city' => $firstRow['City'],
                'countryId' => $this->shopwareClient->getConfig()['country_id'],
            ],
            'attribute' => [
                'attribute1' => $orderId,
            ],
            'details' => [],
        ];
    
        foreach ($orderRows as $row) {
            foreach ($requiredFields as $key => $default) {
                if (!isset($row[$key]) || empty(trim($row[$key]))) {
                    $row[$key] = $default;
                }
            }
    
            $sellerSku = $row['SellerSKU'];
            if (empty($sellerSku)) {
                $this->logger->error("Skipping item in order $orderId - missing 'SellerSKU'");
                continue;
            }
    
            $article = $this->shopwareClient->getArticleByNumber($sellerSku);
            if (!$article) {
                $this->logger->error("Skipping item with SKU $sellerSku in order $orderId - not found in Shopware");
                continue;
            }
    
            $unitPrice = (float)str_replace(' EUR', '', $row['SKUUnitOriginalPrice']);
            $quantity = (int)$row['Quantity'];
            $orderData['details'][] = [
                'articleId' => $article['id'],
                'articleNumber' => $sellerSku,
                'name' => $row['ProductName'],
                'quantity' => $quantity,
                'price' => $unitPrice,
                'taxId' => $article['mainDetail']['taxId'],
            ];
    
            // Store the last valid taxId for potential use in shipping discount
            $lastTaxId = $article['mainDetail']['taxId'];
        }
    
        $shippingFeeDiscount = (float)str_replace(' EUR', '', $firstRow['ShippingFeePlatformDiscount']);
        if ($shippingFeeDiscount > 0) {
            $orderData['details'][] = [
                'articleNumber' => 'SHIPPING_DISCOUNT',
                'name' => 'Shipping Discount (TikTok)',
                'quantity' => 1,
                'price' => -$shippingFeeDiscount,
                'taxId' => $lastTaxId ?? 1, // Use last valid taxId or fallback to 1
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