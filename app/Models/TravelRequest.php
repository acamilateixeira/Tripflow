<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Domain\Travel\Exceptions\InvalidTransition;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Database\Factories\TravelRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $traveler_id
 * @property int $cost_center_id
 * @property int|null $approver_id
 * @property string $origin
 * @property string $destination
 * @property CarbonImmutable $departure_on
 * @property CarbonImmutable $return_on
 * @property int $estimated_cost_cents
 * @property string $reason
 * @property TravelRequestStatus $status
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $cancelled_at
 * @property-read User $traveler
 * @property-read User|null $approver
 * @property-read CostCenter $costCenter
 */
class TravelRequest extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<TravelRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id', 'traveler_id', 'cost_center_id', 'origin', 'destination',
        'departure_on', 'return_on', 'estimated_cost_cents', 'reason', 'status',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => TravelRequestStatus::class,
            'departure_on' => 'immutable_date',
            'return_on' => 'immutable_date',
            'estimated_cost_cents' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Única porta de entrada para mudança de status: a regra de transição
     * fica no enum e nenhuma action consegue "pular" um estado.
     */
    public function transitionTo(TravelRequestStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw InvalidTransition::between($this->status, $target);
        }

        $this->status = $target;
    }

    /** @return BelongsTo<User, $this> */
    public function traveler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traveler_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
