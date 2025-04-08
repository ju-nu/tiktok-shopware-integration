<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\UploadController;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$controller = new UploadController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->handleUpload();
} else {
    $controller->showForm();
}