<?php

namespace App\Enums;

enum NotificationType: string
{
    case TaskAssigned = 'task_assigned';
    case TaskUpdated = 'task_updated';
    case TaskCommented = 'task_commented';
    case TaskDueSoon = 'task_due_soon';
    case TaskOverdue = 'task_overdue';
    case ExpenseDueSoon = 'expense_due_soon';
    case InvitationReceived = 'invitation_received';
    case MemberRemoved = 'member_removed';
    case UserReadyForAccess = 'user_ready_for_access';

    // Universal Share Links plan §11. Fires when a public share link
    // hits the per-token brute-force threshold and is auto-revoked.
    case ShareLinkBruteForce = 'share_link_brute_force';
}
