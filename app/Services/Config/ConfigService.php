<?php

namespace App\Services\Config;

use InvalidArgumentException;

class ConfigService
{
    public function get(string $key): mixed
    {
        $value = config($key);
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Missing required configuration value for key: {$key}");
        }
        return $value;
    }
}


