<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    // Allowed loan states.
    public const STATUS_ACTIVE = 'active';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_CLOSED = 'closed';

    // Only these columns may be filled from an array (protects against mass-assignment).
    protected $fillable = [
        'borrower_id',
        'external_loan_ref',
        'item_title',
        'item_barcode',
        'borrowed_at',
        'due_date',
        'returned_at',
        'renewal_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'borrowed_at' => 'date',
            'due_date' => 'date',
            'returned_at' => 'date',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(Renewal::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }
}
