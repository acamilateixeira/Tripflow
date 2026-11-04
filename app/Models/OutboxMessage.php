<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $event_type
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property array<string, mixed> $payload
 * @property string|null $correlation_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $published_at
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property string|null $last_error
 */
class OutboxMessage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReadyToPublish(Builder $query): Builder
    {
        return $query->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->orderBy('occurred_at');
    }
}
