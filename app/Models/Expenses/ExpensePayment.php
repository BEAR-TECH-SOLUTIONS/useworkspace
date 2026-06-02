<?php

namespace App\Models\Expenses;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePayment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'expense_id' => 'int',
        'paid_at' => 'immutable_date',
        'amount' => 'decimal:2',
        'created_by' => 'int',
        'created_at' => 'immutable_datetime',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
