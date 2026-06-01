<?php

namespace App\Services\Identity;

use App\Enums\OrganisationRole;
use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Project\Project;
use App\Models\User;
use App\Services\Project\ProjectBootstrapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bootstraps the personal organisation and project (with all defaults)
 * for a brand-new user. The Tauri client relies on these defaults always
 * being present (§2.7).
 */
class PersonalProjectFactory
{
    public function __construct(private readonly ProjectBootstrapper $projectBootstrapper) {}

    public function bootstrap(User $user): Project
    {
        return DB::transaction(function () use ($user): Project {
            $organisation = Organisation::create([
                'owner_id' => $user->id,
                'name' => $user->name."'s workspace",
                'slug' => Str::slug($user->name.'-'.Str::random(6)),
                'is_personal' => true,
                // Explicit to avoid drift from DB defaults — this is
                // the invariant the workspace surface depends on: a
                // fresh personal workspace is free/1 and the creator
                // is its only admin.
                'tier' => PlanTier::Free->value,
                'seat_cap' => PlanTier::Free->defaultSeatCap(),
            ]);

            OrganisationMember::create([
                'organisation_id' => $organisation->id,
                'user_id' => $user->id,
                'role' => OrganisationRole::Admin,
                'invited_by' => $user->id, // self-bootstrap
                'joined_at' => now(),
            ]);

            $project = Project::create([
                'organisation_id' => $organisation->id,
                'owner_id' => $user->id,
                'original_owner_id' => $user->id,
                'name' => 'Personal',
                'is_personal' => true,
            ]);

            $this->projectBootstrapper->bootstrap($project, $user);

            return $project;
        });
    }
}
