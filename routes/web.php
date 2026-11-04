<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'TripFlow API',
    'docs' => 'https://github.com/acamilateixeira/tripflow#readme',
    'health' => url('/api/health'),
]));
