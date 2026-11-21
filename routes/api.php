<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes of the achievements module
|--------------------------------------------------------------------------
|
| The module loader already applies the "api" middleware and the "/api"
| prefix: these routes answer under /api/achievements/...
|
*/

Route::prefix('achievements')
    ->name('achievements.api.')
    ->group(function (): void {
        // Route::apiResource('items', ItemController::class);
    });
