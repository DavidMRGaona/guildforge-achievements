<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\DeletesCloudinaryImages;
use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An achievement of the catalogue. The rule is kept as the array it was stored as: AchievementRule
 * decides whether it can be read, never the cast.
 *
 * @property string $id
 * @property string $name
 * @property string $description
 * @property string $icon
 * @property string $colour
 * @property string|null $image_public_id
 * @property int $points
 * @property bool $is_secret
 * @property bool $is_active
 * @property mixed $rule
 * @property string|null $starter_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AchievementModel extends Model
{
    use DeletesCloudinaryImages;
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'achievements_catalogue';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The badge image: released through the core when it is replaced or the achievement is
     * deleted, so a file chosen from the media library and used elsewhere stays.
     *
     * @var array<string>
     */
    protected array $cloudinaryImageFields = ['image_public_id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'description' => '',
        'points' => 0,
        'is_secret' => false,
        'is_active' => true,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name',
        'description',
        'icon',
        'colour',
        'image_public_id',
        'points',
        'is_secret',
        'is_active',
        'rule',
        'starter_key',
    ];

    /**
     * @return HasMany<AchievementGrantModel, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(AchievementGrantModel::class, 'achievement_id');
    }

    /**
     * What happened to its grants, newest last.
     *
     * @return HasMany<AchievementGrantLogModel, $this>
     */
    public function grantLog(): HasMany
    {
        return $this->hasMany(AchievementGrantLogModel::class, 'achievement_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'is_secret' => 'boolean',
            'is_active' => 'boolean',
            'rule' => 'array',
        ];
    }
}
