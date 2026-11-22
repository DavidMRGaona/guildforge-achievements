<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Livewire;

use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Modules\Achievements\Filament\Support\AccountGrantsAccess;
use Modules\Achievements\Filament\Support\GrantLogTable;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantLogModel;

/**
 * «Historial de logros» on the user page: every grant and revoke of the account, read-only.
 */
final class AccountGrantLog extends TableWidget
{
    public const string NAME = 'achievements.account-grant-log';

    #[Locked]
    public string $userId = '';

    protected int|string|array $columnSpan = 'full';

    public function mount(string $userId): void
    {
        $this->userId = strtolower($userId);
        $this->ensureAccess();
    }

    public function hydrate(): void
    {
        $this->ensureAccess();
    }

    public function table(Table $table): Table
    {
        return GrantLogTable::configure($table, withAchievement: true, withAccount: false)
            ->heading((string) __('achievements::messages.grants.log.account_title'))
            ->query(fn (): Builder => AchievementGrantLogModel::query()->where('user_id', $this->userId));
    }

    private function ensureAccess(): void
    {
        abort_unless(AccountGrantsAccess::allows($this->userId), 403);
    }
}
