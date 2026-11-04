<?php

use App\Domain\Travel\Enums\TravelRequestStatus as S;

it('permite apenas as transições do fluxo', function (S $from, S $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'rascunho → pendente' => [S::Draft, S::PendingApproval, true],
    'rascunho → aprovada (pula etapa)' => [S::Draft, S::Approved, false],
    'pendente → aprovada' => [S::PendingApproval, S::Approved, true],
    'pendente → recusada' => [S::PendingApproval, S::Rejected, true],
    'pendente → emitida (pula aprovação)' => [S::PendingApproval, S::Booked, false],
    'aprovada → emitida' => [S::Approved, S::Booked, true],
    'emitida → cancelada' => [S::Booked, S::Cancelled, true],
    'recusada → aprovada (estado final)' => [S::Rejected, S::Approved, false],
    'cancelada → pendente (estado final)' => [S::Cancelled, S::PendingApproval, false],
]);

it('marca recusada e cancelada como estados finais', function () {
    expect(S::Rejected->isFinal())->toBeTrue()
        ->and(S::Cancelled->isFinal())->toBeTrue()
        ->and(S::Approved->isFinal())->toBeFalse();
});

it('considera aprovadas e emitidas como orçamento comprometido', function () {
    expect(S::committingBudget())->toBe([S::Approved, S::Booked]);
});
