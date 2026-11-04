<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Isolamento multi-tenant: toda consulta feita no contexto de um usuário
 * autenticado enxerga apenas os registros da empresa dele. Um ID de outra
 * empresa resulta em 404 (e não 403), para não revelar que o registro existe.
 *
 * Jobs e comandos rodam sem usuário e, portanto, sem o escopo: eles operam
 * sobre IDs vindos de mensagens já validadas. Ver docs/adr/0004.
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $user = Auth::user();

            if ($user instanceof User) {
                $query->where($query->getModel()->qualifyColumn('company_id'), $user->company_id);
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
