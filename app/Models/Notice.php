<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notice extends Model
{
    // Notice types (see ADR-002 timeline).
    public const TYPE_REMINDER_3D = 'reminder_3d';
    public const TYPE_REMINDER_DUE = 'reminder_due';
    public const TYPE_OVERDUE_1D = 'overdue_1d';
    public const TYPE_ESCALATION_LIBRARIAN_7D = 'escalation_librarian_7d';
    public const TYPE_ESCALATION_SUPERVISOR_14D = 'escalation_supervisor_14d';

    // Notice life cycle (see Step 3 retry and recovery policy).
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';
    public const STATUS_SENT = 'sent';
    public const STATUS_MANUAL_REVIEW = 'manual_review';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'loan_id',
        'recipient_id',
        'type',
        'due_date',
        'status',
        'attempts',
        'last_error',
        'next_retry_at',
        'sent_at',
        'resolved_by',
        'resolved_at',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'next_retry_at' => 'datetime',
            'sent_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
