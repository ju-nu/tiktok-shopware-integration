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

            $orderId = trim(preg_replace('/[\p{C}\s]+/u', '', $row['OrderID']));
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

        // Preprocess address fields to handle house numbers in parentheses
        $address = $this->extractStreetAndNumber($firstRow['StreetName'] ?? 'Unknown', $firstRow['HouseNameorNumber'] ?? '');
        $firstRow['StreetName'] = $address['street'];
        $firstRow['HouseNameorNumber'] = $address['number'];

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
            'Zipcode' => '00000',
            'City' => 'Unknown',
            'SellerSKU' => '',
            'SKUUnitOriginalPrice' => '0,00 EUR',
            'SKUSubtotalAfterDiscount' => '0,00 EUR',
            'SKUSubtotalBeforeDiscount' => '0,00 EUR',
            'SKUSellerDiscount' => '0,00 EUR',
            'SKUPlatformDiscount' => '0,00 EUR',
            'Quantity' => '1',
            'ProductName' => 'Unknown Product',
            'ShippingFeePlatformDiscount' => '0,00 EUR',
            'CreatedTime' => date('Y-m-d H:i:s'), // Default to now if missing
            'PaidTime' => date('Y-m-d H:i:s'), // Default to now if missing
            'Phone#' => '', // Default to empty string if missing
        ];
        foreach ($requiredFields as $key => $default) {
            if (!isset($firstRow[$key]) || empty(trim($firstRow[$key]))) {
                $this->logger->warning("Missing or empty '$key' in order $orderId, using default: '$default'");
                $firstRow[$key] = $default;
            }
        }

        // Use OriginalShippingFee as invoiceShipping
        $invoiceShipping = (float)str_replace([' EUR', ','], ['', '.'], $firstRow['OriginalShippingFee']);
        $invoiceAmount = (float)str_replace([' EUR', ','], ['', '.'], $firstRow['OrderAmount']);

        // Convert CreatedTime and PaidTime to Shopware's expected format (Y-m-d H:i:s)
        $orderTime = date('Y-m-d H:i:s', strtotime($firstRow['CreatedTime']));
        $clearedDate = date('Y-m-d H:i:s', strtotime($firstRow['PaidTime']));

        // Get shopId from config, default to 1
        $shopId = $this->shopwareClient->getConfig()['shop_id'] ?? 1;

        $phoneNumber = str_replace('(+49)', '0', $firstRow['Phone#'] ?? '');

        $orderData = [
            'customerId' => $customerId,
            'paymentId' => $this->shopwareClient->getConfig()['payment_method_id'],
            'dispatchId' => $this->shopwareClient->getConfig()['shipping_method_id'],
            'shopId' => $shopId,
            'orderStatusId' => 0, // Offen
            'paymentStatusId' => 12, // "Komplett bezahlt"
            'invoiceAmount' => $invoiceAmount,
            'invoiceAmountNet' => 0, // Placeholder, calculated below
            'invoiceShipping' => $invoiceShipping,
            'invoiceShippingNet' => 0, // Placeholder, calculated below
            'net' => 0, // Gross pricing
            'taxFree' => 0, // Not tax-exempt
            'languageIso' => 1,
            'referer' => 'JUNU Importer',
            'currency' => 'EUR',
            'currencyFactor' => 1.0,
            'orderTime' => $orderTime,
            'clearedDate' => $clearedDate,
            'internalComment' => "TikTok Bestellung-ID: $orderId",
            'attribute' => [
                'tiktokOrderId' => $orderId,
            ],
            'billing' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'street' => trim($firstRow['StreetName'] . ' ' . $firstRow['HouseNameorNumber']),
                'zipcode' => $firstRow['Zipcode'],
                'city' => $firstRow['City'],
                'countryId' => $this->shopwareClient->getConfig()['country_id'],
                'phone' => $phoneNumber,
            ],
            'shipping' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'street' => trim($firstRow['StreetName'] . ' ' . $firstRow['HouseNameorNumber']),
                'zipcode' => $firstRow['Zipcode'],
                'city' => $firstRow['City'],
                'countryId' => $this->shopwareClient->getConfig()['country_id'],
            ],
            'details' => [],
        ];

        $lastTaxRate = 0.00;
        $lastTaxId = 5;
        $netTotal = 0.0;
        $shippingNet = 0.0;

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
            $taxId = $article['tax']['id'] ?? null;
            if (!$taxRate || !$taxId) {
                $this->logger->warning("No tax rate or ID found for SKU $sellerSku in order $orderId, using fallback tax rate 7.00 and tax ID 4");
                $taxRate = 7.00; // Default to 7% VAT
                $taxId = 4; // Default to tax ID 4
            }

            if ((float)$taxRate > (float)$lastTaxRate) {
                $lastTaxRate = (float)$taxRate;
                $lastTaxId = $taxId;
            }

            $unitPrice = (float)str_replace([' EUR', ','], ['', '.'], $row['SKUUnitOriginalPrice']);
            $quantity = (int)$row['Quantity'];
            $orderData['details'][] = [
                'articleId' => $article['id'],
                'articleNumber' => $sellerSku,
                'articleName' => $row['ProductName'],
                'quantity' => $quantity,
                'price' => $unitPrice,
                'taxId' => $taxId,
                'taxRate' => (float)$taxRate,
                'statusId' => 0, // Open
            ];

            $lineNet = ($unitPrice * $quantity) / (1 + ($taxRate / 100));
            $netTotal += $lineNet;

            // Add Seller Discount as a separate line item
            $sellerDiscount = (float)str_replace([' EUR', ','], ['', '.'], $row['SKUSellerDiscount']);
            if ($sellerDiscount != 0) {
                $orderData['details'][] = [
                    'articleId' => 0,
                    'articleNumber' => 'SELLER_DISCOUNT_' . $sellerSku,
                    'articleName' => "Verkäuferrabatt auf Artikel $sellerSku",
                    'quantity' => 1,
                    'price' => -$sellerDiscount, // Negative to subtract
                    'taxId' => $taxId,
                    'taxRate' => (float)$taxRate,
                    'mode' => 4,
                    'statusId' => 0, // Open
                ];
                $discountNet = (-$sellerDiscount) / (1 + ($taxRate / 100));
                $netTotal += $discountNet;
            }

            // Add Platform Discount as a separate line item
            $platformDiscount = (float)str_replace([' EUR', ','], ['', '.'], $row['SKUPlatformDiscount']);
            if ($platformDiscount != 0) {
                $orderData['details'][] = [
                    'articleId' => 0,
                    'articleNumber' => 'PLATFORM_DISCOUNT_' . $sellerSku,
                    'articleName' => "TikTok Shop-Rabatte auf Artikel $sellerSku",
                    'quantity' => 1,
                    'price' => -$platformDiscount, // Negative to subtract
                    'taxId' => $taxId,
                    'taxRate' => (float)$taxRate,
                    'mode' => 4,
                    'statusId' => 0, // Open
                ];
                $discountNet = (-$platformDiscount) / (1 + ($taxRate / 100));
                $netTotal += $discountNet;
            }
        }

        // Add Shipping Fee Platform Discount as a tax-free line item
        $shippingFeePlatformDiscount = (float)str_replace([' EUR', ','], ['', '.'], $firstRow['ShippingFeePlatformDiscount']);
        if ($shippingFeePlatformDiscount != 0) {
            $orderData['details'][] = [
                'articleId' => 0,
                'articleNumber' => 'SHIPPING_PLATFORM_DISCOUNT',
                'articleName' => 'TikTok Shop-Versandgebühr-Rabatte',
                'quantity' => 1,
                'price' => -$shippingFeePlatformDiscount, // Negative to subtract
                'taxId' => $lastTaxId,
                'taxRate' => (float)$lastTaxRate,
                'mode' => 4,
                'statusId' => 0, // Open
            ];
            // Calculate net value of the shipping fee based on tax
            $shippingTaxRate = $lastTaxRate > 0 ? $lastTaxRate : 19.0; // fallback to 19% if nothing found
            $shippingNet = $invoiceShipping / (1 + ($shippingTaxRate / 100));
            $orderData['invoiceShippingNet'] = round($shippingNet, 2);
        }

        // Calculate net values using the last tax rate (for articles, not shipping discount)
        $orderData['invoiceAmountNet'] = round($netTotal, 2);
        $orderData['invoiceShippingNet'] = round($shippingNet, 2);

        try {
            $this->logger->debug("Order data being sent: " . json_encode($orderData));
            $response = $this->shopwareClient->createOrder($orderData);
            $orderIdResponse = $response['data']['id'] ?? 'unknown'; // Safely access nested id
            $this->logger->info("Order created successfully: Shopware Order ID $orderIdResponse");
        } catch (\Exception $e) {
            $this->logger->error("Failed to create order $orderId: " . $e->getMessage());
        }
    }

    private function checkExistingOrder(string $tiktokOrderId): ?array
    {
        $this->logger->debug("Checking for existing TikTok order ID: '$tiktokOrderId'");

        $orderId = trim(preg_replace('/[\p{C}\s]+/u', '', $tiktokOrderId));

        try {
            $response = $this->shopwareClient->get('orders', [
                'query' => [
                    'filter' => [
                        [
                            'property' => 'attribute.tiktokOrderId',
                            'expression' => '=',
                            'value' => $orderId,
                        ],
                    ],
                ],
            ]);

            $body = $response->getBody()->getContents();
            $this->logger->debug("Shopware order search response for TikTok ID $orderId: $body");

            $data = json_decode($body, true);
            return $data['data'][0] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning("Failed to check existing order for TikTok ID $orderId: " . $e->getMessage());
            return null;
        }
    }

    private function generateEmailFromUsername(string $username): string
    {
        return 'tikt_' . uniqid() . '@egal.de';
    }

    private function createGuestCustomer(array $row): ?int
    {
        $recipient = $this->splitRecipientName($row['Recipient'] ?? 'Unknown Unknown');
        $email = $this->generateEmailFromUsername($row['BuyerUsername']);
        $groupKey = 'TK';

        // Preprocess address fields to handle house numbers in parentheses
        $address = $this->extractStreetAndNumber($row['StreetName'] ?? 'Unknown', $row['HouseNameorNumber'] ?? '');
        $row['StreetName'] = $address['street'];
        $row['HouseNameorNumber'] = $address['number'];

        $phoneNumber = str_replace('(+49)', '0', $row['Phone#'] ?? '');

        // Create new guest customer
        $customerData = [
            'email' => $email,
            'active' => true,
            'groupKey' => $groupKey,
            'firstname' => $recipient['firstName'],
            'lastname' => $recipient['lastName'],
            'salutation' => 'mr',
            'paymentId' => $this->shopwareClient->getConfig()['payment_method_id'],
            'password' => bin2hex(random_bytes(8)),
            'billing' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'salutation' => 'mr',
                'street' => trim($row['StreetName'] . ' ' . $row['HouseNameorNumber']),
                'zipcode' => $row['Zipcode'] ?? '00000',
                'city' => $row['City'] ?? 'Unknown',
                'country' => $this->shopwareClient->getConfig()['country_id'],
                'phone' => $phoneNumber,
            ],
            'shipping' => [
                'firstName' => $recipient['firstName'],
                'lastName' => $recipient['lastName'],
                'salutation' => 'mr',
                'street' => trim($row['StreetName'] . ' ' . $row['HouseNameorNumber']),
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

    private function extractStreetAndNumber(string $streetName, string $houseNumber): array
    {
        // Default return values
        $result = [
            'street' => trim($streetName) ?: 'Unknown',
            'number' => trim($houseNumber) ?: '',
        ];
    
        // If streetName is empty or just whitespace, use defaults and log
        if (empty(trim($streetName))) {
            $this->logger->warning("StreetName is empty, using default: street='Unknown', number='$houseNumber'");
            return $result;
        }
    
        // Pattern to match house numbers in various formats:
        // - "Street 12", "Street 12a", "Street 12-14", "Street 12/a"
        // - "Street(12)", "Street (12a)"
        // - "Street12", "Street12a" (no space)
        $pattern = '/^(.*?)(?:\s+|\(|)([\d]+[a-zA-Z]?[-\/]?[a-zA-Z\d]*)(?:\)|)?$/i';
    
        // Try to extract house number from StreetName
        if (preg_match($pattern, trim($streetName), $matches)) {
            $cleanStreet = trim($matches[1]);
            $extractedNumber = trim($matches[2]);
    
            // Ensure the extracted street is not empty
            if (!empty($cleanStreet)) {
                $result['street'] = $cleanStreet;
                $result['number'] = $extractedNumber;
                $this->logger->debug("Extracted from StreetName '$streetName': Street='$cleanStreet', Number='$extractedNumber'");
                return $result;
            }
        }
    
        // If no number was found in StreetName, use StreetName as street and HouseNameorNumber as number
        $result['street'] = trim($streetName);
    
        // Validate HouseNameorNumber: ensure it looks like a house number (e.g., "12", "12a", "12-14", "12/a")
        if (preg_match('/^[\d]+[a-zA-Z]?[-\/]?[a-zA-Z\d]*$/', trim($houseNumber))) {
            $result['number'] = trim($houseNumber);
            $this->logger->debug("Using StreetName '$streetName' as street, HouseNameorNumber '$houseNumber' as number");
        } elseif (!empty(trim($houseNumber)) && trim($houseNumber) !== trim($streetName)) {
            // If HouseNameorNumber is not a valid number and not redundant, log a warning
            $this->logger->warning("HouseNameorNumber '$houseNumber' is not a valid house number for StreetName '$streetName', ignoring");
            $result['number'] = '';
        }
    
        return $result;
    }
}
