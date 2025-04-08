<?php

namespace App\Workers;

use App\Services\CsvProcessor;
use App\Services\QueueManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CsvWorker
{
    private QueueManager $queueManager;
    private CsvProcessor $csvProcessor;
    private Logger $logger;

    public function __construct()
    {
        $this->queueManager = new QueueManager();
        $this->csvProcessor = new CsvProcessor();
        $this->logger = new Logger('worker');
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../../var/logs/worker.log', Logger::INFO));
    }

    public function run(): void
    {
        $this->logger->info('Worker started');
        while (true) {
            $file = $this->queueManager->dequeue();
            if ($file) {
                $this->csvProcessor->process($file);
            } else {
                $this->logger->info('No files in queue, sleeping...');
                sleep(5); // Wait before checking again
            }
        }
    }
}