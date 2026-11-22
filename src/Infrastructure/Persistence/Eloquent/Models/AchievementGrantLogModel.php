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
 * Read-only for the panel: entries are written by AchievementGrantRepositoryInterface, in the same
 * transaction as the change, and never edited.
 *
 * @property string $id
 * @property string|null $achievement_id
 * @property string $achievement_name
 * @property string|null $user_id
 * @property string|null $actor_id
 * @property string $action
 * @property string $source
 * @property string $season_key
 * @property string|null $reason
 * @property Carbon $created_at
 */
final class AchievementGrantLogModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    public const UPDATED_AT = null;

    protected $table = 'achievements_grant_log';

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
     * Deactivated accounts included.
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
            'created_at' => 'datetime',
        ];
    }
}
