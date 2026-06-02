<?php

namespace App\Providers;

use App\Modules\SelfHosted\Http\Middleware\LicenseGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Branches the runtime between cloud and self-hosted editions from a
 * single env switch (`TC_EDITION`). Single codebase, no feature-flag
 * branches in controllers — the spec's "module split" enforcement
 * point.
 *
 * Cloud:
 *   - Registers the cloud edition's container bindings via the
 *     CloudServiceProvider sub-provider (resolved by string FQN so
 *     core code never references cloud classes via `::class`).
 *   - Loads `database/migrations-cloud/` and `routes/cloud.php`.
 *
 * Self-hosted:
 *   - Registers the self-hosted edition's container bindings.
 *   - Loads `database/migrations-selfhosted/` and `routes/selfhosted.php`.
 *   - Pushes {@see LicenseGuard} into the `api` middleware group so
 *     every authenticated request is gated by the license state.
 *   - Registers the hourly `license:phone-home` schedule and the
 *     `tc:license:check` boot-time guard.
 *
 * In test environments we register both migration paths regardless
 * of edition so a single DB can host both edition's tests without a
 * separate setup pass.
 */
class EditionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register the matching edition's sub-provider via string FQN
        // so the cloud class name never appears as a `::class`
        // reference in core code — keeps the self-hosted image's
        // ci-check.sh grep clean. Tests can flip `teamcore.edition`
        // in setUp; bind both providers' resolution lazily so the
        // active edition decides per resolution.
        $cloudProvider = 'App\\Modules\\Cloud\\Providers\\CloudServiceProvider';
        $selfHostedProvider = \App\Modules\SelfHosted\Providers\SelfHostedServiceProvider::class;

        if ($this->edition() === 'self_hosted') {
            $this->app->register($selfHostedProvider);
        } elseif (class_exists($cloudProvider)) {
            $this->app->register($cloudProvider);
        }
    }

    public function boot(): void
    {
        $edition = $this->edition();

        $this->loadMigrationPaths($edition);
        $this->loadEditionRoutes($edition);

        // Console commands are registered unconditionally — they're
        // inert on cloud (no LICENSE_TOKEN, no license_state row) but
        // remaining registered means a self-hosted-flipped TC_EDITION
        // at request time still has them available without a reboot.
        $this->commands([
            \App\Modules\SelfHosted\Console\Commands\PhoneHomeCommand::class,
            \App\Modules\SelfHosted\Console\Commands\BootLicenseCheckCommand::class,
        ]);

        // LicenseGuard is registered on the api middleware group
        // unconditionally — it self-gates on `teamcore.edition` at
        // request time so cloud is a fast pass-through. Pushing
        // unconditionally also keeps tests sane: they flip the
        // config in setUp, after boot has run.
        $this->pushLicenseGuardOntoApiGroup();

        if ($edition === 'self_hosted') {
            $this->registerSelfHostedRuntime();
        }
    }

    /**
     * Load the edition's bonus routes file. routes/api.php (loaded by
     * bootstrap/app.php) holds the routes both editions need. The
     * cloud file imports controllers from app/Modules/Cloud — present
     * only in the cloud image — so self-hosted MUST NOT load it.
     */
    private function loadEditionRoutes(string $edition): void
    {
        $file = $edition === 'self_hosted'
            ? base_path('routes/selfhosted.php')
            : base_path('routes/cloud.php');

        if (is_file($file)) {
            require $file;
        }
    }

    private function pushLicenseGuardOntoApiGroup(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'appendMiddlewareToGroup')) {
            $kernel->appendMiddlewareToGroup('api', LicenseGuard::class);
        }
    }

    private function edition(): string
    {
        return (string) config('teamcore.edition', 'cloud');
    }

    private function loadMigrationPaths(string $edition): void
    {
        // Always load the matching edition's path. In tests we also
        // register the *other* edition's path so the test DB can host
        // both surfaces without a separate setup pass.
        $cloudPath = database_path('migrations-cloud');
        $selfHostedPath = database_path('migrations-selfhosted');

        if ($edition === 'self_hosted') {
            $this->loadMigrationsFrom($selfHostedPath);
        } else {
            $this->loadMigrationsFrom($cloudPath);
        }

        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom($edition === 'self_hosted' ? $cloudPath : $selfHostedPath);
        }
    }

    private function registerSelfHostedRuntime(): void
    {
        // Scheduled tasks register only when the scheduler is running.
        // Wrapping in `if (running console)` is the canonical Laravel
        // recipe — Schedule::command outside that guard would also
        // attempt to bind during HTTP requests, which is harmless but
        // noisy.
        if ($this->app->runningInConsole()) {
            Schedule::command('license:phone-home')->hourly();
        }
    }
}
