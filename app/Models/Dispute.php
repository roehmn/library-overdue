<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_UPHELD = 'upheld';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'loan_id',
        'filed_by',
        'reason',
        'status',
        'decided_by',
        'decision_note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
