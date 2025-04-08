<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$logFile = $_ENV['APP_LOG_PATH'];

header('Content-Type: text/plain');

if (!file_exists($logFile)) {
    echo "Log file not found.";
    exit;
}

// Read last 200 lines (you can tweak this)
$lines = tailFile($logFile, 200);
echo implode("\n", $lines);

// --- Helper function ---
function tailFile($filepath, $lines = 100): array {
    $buffer = [];
    $fp = fopen($filepath, 'r');
    if (!$fp) return [];

    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $line = '';

    while ($pos > 0 && count($buffer) < $lines) {
        $pos--;
        fseek($fp, $pos);
        $char = fgetc($fp);
        if ($char === "\n" && $line !== '') {
            array_unshift($buffer, strrev($line));
            $line = '';
        } else {
            $line .= $char;
        }
    }

    if ($line !== '') {
        array_unshift($buffer, strrev($line));
    }

    fclose($fp);
    return $buffer;
}
