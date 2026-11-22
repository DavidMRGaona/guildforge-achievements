<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\AchievementResource\RelationManagers;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\Achievements\Filament\Support\GrantLogTable;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

/**
 * «Historial de concesiones» of an achievement: who got it and who had it taken away, by whom and
 * why. Read-only; seen with AchievementPolicy::viewGrants().
 */
final class GrantLogRelationManager extends RelationManager
{
    protected static string $relationship = 'grantLog';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $viewer = Auth::user();

        return $viewer instanceof UserModel && $ownerRecord instanceof AchievementModel && $viewer->can('viewGrants', $ownerRecord);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('achievements::messages.grants.log.title');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return GrantLogTable::configure($table, withAchievement: false, withAccount: true);
    }
}
