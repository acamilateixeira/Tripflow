<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TravelRequest;
use App\Models\User;

/**
 * Autorização. O isolamento por empresa já é feito pelo escopo global;
 * aqui ficam as regras de papel e de segregação de funções.
 */
final class TravelRequestPolicy
{
    public function view(User $user, TravelRequest $request): bool
    {
        return $request->traveler_id === $user->id || $user->canApprove();
    }

    public function update(User $user, TravelRequest $request): bool
    {
        return $request->traveler_id === $user->id;
    }

    /** Segregação de funções: ninguém aprova a própria viagem, nem sendo admin. */
    public function decide(User $user, TravelRequest $request): bool
    {
        return $user->canApprove() && $request->traveler_id !== $user->id;
    }

    public function book(User $user, TravelRequest $request): bool
    {
        return $user->canApprove() || $request->traveler_id === $user->id;
    }

    public function cancel(User $user, TravelRequest $request): bool
    {
        return $request->traveler_id === $user->id || $user->canApprove();
    }
}
