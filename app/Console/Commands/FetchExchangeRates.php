<?php

namespace App\Console\Commands;

use App\Services\Fx\FxRateService;
use Illuminate\Console\Command;
use Throwable;

class FetchExchangeRates extends Command
{
    protected $signature = 'fx:fetch';

    protected $description = 'Fetch the latest FX rates and reverse-convert them into a per-base cross-rate matrix cached for the day.';

    public function handle(FxRateService $fx): int
    {
        try {
            $matrix = $fx->refresh();
        } catch (Throwable $e) {
            $this->error('FX fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $bases = array_keys($matrix);
        $this->info('FX matrix cached for: '.implode(', ', $bases));

        return self::SUCCESS;
    }
}
