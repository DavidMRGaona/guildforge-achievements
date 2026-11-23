<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A collective challenge (#12). The rule is kept as the array it was stored as: ChallengeRule
 * decides whether it can be read, never the cast. `starts_on` and `ends_on` are calendar days of the
 * display zone (a `date` cast keeps its day whatever the zones).
 *
 * @property string $id
 * @property string $name
 * @property string $description
 * @property mixed $rule
 * @property string $audience
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $reward_achievement_id
 * @property int $progress
 * @property int $contributors
 * @property int $count_version
 * @property Carbon|null $progress_at
 * @property Carbon|null $completed_at
 * @property int $shown_progress
 * @property int $shown_contributors
 * @property Carbon|null $shown_completed_at
 * @property Carbon|null $shown_at
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ChallengeModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'achievements_challenges';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'description' => '',
        'audience' => 'everybody',
        'progress' => 0,
        'contributors' => 0,
        'count_version' => 1,
        'shown_progress' => 0,
        'shown_contributors' => 0,
    ];

    /** What a count is made of: changing any of it makes counts of the older version stale */
    public const array COUNTED = ['rule', 'audience', 'starts_on', 'ends_on', 'reward_achievement_id'];

    protected static function booted(): void
    {
        self::updating(static function (ChallengeModel $challenge): void {
            if ($challenge->isDirty(self::COUNTED)) {
                $challenge->count_version += 1;
            }
        });
    }

    /**
     * What the panel's form writes; the counts, the completion and the closing are written by the
     * module alone.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'rule',
        'audience',
        'starts_on',
        'ends_on',
        'reward_achievement_id',
        'created_by',
    ];

    /**
     * @return BelongsTo<AchievementModel, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(AchievementModel::class, 'reward_achievement_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'progress' => 'integer',
            'contributors' => 'integer',
            'count_version' => 'integer',
            'progress_at' => 'datetime',
            'completed_at' => 'datetime',
            'shown_progress' => 'integer',
            'shown_contributors' => 'integer',
            'shown_completed_at' => 'datetime',
            'shown_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
