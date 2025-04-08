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

        $headers = fgetcsv($handle, 0, ',');
        if ($headers === false || empty($headers)) {
            $this->logger->error("No headers found in file: $filePath");
            fclose($handle);
            return;
        }

        $this->logger->debug("Raw headers: " . implode(', ', $headers));
        $this->logger->debug("Raw first header bytes: " . bin2hex($headers[0]));

        $headers[0] = str_replace("\ufeff", '', $headers[0]);
        $headers = array_map(function ($header) {
            return preg_replace('/[\p{C}\s]+/u', '', trim($header));
        }, $headers);

        $this->logger->debug("Cleaned headers: " . implode(', ', $headers));
        $this->logger->debug("Cleaned first header bytes: " . bin2hex($headers[0]));

        if ($headers[0] !== 'OrderID') {
            $this->logger->error("Expected 'OrderID' as first header, found: '{$headers[0]}'");
            fclose($handle);
            return;
        }

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
    
        // Check if order already exists in Shopware by TikTok Order ID in internalComment
        $existingOrder = $this->checkExistingOrder($orderId);
        if ($existingOrder) {
            $this->logger->info("Order with TikTok ID $orderId already exists as Shopware Order ID {$existingOrder['id']}, skipping creation");
            return;
        }
    
        $firstRow = $orderRows[0];
        $recipient = $this->splitRecipientName($firstRow['Recipient'] ?? 'Unknown Unknown');
    
        // Create guest customer
        $customerId = $this->createGuestCustomer($firstRow);
        if (!$customerId) {
            $this->logger->error("Failed to create guest customer for order $orderId, skipping");
            return;
        }
    
        // Validate required fields with defaults
        $requiredFields = [
            'OrderAmount' => '0,00 EUR',
            'ShippingFeeAfterDiscount' => '0,00 EUR',
            'OriginalShippingFee' => '0,00 EUR',
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
    
        // Use ShippingFeeAfterDiscount directly as invoiceShipping
        $invoiceShipping = (float)str_replace(' EUR', '', $firstRow['ShippingFeeAfterDiscount']);
        $invoiceAmount = (float)str_replace(' EUR', '', $firstRow['OrderAmount']);
    
        // Get shopId from config, default to 1
        $shopId = $this->shopwareClient->getConfig()['shop_id'] ?? 1;
    
        $orderData = [
            'number' => $orderId,
            'customerId' => $customerId,
            'paymentId' => $this->shopwareClient->getConfig()['payment_method_id'],
            'dispatchId' => $this->shopwareClient->getConfig()['shipping_method_id'],
            'shopId' => $shopId, // Added shopId
            'orderStatusId' => 5, // "Zur Lieferung bereit"
            'paymentStatusId' => 12, // "Komplett bezahlt"
            'invoiceAmount' => $invoiceAmount,
            'invoiceAmountNet' => 0, // Placeholder, calculated below
            'invoiceShipping' => $invoiceShipping,
            'invoiceShippingNet' => 0, // Placeholder, calculated below
            'net' => false, // Gross pricing (includes VAT)
            'taxFree' => false, // Not tax-exempt
            'currency' => 'EUR',
            'currencyFactor' => 1.0,
            'internalComment' => "TikTok Order ID: $orderId",
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
            'details' => [],
        ];
    
        $lastTaxRate = null;
    
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
    
            $taxRate = $article['tax']['tax'] ?? null;
            if (!$taxRate) {
                $this->logger->warning("No tax rate found for SKU $sellerSku in order $orderId, using fallback tax rate 19.00");
                $taxRate = 19.00; // Default to 19% VAT
            }
    
            $unitPrice = (float)str_replace(' EUR', '', $row['SKUUnitOriginalPrice']);
            $quantity = (int)$row['Quantity'];
            $orderData['details'][] = [
                'articleId' => $article['id'],
                'articleNumber' => $sellerSku,
                'articleName' => $row['ProductName'],
                'quantity' => $quantity,
                'price' => $unitPrice,
                'taxId' => $article['tax']['id'] ?? 1,
                'taxRate' => (float)$taxRate,
                'statusId' => 0, // Open
            ];
    
            $lastTaxRate = (float)$taxRate; // Store last tax rate for net calculations
        }
    
        // Calculate net values using the last tax rate
        $taxFactor = 1 + ($lastTaxRate / 100); // e.g., 1.19 for 19% VAT
        $orderData['invoiceAmountNet'] = round($invoiceAmount / $taxFactor, 2);
        $orderData['invoiceShippingNet'] = round($invoiceShipping / $taxFactor, 2);
    
        try {
            $this->logger->debug("Order data being sent: " . json_encode($orderData));
            $response = $this->shopwareClient->createOrder($orderData);
            $this->logger->info("Order created successfully: Shopware Order ID {$response['id']}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to create order $orderId: " . $e->getMessage());
        }
    }

    private function checkExistingOrder(string $tiktokOrderId): ?array
    {
        try {
            $response = $this->shopwareClient->get("orders?filter[0][property]=internalComment&filter[0][value]=TikTok Order ID: $tiktokOrderId");
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['data'][0] ?? null; // Return first match or null if not found
        } catch (\Exception $e) {
            $this->logger->warning("Failed to check existing order for TikTok ID $tiktokOrderId: " . $e->getMessage());
            return null; // Assume not found if check fails
        }
    }

    private function createGuestCustomer(array $row): ?int
    {
        $recipient = $this->splitRecipientName($row['Recipient'] ?? 'Unknown Unknown');
        $email = $row['Email'] ?? 'guest_' . uniqid() . '@example.com';
        $groupKey = 'TK';

        // Check if customer exists
        try {
            $checkResponse = $this->shopwareClient->get('customers', [
                'query' => [
                    'filter' => [
                        ['property' => 'email', 'value' => $email],
                        ['property' => 'groupKey', 'value' => $groupKey],
                    ],
                ],
            ]);

            $existing = json_decode($checkResponse->getBody()->getContents(), true);
            if (isset($existing['data']) && !empty($existing['data'])) {
                foreach ($existing['data'] as $customer) {
                    if ($customer['email'] === $email && $customer['groupKey'] === $groupKey) {
                        $existingId = $customer['id'];
                        $this->logger->info("Existing customer found for email $email: ID $existingId");
                        return $existingId;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to check for existing customer for $email: " . $e->getMessage());
        }

        // Create new guest customer if not found
        $customerData = [
            'email' => $email,
            'active' => true,
            'groupKey' => $groupKey,
            'firstname' => $recipient['firstName'],
            'lastname' => $recipient['lastName'],
            'salutation' => 'mr',
            'password' => bin2hex(random_bytes(8)),
            'billing' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'salutation' => 'mr',
                'street' => $row['StreetName'] ?? 'Unknown',
                'streetNumber' => $row['HouseNameorNumber'] ?? '',
                'zipcode' => $row['Zipcode'] ?? '00000',
                'city' => $row['City'] ?? 'Unknown',
                'country' => $this->shopwareClient->getConfig()['country_id'],
            ],
            'shipping' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'salutation' => 'mr',
                'street' => $row['StreetName'] ?? 'Unknown',
                'streetNumber' => $row['HouseNameorNumber'] ?? '',
                'zipcode' => $row['Zipcode'] ?? '00000',
                'city' => $row['City'] ?? 'Unknown',
                'country' => $this->shopwareClient->getConfig()['country_id'],
            ],
        ];

        try {
            $response = $this->shopwareClient->post('customers', ['json' => $customerData]);
            $data = json_decode($response->getBody()->getContents(), true);
            $this->logger->info("Guest customer created for email $email: Customer ID {$data['data']['id']}");
            return $data['data']['id'];
        } catch (\Exception $e) {
            $this->logger->error("Failed to create guest customer for order {$row['OrderID']}: " . $e->getMessage());
            return null;
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
