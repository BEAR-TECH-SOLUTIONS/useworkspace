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
        if (! $this->boolLimit('can_provision_users')) {
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
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $state = LicenseState::query()->find(1);

        return is_array($state?->verified_payload) ? $state->verified_payload : [];
    }
}
