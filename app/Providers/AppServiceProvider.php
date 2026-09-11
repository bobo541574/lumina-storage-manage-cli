<?php

namespace App\Providers;

use App\Drivers\LocalStorageDriver;
use App\Drivers\RcloneStorageDriver;
use App\Services\StorageService;
use App\Services\TransferService;
use App\Services\VisibilityService;
use App\Support\RcloneProcess;
use App\Support\StorageLogger;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StorageLogger::class, fn (): StorageLogger => new StorageLogger(
            (string) config('storage.logs.path'),
            (int) config('storage.logs.max_files', 14),
            (string) config('storage.logs.level', 'info'),
        ));

        $this->app->singleton(RcloneProcess::class, fn (): RcloneProcess => new RcloneProcess(
            (string) config('storage.drivers.rclone.binary', 'rclone'),
            (int) config('storage.drivers.rclone.timeout', 3600),
        ));

        $this->app->bind(RcloneStorageDriver::class, fn (Application $app): RcloneStorageDriver => new RcloneStorageDriver(
            $app->make(RcloneProcess::class),
            $app->make(StorageLogger::class),
        ));

        $this->app->bind(LocalStorageDriver::class);

        $this->app->singleton(StorageService::class, fn (Application $app): StorageService => new StorageService(
            $app->make(RcloneStorageDriver::class),
            $app->make(LocalStorageDriver::class),
            $app->make('cache')->driver(),
            (int) config('storage.cache.ttl', 300),
        ));

        $this->app->singleton(TransferService::class, fn (Application $app): TransferService => new TransferService(
            $app->make(StorageService::class),
            $app->make(RcloneStorageDriver::class),
            $app->make(LocalStorageDriver::class),
        ));

        $this->app->singleton(VisibilityService::class, fn (Application $app): VisibilityService => new VisibilityService(
            $app->make(StorageService::class),
        ));
    }
}
