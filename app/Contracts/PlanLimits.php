<?php

namespace App\Contracts;

use App\Exceptions\PlanLimitExceeded;
use App\Models\Identity\Organisation;

/**
 * Edition-agnostic plan-cap contract. Cloud binds this to
 * PlanEnforcer (reads `organisations.plan` + `plan_limits`); self-
 * hosted binds it to LicenseEnforcer (reads the verified license
 * payload). Both raise the same {@see PlanLimitExceeded} codes so
 * the desktop client doesn't need edition-aware error handling.
 */
interface PlanLimits
{
    /** @throws PlanLimitExceeded */
    public function assertCanCreateProject(Organisation $workspace): void;

    /** @throws PlanLimitExceeded */
    public function assertCanAddMember(Organisation $workspace): void;

    /** @throws PlanLimitExceeded */
    public function assertCanProvisionUser(Organisation $workspace): void;
}
