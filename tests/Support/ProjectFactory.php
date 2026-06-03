<?php

namespace Tests\Support;

use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Project\Project;
use App\Models\User;
use App\Services\Project\ProjectBootstrapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test helper that builds a fully wired-up project (organisation, owner
 * member, ResourcePermission, default board/columns/vault/bucket) for the
 * given user. Mirrors what the API does on POST /projects.
 */
class ProjectFactory
{
    public static function forOwner(User $owner): Project
    {
        return DB::transaction(function () use ($owner): Project {
            $organisation = Organisation::create([
                'owner_id' => $owner->id,
                'name' => 'Org '.bin2hex(random_bytes(3)),
                'slug' => 'org-'.Str::random(8),
                // Default the test factory to the Team plan so multi-
                // member invite/accept and provisioning flows don't
                // bump into Free's cap (max_members=2) before reaching
                // the behaviour under test. Tests that exercise the
                // Free cap override this explicitly.
                'tier' => 'team',
                'seat_cap' => 50,
            ]);

            OrganisationMember::create([
                'organisation_id' => $organisation->id,
                'user_id' => $owner->id,
                'role' => 'admin',
            ]);

            $project = Project::create([
                'organisation_id' => $organisation->id,
                'owner_id' => $owner->id,
                'original_owner_id' => $owner->id,
                'name' => 'Project '.bin2hex(random_bytes(3)),
            ]);

            app(ProjectBootstrapper::class)->bootstrap($project, $owner, base64_encode(random_bytes(48)));

            return $project;
        });
    }
}
