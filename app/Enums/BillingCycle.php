<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum BillingCycle: string
{
    case OneTime = 'one_time';
    case Weekly = 'weekly';
    case BiWeekly = 'bi_weekly';
    case Monthly = 'monthly';
    case BiMonthly = 'bi_monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Yearly = 'yearly';

    /**
     * Advance a date by one cycle. Uses NoOverflow variants so
     * Jan 31 → monthly → Feb 28 (not Mar 3).
     */
    public function advance(Carbon $date): ?Carbon
    {
        return match ($this) {
            self::OneTime => null,
            self::Weekly => $date->copy()->addDays(7),
            self::BiWeekly => $date->copy()->addDays(14),
            self::Monthly => $date->copy()->addMonthNoOverflow(),
            self::BiMonthly => $date->copy()->addMonthsNoOverflow(2),
            self::Quarterly => $date->copy()->addMonthsNoOverflow(3),
            self::SemiAnnual => $date->copy()->addMonthsNoOverflow(6),
            self::Yearly => $date->copy()->addYearNoOverflow(),
        };
    }

    /**
     * Reverse one cycle — used by the payment-delete undo path.
     */
    public function reverse(Carbon $date): ?Carbon
    {
        return match ($this) {
            self::OneTime => null,
            self::Weekly => $date->copy()->subDays(7),
            self::BiWeekly => $date->copy()->subDays(14),
            self::Monthly => $date->copy()->subMonthNoOverflow(),
            self::BiMonthly => $date->copy()->subMonthsNoOverflow(2),
            self::Quarterly => $date->copy()->subMonthsNoOverflow(3),
            self::SemiAnnual => $date->copy()->subMonthsNoOverflow(6),
            self::Yearly => $date->copy()->subYearNoOverflow(),
        };
    }
}
