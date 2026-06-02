<?php

namespace App\Models\Expenses;

use App\Enums\BillingCycle;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentType;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'category' => ExpenseCategory::class,
        'billing_cycle' => BillingCycle::class,
        'payment_type' => PaymentType::class,
        'amount' => 'decimal:2',
        'tags' => 'array',
        'next_due_date' => 'immutable_date',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(ExpenseBucket::class, 'bucket_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }
}
