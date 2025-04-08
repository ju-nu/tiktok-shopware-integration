<?php

namespace TikTokShopwareSync;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class Config
{
    public function getLogger(): Logger
    {
        $logger = new Logger('tiktok_shopware_sync');
        // Keep up to 7 daily log files by default
        $logger->pushHandler(new RotatingFileHandler(
            $_ENV['APP_LOG_PATH'], // base filename (e.g. /path/to/app.log)
            7,                     // keep up to 7 rotated files
            Logger::DEBUG,
            true,                  // bubble
            0644,                  // file permissions
            true,                  // use locking
            '',                    // empty date format = classic suffixes (.1, .2, .3)
        ));
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
