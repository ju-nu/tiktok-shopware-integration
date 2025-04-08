<?php

namespace TikTokShopwareSync;

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv; // Import the correct Dotenv class
use Psr\Log\LoggerInterface;

class Worker
{
    private Config $config;
    private CsvProcessor $processor;
    private LoggerInterface $logger;

    public function __construct()
    {
        // Use the imported Dotenv class
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();

        $this->config = new Config();
        $this->logger = $this->config->getLogger();
        $shopwareClient = new ShopwareClient($this->config->getShopwareConfig(), $this->logger);
        $this->processor = new CsvProcessor($shopwareClient, $this->logger);
    }

    public function run(): void
    {
        $this->logger->info("Worker started");

        while (true) {
            $queuePath = $this->config->getQueuePath();
            $files = glob("$queuePath/*.csv");

            if (empty($files)) {
                $this->logger->debug("No files in queue, sleeping for 300 seconds");
                sleep(300);
                continue;
            }

            foreach ($files as $file) {
                $this->processor->processFile($file);
            }
        }
    }
}

if (php_sapi_name() === 'cli') {
    $worker = new Worker();
    $worker->run();
}