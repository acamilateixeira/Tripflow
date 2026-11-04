<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Travel\Enums\BookingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BookTravelRequestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:3'],
            'items.*.type' => ['required', 'distinct', Rule::enum(BookingType::class)],
            'items.*.supplier' => ['required', 'string', 'max:100'],
            'items.*.amount_cents' => ['required', 'integer', 'min:1'],
        ];
    }
}
