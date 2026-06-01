<?php

namespace App\Enums;

enum TaskResourceLinkKind: string
{
    case Credential = 'credential';
    case ExpenseBucket = 'expense_bucket';
    case Expense = 'expense';
    case Doc = 'doc';

    public function activityAttached(): ActivityAction
    {
        return match ($this) {
            self::Credential => ActivityAction::AttachedCredential,
            self::ExpenseBucket => ActivityAction::AttachedExpenseBucket,
            self::Expense => ActivityAction::AttachedExpense,
            self::Doc => ActivityAction::AttachedDoc,
        };
    }

    public function activityDetached(): ActivityAction
    {
        return match ($this) {
            self::Credential => ActivityAction::DetachedCredential,
            self::ExpenseBucket => ActivityAction::DetachedExpenseBucket,
            self::Expense => ActivityAction::DetachedExpense,
            self::Doc => ActivityAction::DetachedDoc,
        };
    }
}
