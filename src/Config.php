<?php

namespace HsDeduper;

use Dotenv\Dotenv;
use RuntimeException;

class Config
{
    private static $loaded = false;

    public function __construct()
    {
        if (!self::$loaded) {
            self::load();
        }
    }

    private static function load()
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        $dotenv->required(['HUBSPOT_API_KEY'])->notEmpty();
        self::$loaded = true;
    }

    public function getHubspotApiKey(): string
    {
        $apiKey = $_ENV['HUBSPOT_API_KEY'] ?? null;
        
        if (!$apiKey) {
            throw new RuntimeException('HUBSPOT_API_KEY is not set in environment variables');
        }
        
        return $apiKey;
    }
}
