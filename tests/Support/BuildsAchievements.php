<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

/**
 * Achievements of the catalogue, accounts and ledger facts written straight to their tables.
 */
trait BuildsAchievements
{
    /**
     * Enabling the module runs its seeder, which creates the starter catalogue: tests that build their
     * own catalogue start without it.
     */
    protected function withoutStarterCatalogue(): void
    {
        AchievementModel::query()->delete();
        // The seeder read the sources (scoped) before the test registers its own
        $this->app->forgetScopedInstances();
    }

    /**
     * @param  array<string, mixed>|string  $rule
     * @param  array<string, mixed>  $attributes
     */
    protected function achievement(array|string $rule, array $attributes = []): AchievementModel
    {
        $model = new AchievementModel;
        $model->fill($attributes + [
            'name' => 'Logro '.uniqid(),
            'description' => 'Una descripción',
            'icon' => 'trophy',
            'colour' => 'amber',
            'points' => 10,
            'is_secret' => false,
            'is_active' => true,
        ]);
        // A string is stored as it is (a broken rule, say), bypassing the cast
        is_string($rule) ? $model->setRawAttributes($model->getAttributes() + ['rule' => $rule]) : $model->rule = $rule;
        $model->save();

        return $model;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function countRule(string $kind = 'game_session', int $threshold = 1, array $extra = []): array
    {
        return ['version' => 1, 'metric' => 'count', 'kind' => $kind, 'threshold' => $threshold] + $extra;
    }

    protected function account(?string $id = null, bool $anonymized = false): string
    {
        $attributes = $id === null ? [] : ['id' => $id];

        if ($anonymized) {
            $attributes['anonymized_at'] = now();
        }

        return strtolower((string) UserModel::factory()->create($attributes)->id);
    }

    /**
     * @param  list<RecordedFact>  $facts
     */
    protected function ledgerSubject(string $sourceType, string $subjectId, string $kind, array $facts): void
    {
        $this->app->make(AchievementFactRepositoryInterface::class)->replaceSubject($sourceType, $subjectId, $kind, $facts);
    }

    protected function userWith(string ...$permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach (['admin.access', ...$permissionKeys] as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'achievements', 'action' => 'test', 'module' => 'achievements'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
