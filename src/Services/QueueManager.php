<?php

namespace App\Services;

use App\Config\Config;

class QueueManager
{
    private string $queueDir;

    public function __construct()
    {
        $this->queueDir = Config::get('QUEUE_DIR', __DIR__ . '/../../var/queue');
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0777, true);
        }
    }

    public function enqueue(string $tmpPath, string $filename): void
    {
        $destPath = $this->queueDir . '/' . $filename;
        move_uploaded_file($tmpPath, $destPath);
    }

    public function dequeue(): ?string
    {
        $files = glob($this->queueDir . '/*.csv');
        return !empty($files) ? array_shift($files) : null;
    }
}