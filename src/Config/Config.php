<?php

namespace App\Config;

class Config
{
    public static function get(string $key, $default = null): string|array|null
    {
        return $_ENV[$key] ?? $default;
    }
}