<?php

namespace App\Observers;

use App\Models\Identity\OrganisationMember;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `organisations.member_count` aligned with the live
 * `organisation_members` table. We use an observer rather than
 * pushing the increment into each Action because membership rows are
 * mutated from several paths (invitation accept, provisioning,
 * personal-org bootstrap, future invariants) — a single observer
 * guarantees the counter never drifts.
 *
 * Uses raw SQL increments so concurrent invites don't lost-update each
 * other under read-modify-write semantics.
 */
class OrganisationMemberObserver
{
    public function created(OrganisationMember $member): void
    {
        DB::table('organisations')
            ->where('id', $member->organisation_id)
            ->update(['member_count' => DB::raw('member_count + 1')]);
    }

    public function deleted(OrganisationMember $member): void
    {
        // GREATEST guards against a bad backfill leaving the counter
        // at zero while a row still existed; we'd rather clamp than
        // wrap an unsigned column to a giant number.
        DB::table('organisations')
            ->where('id', $member->organisation_id)
            ->update(['member_count' => DB::raw('GREATEST(member_count - 1, 0)')]);
    }
}
