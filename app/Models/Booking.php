<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Travel\Enums\BookingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $travel_request_id
 * @property BookingType $type
 * @property string $supplier
 * @property string $locator
 * @property int $amount_cents
 */
class Booking extends Model
{
    protected $fillable = ['travel_request_id', 'type', 'supplier', 'locator', 'amount_cents'];

    protected function casts(): array
    {
        return [
            'type' => BookingType::class,
            'amount_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<TravelRequest, $this> */
    public function travelRequest(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class);
    }
}
