<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Services\EmailVerificationPolicyInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Throwable;

/**
 * The «Logros» tab of the account settings (`/perfil?tab=achievements`, achievements#6): whether the
 * owner's rare achievements are announced and whether they appear in the leaderboard. Each choice is
 * offered only while its mechanic is on; with neither, or while the email must be verified (the save
 * route would refuse it), there is no tab.
 */
final readonly class AccountAchievementsTab
{
    public const string TAB = 'achievements';

    public function __construct(
        private AchievementsSettingsInterface $settings,
        private AchievementPreferencesRepositoryInterface $preferences,
        private EmailVerificationPolicyInterface $verification,
    ) {}

    /**
     * @return array{announceRare: bool, inLeaderboard: bool, hidden: bool, offersAnnouncements: bool, offersLeaderboard: bool, threshold: float, updateUrl: string, noticesUrl: string}|null
     */
    public function props(Authenticatable $user): ?array
    {
        if ($this->mustVerify($user)) {
            return null;
        }

        $settings = $this->settings->settings();

        if (! $settings->announcements && ! $settings->leaderboard) {
            return null;
        }

        $preferences = $this->preferences->find((string) $user->getAuthIdentifier());

        return [
            'announceRare' => $preferences->announceRare,
            'inLeaderboard' => $preferences->inLeaderboard,
            'hidden' => $preferences->hidden,
            'offersAnnouncements' => $settings->announcements,
            'offersLeaderboard' => $settings->leaderboard,
            'threshold' => $settings->announcementsRarityThreshold,
            'updateUrl' => route('achievements.account.update', [], false),
            'noticesUrl' => route('profile.show', ['tab' => 'mail'], false),
        ];
    }

    private function mustVerify(Authenticatable $user): bool
    {
        try {
            return $this->verification->mustVerify($user);
        } catch (Throwable $exception) {
            // As the route would: without knowing, the person is treated as having to verify
            Log::warning('Could not tell whether the email must be verified; the achievements tab is not shown.', [
                'user_id' => $user->getAuthIdentifier(),
                'exception' => $exception::class,
            ]);

            return true;
        }
    }
}
