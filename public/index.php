<?php

require_once __DIR__ . '/../vendor/autoload.php';

use TikTokShopwareSync\Config;
use Psr\Log\LoggerInterface;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$config = new Config();
$logger = $config->getLogger();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_files'])) {
    $queuePath = $_ENV['APP_QUEUE_PATH'];
    if (!is_dir($queuePath)) {
        mkdir($queuePath, 0777, true);
    }

    foreach ($_FILES['csv_files']['tmp_name'] as $index => $tmpName) {
        if ($_FILES['csv_files']['error'][$index] === UPLOAD_ERR_OK) {
            $fileName = uniqid('csv_') . '.csv';
            $destination = $queuePath . '/' . $fileName;
            move_uploaded_file($tmpName, $destination);
            $logger->info("File uploaded and queued: $fileName");
        } else {
            $logger->error("Upload error for file $index: " . $_FILES['csv_files']['error'][$index]);
        }
    }

    header('Location: /?success=1');
    exit;
}

if (isset($_GET['success'])) {
    include __DIR__ . '/../templates/success.php';
} else {
    include __DIR__ . '/../templates/upload.php';
}