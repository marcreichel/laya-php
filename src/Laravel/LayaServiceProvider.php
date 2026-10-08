<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Support\ServiceProvider;
use MarcReichel\Laya\Laya;

/**
 * Registers Laya as a singleton configured from config/laya.php. Auto-discovered by Laravel.
 */
final class LayaServiceProvider extends ServiceProvider
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
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/laya.php' => $this->app->configPath('laya.php'),
        ], 'laya-config');

        $this->commands([HealthCommand::class, TryCommand::class]);
    }
}
