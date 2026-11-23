<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Recognitions;

use App\Application\Profiles\DTOs\PublicProfileDTO;
use App\Application\Profiles\Services\ProfileAccessRule;
use App\Infrastructure\Seo\CloudinaryImageUrlBuilder;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Infrastructure\Leaderboard\LeaderboardProfiles;

/**
 * Who among some accounts the viewer may be shown as a person to recognise (#11): a profile the
 * core's access rule lets the viewer see (deactivated, anonymized, hidden or stricter profiles go),
 * with a public name, and not hiding their achievements. Never decided by hand: the profiles are
 * described by the core and the rule is the core's.
 */
final readonly class RecognisablePeople
{
    private const int AVATAR_SIZE = 64;

    public function __construct(
        private LeaderboardProfiles $profiles,
        private ProfileAccessRule $access,
        private AchievementPreferencesRepositoryInterface $preferences,
        private CloudinaryImageUrlBuilder $cloudinary,
    ) {}

    /**
     * @param  list<string>  $userIds  account ids in lower case
     * @return array<string, array{handle: string, publicName: string, profileUrl: string, avatarUrl: string|null}>
     */
    public function among(array $userIds, Authenticatable $viewer): array
    {
        if ($userIds === []) {
            return [];
        }

        $described = $this->profiles->describe($userIds);
        $visible = array_flip(array_map(
            strtolower(...),
            $this->access->visibleAmong(array_values(array_map(static fn (array $one): PublicProfileDTO => $one['profile'], $described)), $viewer),
        ));
        $people = [];

        foreach ($this->preferences->forUsers(array_keys(array_intersect_key($described, $visible))) as $userId => $choices) {
            $profile = $described[$userId]['profile'] ?? null;
            $name = $profile?->shownPublicName();

            if ($choices->hidden || ! $profile instanceof PublicProfileDTO || $name === null) {
                continue;
            }

            $people[$userId] = [
                'handle' => $profile->handle,
                'publicName' => $name,
                'profileUrl' => route('profiles.show', ['handle' => $profile->handle], absolute: false),
                'avatarUrl' => $this->avatar($described[$userId]['avatarPublicId']),
            ];
        }

        return $people;
    }

    /**
     * The public name of each account that has one (not deactivated nor anonymized), whatever its
     * profile's visibility: the other side of the owner's own recognitions, who gave one to the
     * owner or was picked by them. The profile link only while the owner may see that profile.
     *
     * @param  list<string>  $userIds  account ids in lower case
     * @return array<string, array{publicName: string, profileUrl: string|null}>
     */
    public function named(array $userIds, Authenticatable $viewer): array
    {
        if ($userIds === []) {
            return [];
        }

        $described = $this->profiles->describe($userIds);
        $visible = array_flip(array_map(
            strtolower(...),
            $this->access->visibleAmong(array_values(array_map(static fn (array $one): PublicProfileDTO => $one['profile'], $described)), $viewer),
        ));
        $named = [];

        foreach ($described as $userId => $one) {
            $profile = $one['profile'];
            $name = $profile->shownPublicName();

            if ($name === null || $profile->deactivated || $profile->anonymized) {
                continue;
            }

            $named[(string) $userId] = [
                'publicName' => $name,
                'profileUrl' => isset($visible[$userId]) ? route('profiles.show', ['handle' => $profile->handle], absolute: false) : null,
            ];
        }

        return $named;
    }

    private function avatar(?string $publicId): ?string
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        $url = $this->cloudinary->avatar($publicId, self::AVATAR_SIZE);

        return $url !== null && str_starts_with($url, 'https://') ? $url : null;
    }
}
