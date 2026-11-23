<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recognition (#11), for the panel's moderation table only: the site reads them through
 * RecognitionRepositoryInterface. Written only by the repository, with random (v4) ids: never
 * Laravel's time-ordered ones, which would carry the instant.
 *
 * @property string $id
 * @property string $type_id
 * @property string $giver_id
 * @property string $receiver_id
 * @property string $source_type
 * @property string $subject_id
 * @property string $kind
 * @property Carbon $given_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RecognitionTypeModel|null $type
 * @property-read UserModel|null $giver
 * @property-read UserModel|null $receiver
 */
final class RecognitionModel extends Model
{
    use StoresInstantsInStorageTimezone;

    protected $table = 'achievements_recognitions';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'given_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecognitionTypeModel, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(RecognitionTypeModel::class, 'type_id');
    }

    /**
     * @return BelongsTo<UserModel, $this>
     */
    public function giver(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'giver_id')->withTrashed();
    }

    /**
     * @return BelongsTo<UserModel, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'receiver_id')->withTrashed();
    }
}
