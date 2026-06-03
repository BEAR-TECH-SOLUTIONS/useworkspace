<?php

namespace App\Modules\SelfHosted\Services\Licensing;

use App\Contracts\PlanLimits;
use App\Enums\WorkspaceInvitationStatus;
use App\Exceptions\PlanLimitExceeded;
use App\Models\Identity\Organisation;
use App\Models\Project\Project;
use App\Models\WorkspaceInvitation;
use App\Modules\SelfHosted\Models\LicenseState;

/**
 * Self-hosted counterpart to PlanEnforcer. Reads caps from the
 * verified license payload cached in `license_state` and raises the
 * same {@see PlanLimitExceeded} codes so the desktop client stays
 * edition-agnostic.
 *
 * The exception messages are self-host-flavoured ("contact your
 * administrator") to match the spec — cloud says "upgrade your plan".
 * The `code` is identical in both editions.
 */
class LicenseEnforcer implements PlanLimits
{
    public function assertCanCreateProject(Organisation $workspace): void
    {
        $cap = $this->intLimit('max_projects');
        if ($cap === null) {
            return;
        }

        $current = Project::query()
            ->where('organisation_id', $workspace->id)
            ->count();

        if ($current >= $cap) {
            throw new PlanLimitExceeded(
                'plan_limit_projects',
                "Your license caps projects at {$cap}. Contact your administrator to extend it.",
            );
        }
    }

    public function assertCanAddMember(Organisation $workspace): void
    {
        $cap = $this->intLimit('max_members');
        if ($cap === null) {
            return;
        }

        $current = (int) ($workspace->member_count ?? 0)
            + WorkspaceInvitation::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', WorkspaceInvitationStatus::Pending->value)
                ->count();

        if ($current >= $cap) {
            throw new PlanLimitExceeded(
                'plan_limit_members',
                "Your license caps members at {$cap}. Contact your administrator to extend it.",
            );
        }
    }

    public function assertCanProvisionUser(Organisation $workspace): void
    {
        // Self-hosted has no provisioning restriction by default —
        // the customer is running the software on their own
        // infrastructure and there's no commercial reason to gate it.
        // We DO still respect an explicit `can_provision_users: false`
        // in the license payload (older v1 admin-issued licenses set
        // that field) so a deliberately-restricted license keeps its
        // restriction. Absent (the v2 self-serve payload omits the
        // field entirely) → permit. Without this distinction, v2
        // licenses fail the gate even though they're meant to be
        // unrestricted.
        if ($this->hasLimit('can_provision_users') && ! $this->boolLimit('can_provision_users')) {
            throw new PlanLimitExceeded(
                'plan_limit_provision_users',
                'Direct user provisioning is disabled by your license. Contact your administrator.',
            );
        }

        $this->assertCanAddMember($workspace);
    }

    private function intLimit(string $key): ?int
    {
        $payload = $this->payload();
        $value = $payload[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    private function boolLimit(string $key): bool
    {
        $payload = $this->payload();

        return (bool) ($payload[$key] ?? false);
    }

    /**
     * Did the license payload explicitly set this key? Distinct from
     * `boolLimit` because we sometimes want to know "field absent"
     * from "field present and false" — the former means the v2
     * minimal payload is in use and the limit should be treated as
     * unrestricted, while the latter is a deliberate restriction
     * from a v1 admin-issued license.
     */
    private function hasLimit(string $key): bool
    {
        return array_key_exists($key, $this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $state = LicenseState::query()->find(1);

        return is_array($state?->verified_payload) ? $state->verified_payload : [];
    }
}
