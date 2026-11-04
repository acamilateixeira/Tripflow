<?php

use App\Models\User;

it('emite token para credenciais válidas', function () {
    $user = User::factory()->create(['email' => 'ana@acme.test']);

    $this->postJson('/api/v1/auth/token', ['email' => 'ana@acme.test', 'password' => 'password'])
        ->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure(['token', 'expires_at']);
});

it('não revela se o e-mail existe', function () {
    User::factory()->create(['email' => 'ana@acme.test']);

    $wrongPassword = $this->postJson('/api/v1/auth/token', ['email' => 'ana@acme.test', 'password' => 'errada']);
    $unknownEmail = $this->postJson('/api/v1/auth/token', ['email' => 'nao@existe.test', 'password' => 'errada']);

    $wrongPassword->assertUnprocessable();
    $unknownEmail->assertUnprocessable();
    expect($wrongPassword->json('errors'))->toBe($unknownEmail->json('errors'));
});

it('limita tentativas de login por e-mail e IP', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/token', ['email' => 'x@acme.test', 'password' => 'y']);
    }

    $this->postJson('/api/v1/auth/token', ['email' => 'x@acme.test', 'password' => 'y'])
        ->assertStatus(429);
});

it('exige autenticação nas rotas da API', function () {
    $this->getJson('/api/v1/travel-requests')->assertUnauthorized();
});
