<?php

namespace App\Core;

/**
 * The Config class provides a simple configuration management system.
 *
 * @package App\Core
 */
class Config
{
    /**
     * The array of configuration values.
     *
     * @var array
     */
    private static array $config = [];

    /**
     * Load the configuration values from the file.
     *
     * @param string $path
     */
    public static function set_config(string $path)
    {
        if (file_exists($path)) {
            self::$config = require $path;
        }
    }

    /**
     * Get the entire configuration array.
     *
     * @return array
     */
    public static function all(): array
    {
        return self::$config;
    }

    /**
     * Set multiple configuration values at runtime.
     *
     * @param array $values
     */
    public static function set_many(array $values)
    {
        self::$config = array_merge(self::$config, $values);
    }

    /**
     * Get the value of a configuration key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * Check if a configuration key exists.
     *
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return isset(self::$config[$key]);
    }

    /**
     * Set a configuration value at runtime.
     *
     * @param string $key
     * @param mixed $value
     */
    public static function set(string $key, $value)
    {
        self::$config[$key] = $value;
    }
}