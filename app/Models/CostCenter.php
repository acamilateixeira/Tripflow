<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CostCenterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property int $monthly_budget_cents
 */
class CostCenter extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CostCenterFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'code', 'name', 'monthly_budget_cents'];

    protected function casts(): array
    {
        return ['monthly_budget_cents' => 'integer'];
    }

    /** @return HasMany<TravelRequest, $this> */
    public function travelRequests(): HasMany
    {
        return $this->hasMany(TravelRequest::class);
    }
}
