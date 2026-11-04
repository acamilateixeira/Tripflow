<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Travel\Actions\ApproveTravelRequest;
use App\Domain\Travel\Actions\BookTravelRequest;
use App\Domain\Travel\Actions\CancelTravelRequest;
use App\Domain\Travel\Actions\CreateTravelRequest;
use App\Domain\Travel\Actions\RejectTravelRequest;
use App\Domain\Travel\Actions\SubmitTravelRequest;
use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookTravelRequestRequest;
use App\Http\Requests\StoreTravelRequestRequest;
use App\Http\Resources\TravelRequestResource;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Controller fino: valida, autoriza e delega para uma action.
 * Regra de negócio não mora aqui.
 */
final class TravelRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TravelRequestStatus::class)],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $query = TravelRequest::query()
            ->with('traveler:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(! $user->canApprove() || $request->boolean('mine'), fn ($q) => $q->where('traveler_id', $user->id))
            ->orderByDesc('id');

        // Cursor pagination: custo constante mesmo em páginas profundas (sem OFFSET).
        return TravelRequestResource::collection($query->cursorPaginate($filters['per_page'] ?? 20));
    }

    public function store(StoreTravelRequestRequest $request, CreateTravelRequest $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $travelRequest = $action->handle($user, $request->toData());

        return (new TravelRequestResource($travelRequest))->response()->setStatusCode(201);
    }

    public function show(TravelRequest $travelRequest): TravelRequestResource
    {
        Gate::authorize('view', $travelRequest);

        return new TravelRequestResource($travelRequest->load(['traveler:id,name', 'bookings']));
    }

    public function submit(TravelRequest $travelRequest, SubmitTravelRequest $action): TravelRequestResource
    {
        Gate::authorize('update', $travelRequest);

        return new TravelRequestResource($action->handle($travelRequest));
    }

    public function approve(Request $request, TravelRequest $travelRequest, ApproveTravelRequest $action): TravelRequestResource
    {
        Gate::authorize('decide', $travelRequest);

        /** @var User $user */
        $user = $request->user();

        return new TravelRequestResource($action->handle($travelRequest, $user));
    }

    public function reject(Request $request, TravelRequest $travelRequest, RejectTravelRequest $action): TravelRequestResource
    {
        Gate::authorize('decide', $travelRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        /** @var User $user */
        $user = $request->user();

        return new TravelRequestResource($action->handle($travelRequest, $user, $data['reason']));
    }

    public function book(BookTravelRequestRequest $request, TravelRequest $travelRequest, BookTravelRequest $action): TravelRequestResource
    {
        Gate::authorize('book', $travelRequest);

        /** @var list<array{type: string, supplier: string, amount_cents: int}> $items */
        $items = $request->validated('items');

        return new TravelRequestResource($action->handle($travelRequest, $items)->load('bookings'));
    }

    public function cancel(TravelRequest $travelRequest, CancelTravelRequest $action): TravelRequestResource
    {
        Gate::authorize('cancel', $travelRequest);

        return new TravelRequestResource($action->handle($travelRequest));
    }
}
