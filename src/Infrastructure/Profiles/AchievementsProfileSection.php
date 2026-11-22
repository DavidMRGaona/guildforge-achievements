<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Exceptions\NothingToDrawException;
use App\Application\Profiles\Contracts\ProfileSectionInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Services\EmailVerificationPolicyInterface;
use App\Domain\Profiles\ProfileRegion;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\ShowcaseItemDTO;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;
use Throwable;

/**
 * «Logros» on the public profile (`achievements.badges`): what the owner got, with its rarity and
 * date (the owner gets the day; anybody else the month alone, since grants of one evaluation share
 * their second); for the owner also what is left with their progress, the stars to feature up to
 * three and the switch that hides them from everybody else.
 *
 * The core drew the profile only after its access rule let the viewer see it; this section never
 * decides that again. It decides what the owner's choices and the secrets let the viewer see
 * (AchievementShowcaseInterface), and it never names a table, session or event: only totals.
 */
final readonly class AchievementsProfileSection implements ProfileSectionInterface
{
    public const string KEY = 'achievements.badges';

    public function __construct(
        private AchievementShowcaseInterface $showcase,
        private AchievementsSettingsInterface $settings,
        private ShowcasePresenter $presenter,
        private EmailVerificationPolicyInterface $verification,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('achievements::messages.profile.section.label');
    }

    public function description(): string
    {
        return __('achievements::messages.profile.section.description');
    }

    public function component(): string
    {
        return 'components/profileSections/AchievementsProfileSection.vue';
    }

    public function region(): ProfileRegion
    {
        return ProfileRegion::Main;
    }

    public function order(): int
    {
        return 20;
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function props(ProfileOwnerDTO $owner, ?Authenticatable $viewer): array
    {
        $viewerId = $viewer instanceof Authenticatable ? strtolower((string) $viewer->getAuthIdentifier()) : null;
        $isOwner = $viewerId === strtolower($owner->id);

        if (! $this->showcase->isVisibleTo($owner->id, $viewerId)) {
            throw NothingToDrawException::make();
        }

        $earned = $this->showcase->earned($owner->id, $viewerId);
        $locked = $isOwner ? $this->showcase->locked($owner->id) : [];

        // Secrets the viewer may not see are only counted: one by one, a date, a rarity or a
        // position would tie them to a secret revealed elsewhere (grants of one batch share their
        // second), and with it to the game or event behind it. The owner sees none masked.
        $secrets = count(array_filter($earned, static fn (ShowcaseItemDTO $item): bool => $item->achievement->masked));
        $earned = array_values(array_filter($earned, static fn (ShowcaseItemDTO $item): bool => ! $item->achievement->masked));

        if ($earned === [] && $locked === [] && $secrets === 0) {
            throw NothingToDrawException::make();
        }

        // An owner who must verify their email gets no controls, as the core's profile editor does
        $preferences = $isOwner && ! $this->mustVerify($viewer) ? $this->showcase->preferences($owner->id) : null;

        return [
            'earned' => array_map($this->presenter->earned(...), $earned),
            'secretCount' => $secrets,
            'locked' => array_map($this->presenter->locked(...), $locked),
            'showsRarity' => $this->settings->rarityEnabled(),
            'catalogueUrl' => route('achievements.index', absolute: false),
            'owner' => $preferences instanceof AchievementPreferences ? [
                'hidden' => $preferences->hidden,
                'chosen' => array_values(array_filter(
                    $preferences->featured,
                    static fn (string $id): bool => array_any($earned, static fn (ShowcaseItemDTO $item): bool => $item->achievement->id === $id),
                )),
                'maxFeatured' => AchievementPreferences::MAX_FEATURED,
                'updateUrl' => route('achievements.profile.update', ['handle' => $owner->handle], absolute: false),
            ] : null,
        ];
    }

    /**
     * Fails closed: a policy that throws counts as «must verify» (no controls).
     */
    private function mustVerify(?Authenticatable $viewer): bool
    {
        if (! $viewer instanceof Authenticatable) {
            return true;
        }

        try {
            return $this->verification->mustVerify($viewer);
        } catch (Throwable) {
            return true;
        }
    }
}
