<?php

namespace App\Controllers;

use App\Services\QueueManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class UploadController
{
    private Logger $logger;
    private QueueManager $queueManager;

    public function __construct()
    {
        $this->logger = new Logger('upload');
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../Logs/app.log', Logger::INFO));
        $this->queueManager = new QueueManager();
    }

    public function showForm(): void
    {
        include __DIR__ . '/../../templates/upload_form.php';
    }

    public function handleUpload(): void
    {
        if (!isset($_FILES['csv_files'])) {
            $this->logger->error('No files uploaded');
            die('No files uploaded.');
        }

        $files = $_FILES['csv_files'];
        foreach ($files['tmp_name'] as $index => $tmpName) {
            if ($files['error'][$index] === UPLOAD_ERR_OK) {
                $filename = uniqid('csv_') . '.csv';
                $this->queueManager->enqueue($tmpName, $filename);
                $this->logger->info("Queued file: {$filename}");
            } else {
                $this->logger->error("Upload error for file {$files['name'][$index]}: " . $files['error'][$index]);
            }
        }

        include __DIR__ . '/../../templates/upload_success.php';
    }
}