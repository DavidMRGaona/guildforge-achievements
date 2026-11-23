<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\ShownToOthersRule;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

final readonly class EloquentAchievementRepository implements AchievementRepositoryInterface
{
    public function all(): array
    {
        return $this->entities(AchievementModel::query()->orderBy('name')->orderBy('id')->get()->all());
    }

    public function active(): array
    {
        return $this->entities(AchievementModel::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get()->all());
    }

    public function find(string $id): ?Achievement
    {
        // PostgreSQL rejects a malformed uuid instead of finding nothing
        if (! AchievementIds::isWellFormed($id)) {
            return null;
        }

        $model = AchievementModel::query()->find(strtolower($id));

        return $model instanceof AchievementModel ? self::toEntity($model) : null;
    }

    public static function toEntity(AchievementModel $model): Achievement
    {
        return new Achievement(
            id: $model->id,
            name: $model->name,
            description: $model->description,
            icon: $model->icon,
            colour: $model->colour,
            imagePublicId: $model->image_public_id === '' ? null : $model->image_public_id,
            points: $model->points,
            secret: $model->is_secret,
            active: $model->is_active,
            rule: AchievementRule::tryFrom($model->getRawOriginal('rule')),
            starterKey: $model->starter_key,
            showsByClosedMonths: ShownToOthersRule::byClosedMonths($model->getRawOriginal('rule')),
        );
    }

    /**
     * @param  array<AchievementModel>  $models
     * @return list<Achievement>
     */
    private function entities(array $models): array
    {
        return array_values(array_map(self::toEntity(...), $models));
    }
}
