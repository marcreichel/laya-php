<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Laravel;

use Illuminate\Console\Command;
use MarcReichel\Laya\Exceptions\LayaException;
use MarcReichel\Laya\Laya;

/**
 * `php artisan laya:health`: fails (exit 1) when laya-serve is unreachable or unhealthy, for deploy checks.
 */
final class HealthCommand extends Command
{
    protected $signature = 'laya:health';

    protected $description = 'Check that laya-serve is reachable and healthy';

    public function handle(Laya $laya): int
    {
        try {
            $health = $laya->health();
        } catch (LayaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Status', $health->ok ? 'ok' : 'unhealthy');
        $this->components->twoColumnDetail('Device', $health->device);
        $this->components->twoColumnDetail('Loaded', implode(', ', $health->loaded) ?: 'none');

        return $health->ok ? self::SUCCESS : self::FAILURE;
    }
}
