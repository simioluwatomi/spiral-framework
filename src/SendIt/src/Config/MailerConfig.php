<?php

declare(strict_types=1);

namespace Spiral\SendIt\Config;

use Spiral\Core\InjectableConfig;

final class MailerConfig extends InjectableConfig
{
    public const CONFIG = 'mailer';

    protected array $config = [
        'dsn' => '',
        'from' => '',
        'queue' => null,
        'queueConnection' => null,
    ];

    public function getDSN(): string
    {
        return $this->config['dsn'];
    }

    public function getFromAddress(): string
    {
        return $this->config['from'];
    }

    public function getQueue(): ?string
    {
        return $this->config['queue'] ?? null;
    }

    public function getQueueConnection(): ?string
    {
        return $this->config['queueConnection'] ?? null;
    }
}
