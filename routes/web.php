<?php

declare(strict_types=1);

use App\Http\Middleware\ThrottleWhereListed;
use Illuminate\Support\Facades\Route;
use Modules\Achievements\AchievementsServiceProvider;
use Modules\Achievements\Http\Controllers\AccountAchievementsController;
use Modules\Achievements\Http\Controllers\AchievementCatalogueController;
use Modules\Achievements\Http\Controllers\LeaderboardController;
use Modules\Achievements\Http\Controllers\ProfileAchievementsController;

/*
|--------------------------------------------------------------------------
| Web routes of the achievements module
|--------------------------------------------------------------------------
|
| The module loader already applies the "web" middleware.
|
*/

Route::prefix('logros')
    ->name('achievements.')
    ->group(function (): void {
        // The catalogue with the rarity of each achievement, for everybody
        Route::get('/', AchievementCatalogueController::class)->name('index');

        // The leaderboard (#10): signed-in accounts only (and, with `auth`, the core's email
        // verification applies); it answers 404 while points or the leaderboard are off
        Route::get('clasificacion', LeaderboardController::class)
            ->middleware(['auth', ThrottleWhereListed::class.':'.AchievementsServiceProvider::LEADERBOARD_LIMITER])
            ->name('leaderboard');

        // The owner's choices from their profile's section: only the owner of the profile at
        // {handle} passes (the core's profile editor rule), anybody else gets a 404
        Route::put('miembros/{handle}', [ProfileAchievementsController::class, 'update'])
            ->middleware(['auth', ThrottleWhereListed::class.':'.AchievementsServiceProvider::PROFILE_CHOICES_LIMITER])
            ->name('profile.update');

        // The signed-in account's choices from the account settings' «Logros» tab (#6)
        Route::put('preferencias', [AccountAchievementsController::class, 'update'])
            ->middleware(['auth', ThrottleWhereListed::class.':'.AchievementsServiceProvider::PROFILE_CHOICES_LIMITER])
            ->name('account.update');
    });
