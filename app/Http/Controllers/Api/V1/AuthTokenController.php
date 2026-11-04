<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            // Mesma mensagem para e-mail inexistente e senha errada: não revela contas.
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        $token = $user->createToken($data['device_name'] ?? 'api', expiresAt: now()->addHours(8));

        return new JsonResponse([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->value],
        ], 201);
    }
}
