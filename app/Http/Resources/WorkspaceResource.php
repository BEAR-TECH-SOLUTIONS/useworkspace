<?php

namespace App\Http\Resources;

use App\Enums\OrganisationRole;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Workspace (organisations row) — the billing unit and member
 * directory container. `seat_count` is the live row count so clients
 * can render the "x / y seats used" badge without a second call.
 *
 * @mixin Organisation
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $seatCount = OrganisationMember::query()
            ->where('organisation_id', $this->id)
            ->count();

        $viewerRole = null;
        if ($user = $request->user()) {
            if ((int) $this->owner_id === (int) $user->id) {
                $viewerRole = OrganisationRole::Admin->value;
            } else {
                // `->value('role')` on an Eloquent builder applies the
                // model's `$casts`, returning a backed enum. Casting
                // that to string via `(string) $enum` is a TypeError
                // in PHP 8 (BackedEnum has no __toString). Handle both
                // the enum-cast path and a raw-string fallback so the
                // resource can't crash on this one column again.
                $role = OrganisationMember::query()
                    ->where('organisation_id', $this->id)
                    ->where('user_id', $user->id)
                    ->value('role');

                $viewerRole = $role instanceof OrganisationRole
                    ? $role->value
                    : ($role !== null ? (string) $role : null);
            }
        }

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_personal' => (bool) $this->is_personal,
            'owner_id' => (int) $this->owner_id,
            'tier' => $this->tier?->value,
            // `plan` is an alias for `tier` kept on the response shape
            // for backwards compatibility — older client builds read
            // workspace.plan to drive billing UI. The underlying
            // column has collapsed into `tier` (canonical PlanTier
            // taxonomy); we re-emit it under both names until the
            // client minimum-version drops the alias.
            'plan' => $this->tier?->value,
            'plan_limits' => $this->plan_limits,
            'plan_started_at' => $this->plan_started_at?->toIso8601String(),
            'plan_renews_at' => $this->plan_renews_at?->toIso8601String(),
            // `member_count` is the denormalised counter (kept in sync by
            // OrganisationMemberObserver). `seat_count` is the same value
            // sourced live from the membership table — kept around for the
            // existing seat-cap UI until billing migrates over.
            'member_count' => (int) ($this->member_count ?? $seatCount),
            'members_can_create_projects' => (bool) ($this->members_can_create_projects ?? true),
            'members_can_invite_members' => (bool) ($this->members_can_invite_members ?? true),
            'seat_cap' => (int) $this->seat_cap,
            'seat_count' => $seatCount,
            'billing_status' => $this->billing_status?->value,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'viewer_role' => $viewerRole,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
