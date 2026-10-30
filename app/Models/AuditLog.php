<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only history of state changes.
 * Rows can be created but never edited or deleted through the application.
 */
class AuditLog extends Model
{
    // The table has created_at only (no updated_at), and we set it ourselves.
    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'from_state',
        'to_state',
        'details',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Enforce "append-only" in code: history can never be altered.
        static::updating(function () {
            throw new LogicException('Audit log entries are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('Audit log entries are append-only and cannot be deleted.');
        });
    }

    /**
     * Record one state change. $actorId is null when the system itself acted.
     */
    public static function record(
        ?int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        ?string $from = null,
        ?string $to = null,
        array $details = []
    ): self {
        return static::create([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'from_state' => $from,
            'to_state' => $to,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}
