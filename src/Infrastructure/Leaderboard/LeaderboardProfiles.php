<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Leaderboard;

use App\Application\Profiles\DTOs\PublicProfileDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Profiles\Services\PublicProfileService;
use App\Infrastructure\Profiles\StoredProfiles;
use Illuminate\Contracts\Container\Container;

/**
 * The profiles of the leaderboard's candidates as the core's access rule needs them, described by
 * the core itself (PublicProfileService::describe(), what its badges and card use), so the rule
 * decides as it does on the profile page. Accounts without a profile row have no public profile
 * and are left out.
 */
final readonly class LeaderboardProfiles
{
    private const int CHUNK = 500;

    public function __construct(
        private Container $container,
    ) {}

    /**
     * @param  list<string>  $userIds  account ids in lower case
     * @return array<string, array{profile: PublicProfileDTO, avatarPublicId: string|null}>
     */
    public function describe(array $userIds): array
    {
        $described = [];
        $stored = $this->container->make(StoredProfiles::class);

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $rows = $stored->findMany($chunk);

            if ($rows === []) {
                continue;
            }

            $owners = UserModel::withTrashed()->whereKey(array_keys($rows))->get()->keyBy(
                static fn (UserModel $owner): string => strtolower((string) $owner->id),
            );

            foreach ($rows as $userId => $row) {
                $owner = $owners->get($userId);

                if ($owner instanceof UserModel) {
                    $described[$userId] = [
                        'profile' => PublicProfileService::describe($row, $owner),
                        'avatarPublicId' => $owner->avatar_public_id,
                    ];
                }
            }
        }

        return $described;
    }
}
