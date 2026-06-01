<?php

namespace App\Enums;

enum ActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Completed = 'completed';
    case Reopened = 'reopened';
    case Moved = 'moved';
    case Archived = 'archived';
    case Unarchived = 'unarchived';
    case ChecklistAdded = 'checklist_added';
    case ChecklistChecked = 'checklist_checked';
    case ChecklistUnchecked = 'checklist_unchecked';
    case Commented = 'commented';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case Labeled = 'labeled';
    case Unlabeled = 'unlabeled';
    case ColumnCreated = 'column_created';
    case ColumnRenamed = 'column_renamed';
    case ColumnDeleted = 'column_deleted';

    // Task Resource Attachments spec §6.
    case AttachedCredential = 'attached_credential';
    case AttachedExpenseBucket = 'attached_expense_bucket';
    case AttachedExpense = 'attached_expense';
    case AttachedDoc = 'attached_doc';
    case DetachedCredential = 'detached_credential';
    case DetachedExpenseBucket = 'detached_expense_bucket';
    case DetachedExpense = 'detached_expense';
    case DetachedDoc = 'detached_doc';

    // Universal share-link lifecycle, written to task_activities for
    // task/board shares so the audit panel inside a board surfaces them.
    // Credential/doc/expense shares write to audit_log instead — they
    // don't belong in the per-board feed. Plan §8.
    case TaskShared = 'task_shared';
    case TaskShareRevoked = 'task_share_revoked';
    case TaskShareViewed = 'task_share_viewed';
    case BoardShared = 'board_shared';
    case BoardShareRevoked = 'board_share_revoked';
    case BoardShareViewed = 'board_share_viewed';

    // Expense module: payment-type lifecycle (no labels — coloring is
    // client-side only, hashed deterministically from the tag string).
    case ExpensePaymentTypeSet = 'expense_payment_type_set';
}
