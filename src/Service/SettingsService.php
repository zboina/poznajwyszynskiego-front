<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class SettingsService
{
    private ?array $cache = null;

    private const DEFAULTS = [
        'frontend_loader' => 'off',
        'frontend_font' => 'cormorant',
        'frontend_layout' => 'default',
        'demo_enabled' => '0',
        'demo_password' => '',
    ];

    public function __construct(private Connection $connection) {}

    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = self::DEFAULTS;
            try {
                $rows = $this->connection->fetchAllAssociative(
                    "SELECT \"key\", value FROM setting WHERE \"key\" LIKE 'frontend_%' OR \"key\" LIKE 'demo_%'"
                );
                foreach ($rows as $row) {
                    $this->cache[$row['key']] = $row['value'];
                }
            } catch (\Throwable) {
                // Table might not exist yet
            }
        }

        return $this->cache;
    }

    public function getFrontendDefaults(): array
    {
        $all = $this->all();
        return [
            'loader' => $all['frontend_loader'] ?? 'off',
            'font' => $all['frontend_font'] ?? 'cormorant',
            'layout' => $all['frontend_layout'] ?? 'default',
            'demo' => (bool) ($all['demo_enabled'] ?? false),
        ];
    }

    public function isDemoEnabled(): bool
    {
        return (bool) $this->get('demo_enabled');
    }

    public function getDemoPassword(): string
    {
        return $this->get('demo_password') ?? '';
    }
}
