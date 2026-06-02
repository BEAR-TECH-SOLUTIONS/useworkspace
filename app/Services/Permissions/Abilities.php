<?php

namespace App\Services\Permissions;

use App\Enums\MemberRole;

/**
 * Single source of truth for which roles can perform which abilities.
 * No controller, policy, or service may hand-roll role checks; everything
 * must consult this map via PermissionService::can().
 */
final class Abilities
{
    public const VIEW = 'view';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public const SHARE = 'share';

    public const ARCHIVE = 'archive';

    // Distinct from VIEW so the activity feed can be locked down
    // independently — viewers can read tasks without seeing who moved
    // what when. Universal Share Links board-stats addendum.
    public const VIEW_ACTIVITY = 'view_activity';

    /**
     * @var array<string, array<int, MemberRole>>
     */
    private const MAP = [
        self::VIEW => [MemberRole::Owner, MemberRole::Editor, MemberRole::Viewer],
        self::CREATE => [MemberRole::Owner, MemberRole::Editor],
        self::UPDATE => [MemberRole::Owner, MemberRole::Editor],
        self::ARCHIVE => [MemberRole::Owner, MemberRole::Editor],
        self::DELETE => [MemberRole::Owner],
        self::SHARE => [MemberRole::Owner],
        // Same default scope as VIEW; gate is conceptually separate so
        // workspaces can tighten it without touching read access.
        self::VIEW_ACTIVITY => [MemberRole::Owner, MemberRole::Editor, MemberRole::Viewer],
    ];

    public static function allows(MemberRole $role, string $ability): bool
    {
        $allowed = self::MAP[$ability] ?? null;

        if ($allowed === null) {
            throw new \InvalidArgumentException("Unknown ability [{$ability}]");
        }

        return in_array($role, $allowed, true);
    }
}
