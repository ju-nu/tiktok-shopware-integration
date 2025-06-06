<?php

namespace TikTokShopwareSync;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Config
{
    public function getLogger(): Logger
    {
        $logger = new Logger('tiktok_shopware_sync');
        $logger->pushHandler(new StreamHandler($_ENV['APP_LOG_PATH'], Logger::DEBUG));
        return $logger;
    }

    public function getShopwareConfig(): array
    {
        return [
            'api_url' => $_ENV['SHOPWARE_API_URL'],
            'username' => $_ENV['SHOPWARE_API_USERNAME'],
            'api_key' => $_ENV['SHOPWARE_API_KEY'],
            'payment_method_id' => (int)$_ENV['SHOPWARE_PAYMENT_METHOD_ID'],
            'country_id' => (int)$_ENV['SHOPWARE_COUNTRY_ID'],
            'shipping_method_id' => (int)$_ENV['SHOPWARE_SHIPPING_METHOD_ID'],
        ];
    }

    public function getQueuePath(): string
    {
        return $_ENV['APP_QUEUE_PATH'];
    }
}