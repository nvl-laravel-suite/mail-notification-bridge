<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge;

use InvalidArgumentException;

/** Named service-client connections with isolated credentials and signing configuration.
 * @api
 */
final class MailNotificationsManager
{
    /** @var array<string, MailNotificationsClient> */
    private array $connections = [];

    /** @param array<string, mixed> $config */
    public function __construct(#[\SensitiveParameter] private readonly array $config) {}

    /** @return array<string, mixed> */
    public function configuration(string $name = 'default'): array
    {
        $name = $this->resolveName($name);
        $connections = $this->config['connections'] ?? [];
        if (! is_array($connections)) {
            throw new InvalidArgumentException('Mail Notifications connections must be a map.');
        }
        if (array_key_exists($name, $connections)) {
            if (! is_array($connections[$name])) {
                throw new InvalidArgumentException('The Mail Notifications connection must be an array.');
            }

            $profile = [];
            foreach ($connections[$name] as $key => $value) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException('Connection configuration keys must be strings.');
                }
                $profile[$key] = $value;
            }

            return ['connect_timeout' => $this->config['connect_timeout'] ?? 5, 'timeout' => $this->config['timeout'] ?? 30,
                'client_id' => null, 'webhook_secrets' => [], ...$profile];
        }
        if ($name !== 'default') {
            throw new InvalidArgumentException('Unknown Mail Notifications connection: '.$name);
        }

        return ['base_url' => $this->config['base_url'] ?? null, 'token' => $this->config['token'] ?? null,
            'connect_timeout' => $this->config['connect_timeout'] ?? 5, 'timeout' => $this->config['timeout'] ?? 30,
            'client_id' => $this->config['client_id'] ?? null, 'webhook_secrets' => $this->config['webhook_secrets'] ?? []];
    }

    public function connection(string $name = 'default'): MailNotificationsClient
    {
        $resolved = $this->resolveName($name);
        if (! isset($this->connections[$resolved])) {
            $configuration = $this->configuration($name);
            $url = $configuration['base_url'] ?? '';
            $token = $configuration['token'] ?? '';
            $connectTimeout = filter_var($configuration['connect_timeout'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $timeout = filter_var($configuration['timeout'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_string($url) || ! is_string($token) || $connectTimeout === false || $timeout === false) {
                throw new InvalidArgumentException('Connection URL/token must be strings and timeouts must be positive integers.');
            }
            $this->connections[$resolved] = new MailNotificationsClient($url, $token, $connectTimeout, $timeout);
        }

        return $this->connections[$resolved];
    }

    public function connectionName(string $name = 'default'): string
    {
        $this->configuration($name);

        return $this->resolveName($name);
    }

    private function resolveName(string $name): string
    {
        $resolved = $name === 'default' ? ($this->config['default'] ?? 'default') : $name;
        if (! is_string($resolved) || trim($resolved) === '') {
            throw new InvalidArgumentException('The default API connection must have a non-empty name.');
        }

        return $resolved;
    }
}
