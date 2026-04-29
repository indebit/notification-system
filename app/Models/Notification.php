<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;

class Notification extends Model
{
    use HasUuids;

    protected $fillable = [
        'batch_id',
        'channel',
        'recipient',
        'content',
        'scheduled_at',
        'priority',
        'status',
        'idempotency_key',
        'processing_started_at',
        'delivered_at',
        'failed_at',
        'attempt_count',
        'last_error',
        'external_message_id',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'priority' => NotificationPriority::class,
            'status' => NotificationStatus::class,
            'scheduled_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

}
