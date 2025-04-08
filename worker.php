<?php

require __DIR__ . '/vendor/autoload.php';

use App\Workers\CsvWorker;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$worker = new CsvWorker();
$worker->run();