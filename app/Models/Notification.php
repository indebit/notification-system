<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

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

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function scopeByStatus(Builder $query, NotificationStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeByChannel(Builder $query, NotificationChannel $channel): Builder
    {
        return $query->where('channel', $channel->value);
    }

    public function scopeByDateRange(Builder $query, ?Carbon $from, ?Carbon $to): Builder
    {
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    public function scopeByBatchId(Builder $query, ?string $batchId): Builder
    {
        if ($batchId === null) {
            return $query;
        }

        return $query->where('batch_id', $batchId);
    }

}
