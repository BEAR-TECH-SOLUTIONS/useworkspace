<?php

namespace App\Modules\SelfHosted\Providers;

use App\Contracts\PlanLimits;
use App\Modules\SelfHosted\Services\Licensing\LicenseEnforcer;
use Illuminate\Support\ServiceProvider;

/**
 * Self-hosted-edition container bindings. Registered by
 * {@see \App\Providers\EditionServiceProvider} only when
 * TC_EDITION=self_hosted.
 */
class SelfHostedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PlanLimits::class, LicenseEnforcer::class);
    }
}
