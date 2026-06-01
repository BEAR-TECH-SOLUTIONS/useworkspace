<?php

namespace App\Enums;

/**
 * Verbs written to the audit_log table. Kept short and stable — renames
 * force downstream consumers (audit viewer UI, BI pipelines) to do a
 * coordinated swap, so we only ADD new values here, never rename.
 */
enum AuditAction: string
{
    case ResourceGranted = 'resource.granted';
    case ResourceRevoked = 'resource.revoked';
    case ResourceRotated = 'resource.rotated';
    case VaultMigrated = 'vault.migrated';
    case MemberInvited = 'member.invited';
    case MemberRemoved = 'member.removed';
    case MemberRoleChanged = 'member.role_changed';
    case InvitationSent = 'member.invitation_sent';
    case InvitationAccepted = 'member.invitation_accepted';
    case InvitationDeclined = 'member.invitation_declined';
    case InvitationCancelled = 'member.invitation_cancelled';

    // Universal share-link lifecycle (Universal Share Links plan §8).
    // Used for credential/doc/expense shares; task/board shares route
    // through task_activities instead.
    case ShareLinkCreated = 'share_link.created';
    case ShareLinkRevoked = 'share_link.revoked';
    case ShareLinkViewed = 'share_link.viewed';
}