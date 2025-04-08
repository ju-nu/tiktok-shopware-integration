<?php

namespace TikTokShopwareSync;

use Psr\Log\LoggerInterface;

class Worker
{
    private Config $config;
    private CsvProcessor $processor;
    private LoggerInterface $logger;

    public function __construct()
    {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
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
                $this->logger->debug("No files in queue, sleeping for 5 seconds");
                sleep(5);
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