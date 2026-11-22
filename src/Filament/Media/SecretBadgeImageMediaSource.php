<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Media;

use App\Application\Media\Contracts\ReleasesMediaThroughCoreInterface;
use App\Infrastructure\Media\Sources\EloquentColumnMediaSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

/**
 * The images of secret achievements ("achievements/badges/secret/<year>/<month>/"). Hidden, like
 * avatars: the media library never lists them, so their thumbnail never shows a secret badge to
 * whoever may not see it. The core never deletes a file of a hidden folder: a replaced or deleted
 * secret image stays stored.
 */
final class SecretBadgeImageMediaSource extends EloquentColumnMediaSource implements ReleasesMediaThroughCoreInterface
{
    /** Stored with each usage: never rename it */
    public const string TYPE = 'achievements.secret-badge-image';

    public const string FOLDER = AchievementBadgeImageMediaSource::FOLDER.AchievementBadgeImageMediaSource::SECRET_SUBFOLDER;

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('achievements::messages.media.secret_source');
    }

    public function folders(): array
    {
        return [self::FOLDER];
    }

    /**
     * @return list<string>
     */
    public function excludedSubfolders(): array
    {
        return [];
    }

    public function hidden(): bool
    {
        return true;
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

    /**
     * @return Builder<Model>
     */
    protected function usagesQuery(): Builder
    {
        return $this->query()->where('is_secret', true);
    }

    protected function titleOf(Model $record): string
    {
        return (string) __('achievements::messages.secret.name');
    }

    protected function editUrlOf(Model $record, object $user): ?string
    {
        return null;
    }
}
