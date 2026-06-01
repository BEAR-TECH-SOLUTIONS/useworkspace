<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum AnalyticsPeriod: string
{
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
    case AllTime = 'all_time';

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dateRange(): array
    {
        return match ($this) {
            self::Month => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            self::Quarter => [Carbon::now()->firstOfQuarter(), Carbon::now()->lastOfQuarter()->endOfDay()],
            self::Year => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
            self::AllTime => [Carbon::create(2000, 1, 1), Carbon::now()->endOfYear()],
        };
    }
}
