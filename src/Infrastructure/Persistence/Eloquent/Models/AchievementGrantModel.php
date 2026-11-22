<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Read-only for the panel: grants are written by AchievementGrantRepositoryInterface.
 *
 * @property string $id
 * @property string $user_id
 * @property string $achievement_id
 * @property string|null $season
 * @property string $season_key
 * @property Carbon $granted_at
 * @property string $source
 * @property string|null $actor_id
 * @property bool $silent
 */
final class AchievementGrantModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'achievements_grants';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<AchievementModel, $this>
     */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(AchievementModel::class, 'achievement_id');
    }

    /**
     * Deactivated accounts included (they keep their achievements).
     *
     * @return BelongsTo<UserModel, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<UserModel, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'actor_id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'silent' => 'boolean',
        ];
    }
}
