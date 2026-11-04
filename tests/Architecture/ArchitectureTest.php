<?php

use Illuminate\Filesystem\Filesystem;

/*
 * Regras de arquitetura verificadas no CI. Se alguém quebrar a separação
 * de camadas, o build falha antes do code review.
 */

arch('domínio não conhece HTTP')
    ->expect('App\Domain')
    ->not->toUse(['App\Http', 'Illuminate\Http\Request', 'Illuminate\Support\Facades\Request']);

arch('controllers não acessam o banco diretamente')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB'])
    ->ignoring('App\Http\Controllers\HealthController'); // health check precisa sondar o banco

arch('actions são classes finais com método handle')
    ->expect('App\Domain\Travel\Actions')
    ->toBeFinal()
    ->toHaveMethod('handle');

arch('código de produção não tem debug esquecido')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

test('arquivos de app/ usam strict types', function () {
    $missing = collect((new Filesystem)->allFiles(dirname(__DIR__, 2).'/app'))
        ->filter(fn ($file) => ! str_contains($file->getContents(), 'declare(strict_types=1);'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
