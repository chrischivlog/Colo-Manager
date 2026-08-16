<?php

declare(strict_types=1);

namespace ColoManager\Database;

use ColoManager\Config;
use MongoDB\Client;
use MongoDB\Database;

/** Hält genau einen MongoDB-Client und stellt die konfigurierte Datenbank bereit. */
final class MongoConnection
{
    private readonly Client $client;
    private readonly Database $database;

    public function __construct(Config $config)
    {
        $this->client = new Client($config->mongoUri, [], [
            'typeMap' => [
                'array' => 'array',
                'document' => 'array',
                'root' => 'array',
            ],
        ]);
        $this->database = $this->client->selectDatabase($config->mongoDatabase);
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function ping(): bool
    {
        $result = $this->database->command(['ping' => 1])->toArray();

        return ($result[0]['ok'] ?? 0) === 1.0 || ($result[0]['ok'] ?? 0) === 1;
    }
}
