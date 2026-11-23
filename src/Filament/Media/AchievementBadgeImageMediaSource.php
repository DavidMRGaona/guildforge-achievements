<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Media;

use App\Application\Media\Contracts\ReleasesMediaThroughCoreInterface;
use App\Application\Media\Contracts\ShowsMediaPreviews;
use App\Infrastructure\Media\Sources\EloquentColumnMediaSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

/**
 * The optional image of an achievement's badge, uploaded to "achievements/badges/<year>/<month>/" or
 * chosen from the media library. The model releases a replaced or deleted image through the core,
 * which keeps it while anyone else uses it.
 *
 * Badges show their previews in the media library, except a secret achievement's: its usage is
 * hidden (a placeholder instead of the image, never offered for reuse) and titled «Logro secreto», so
 * the team that manages the library never learns the secret by accident. It guards against spoilers,
 * not access: the file stays public in Cloudinary. Changing the secrecy refreshes the usage (the
 * provider's observer).
 */
final class AchievementBadgeImageMediaSource extends EloquentColumnMediaSource implements ReleasesMediaThroughCoreInterface, ShowsMediaPreviews
{
    /** Stored with each usage: never rename it */
    public const string TYPE = 'achievements.badge-image';

    /** Where the panel uploads badge images (with the year and month below it) */
    public const string FOLDER = 'achievements/badges/';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('achievements::messages.media.source');
    }

    public function folders(): array
    {
        return [self::FOLDER];
    }

    /**
     * No rich editor writes into the module's folder.
     *
     * @return list<string>
     */
    public function excludedSubfolders(): array
    {
        return [];
    }

    protected function modelClass(): string
    {
        return AchievementModel::class;
    }

    protected function column(): string
    {
        return 'image_public_id';
    }

    protected function role(): string
    {
        return __('achievements::messages.media.role');
    }

    protected function isHidden(Model $record): bool
    {
        return (bool) $record->getAttribute('is_secret');
    }

    protected function titleOf(Model $record): string
    {
        if ((bool) $record->getAttribute('is_secret')) {
            return (string) __('achievements::messages.secret.name');
        }

        return (string) $record->getAttribute('name');
    }

    protected function editUrlOf(Model $record, object $user): ?string
    {
        if (! Gate::forUser($user)->allows('update', $record)) {
            return null;
        }

        return AchievementResource::getUrl('edit', ['record' => $record]);
    }
}
