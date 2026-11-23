<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A kind of recognition (#11): «Gran narración», «Buen compañero»… Its icon and colour are the
 * core's badge ones, so it is drawn like an achievement.
 *
 * @property string $id
 * @property string $name
 * @property string $icon
 * @property string $colour
 * @property bool $is_active
 * @property string|null $starter_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RecognitionTypeModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'achievements_recognition_types';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'icon', 'colour', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
