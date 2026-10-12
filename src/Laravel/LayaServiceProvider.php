<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Support\ServiceProvider;
use MarcReichel\Laya\Laya;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * Registers Laya as a singleton configured from config/laya.php. Auto-discovered by Laravel.
 */
class LayaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/laya.php', 'laya');

        $this->app->singleton(Laya::class, function (): Laya {
            $config = $this->app->make(Repository::class);
            $apiKey = $config->get('laya.api_key');
            $store = $config->get('laya.cache.store');
            $ttl = $config->get('laya.cache.ttl');

            return new Laya(
                $config->string('laya.url'),
                apiKey: is_string($apiKey) && $apiKey !== '' ? $apiKey : null,
                cache: is_string($store) && $store !== '' ? $this->app->make(CacheManager::class)->store($store) : null,
                cacheTtl: is_numeric($ttl) ? (int) $ttl : null,
                events: filter_var($config->get('laya.events.enabled'), FILTER_VALIDATE_BOOLEAN) ? new EventDispatcher($this->app) : null,
                includeState: filter_var($config->get('laya.events.include_state'), FILTER_VALIDATE_BOOLEAN),
                httpClient: $this->httpClient($config),
                batchSize: $config->integer('laya.batch_size'),
            );
        });
    }

    /**
     * A client from laya.http_client (a service id) or an explicit ClientInterface binding wins; otherwise
     * laya.timeout / laya.retries build a Symfony one; with none of them set, the SDK discovers a client.
     */
    private function httpClient(Repository $config): ?ClientInterface
    {
        $id = $config->get('laya.http_client');

        if (is_string($id) && $id !== '') {
            $client = $this->app->make($id);

            return $client instanceof ClientInterface ? $client : throw new RuntimeException("laya.http_client \"{$id}\" is not a Psr\\Http\\Client\\ClientInterface.");
        }

        if ($this->app->bound(ClientInterface::class)) {
            return $this->app->make(ClientInterface::class);
        }

        $timeout = $config->get('laya.timeout');
        $timeout = is_numeric($timeout) ? $timeout : null;
        $retries = $config->get('laya.retries');
        $retries = is_numeric($retries) ? (int) $retries : null;

        if ($timeout === null && ! $retries) {
            return null;
        }

        if (! $this->symfonyInstalled()) {
            throw new RuntimeException('laya.timeout and laya.retries need symfony/http-client (composer require symfony/http-client), or bind your own Psr\\Http\\Client\\ClientInterface.');
        }

        $client = HttpClient::create($timeout === null ? [] : ['timeout' => $timeout]);

        if ($retries > 0) {
            // Only 503 (laya-serve at its concurrency limit) and transport errors are retried.
            $client = new RetryableHttpClient($client, new GenericRetryStrategy([503]), $retries);
        }

        return new Psr18Client($client);
    }

    protected function symfonyInstalled(): bool
    {
        return class_exists(RetryableHttpClient::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/laya.php' => $this->app->configPath('laya.php'),
        ], 'laya-config');

        $this->commands([HealthCommand::class, TryCommand::class]);
    }
}
