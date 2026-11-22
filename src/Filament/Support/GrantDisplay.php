<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Auth;
use Modules\Achievements\Domain\Enums\GrantLogAction;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Policies\AchievementPolicy;

/**
 * How grants and their history read in the panel: names masked for whoever may not see a secret
 * achievement, and the labels and colours of actions and sources.
 */
final class GrantDisplay
{
    /**
     * The achievement's name as the signed-in person may see it; a deleted one (history only) keeps
     * its stored name for whoever manages the catalogue.
     */
    public static function achievementName(?AchievementModel $achievement, ?string $storedName = null): string
    {
        $viewer = Auth::user();

        if (! $achievement instanceof AchievementModel) {
            $deleted = self::text('log.deleted_achievement');

            return $viewer instanceof UserModel && $storedName !== null && self::manages($viewer) ? $deleted.' · '.$storedName : $deleted;
        }

        if (! $viewer instanceof UserModel || ! AchievementPolicy::seesUnmaskedFor($viewer, $achievement)) {
            return $achievement->is_secret ? (string) __('achievements::messages.secret.name') : $achievement->name;
        }

        return $achievement->name;
    }

    public static function accountName(?UserModel $user): string
    {
        return $user instanceof UserModel ? $user->name : self::text('log.anonymized');
    }

    public static function actorName(?UserModel $actor, string $source): string
    {
        if ($actor instanceof UserModel) {
            return $actor->name;
        }

        return in_array($source, [GrantSource::Rule->value, GrantSource::Challenge->value], true) ? self::text('log.automatic') : '—';
    }

    public static function sourceLabel(string $source): string
    {
        $key = 'achievements::messages.grants.sources.'.$source;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $source;
    }

    public static function sourceColour(string $source): string
    {
        return match ($source) {
            GrantSource::Rule->value => 'gray',
            GrantSource::Manual->value => 'primary',
            GrantSource::Bulk->value => 'info',
            default => 'warning',
        };
    }

    public static function actionLabel(string $action): string
    {
        return GrantLogAction::tryFrom($action) === GrantLogAction::Revoked ? self::text('actions.revoked') : self::text('actions.granted');
    }

    public static function actionColour(string $action): string
    {
        return GrantLogAction::tryFrom($action) === GrantLogAction::Revoked ? 'danger' : 'success';
    }

    private static function manages(UserModel $viewer): bool
    {
        return $viewer->can('create', AchievementModel::class);
    }

    private static function text(string $key): string
    {
        return (string) __('achievements::messages.grants.'.$key);
    }
}
