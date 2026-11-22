<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Application\DTOs\ShowcaseItemDTO;
use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;

/**
 * What a profile shows of its owner's achievements: the profile section, the count and the
 * featured badges all read it. It never decides who may see the profile (the core's access rule
 * did, before anything here is asked); it decides what the owner's choices and the secrets let a
 * viewer see:
 *
 * - an owner who hid their achievements shows nothing to anybody else;
 * - a secret achievement shows as «Logro secreto» (with no rarity) to anybody who does not hold it,
 *   unless the owner featured it, which shows it to everybody;
 * - only totals: never the tables, sessions or events behind an achievement.
 */
interface AchievementShowcaseInterface
{
    public function preferences(string $userId): AchievementPreferences;

    /**
     * Whether a viewer sees the owner's achievements at all: the owner always, anybody else unless
     * the owner hid them.
     */
    public function isVisibleTo(string $ownerId, ?string $viewerId): bool;

    /**
     * The owner's achievements, newest first, as the viewer may see them. Empty when they are hidden
     * from the viewer.
     *
     * @return list<ShowcaseItemDTO>
     */
    public function earned(string $ownerId, ?string $viewerId): array;

    /**
     * What the owner does not hold yet and can still get, with their progress, closest first; the
     * secret ones masked and last. Only for the owner, and empty while progress is switched off.
     *
     * @return list<ShowcaseItemDTO>
     */
    public function locked(string $ownerId): array;

    /**
     * The catalogue for `/logros`: what the viewer holds (whatever its state now), plus every
     * active achievement that can still be got, automatically or by hand. Left out: those without a
     * source, of a kind switched off, secret while secrets are off, or with a broken rule. Secret
     * ones the viewer does not hold come masked; sorted by the name shown, then id.
     *
     * @return list<AchievementViewDTO>
     */
    public function catalogueFor(?string $viewerId): array;

    /**
     * How many achievements the owner holds.
     */
    public function heldCount(string $ownerId): int;

    /**
     * Featured badges of each account, in one read for all of them: the held ones the owner chose,
     * in their order, else the rarest held ones that are not secret (the newest while rarity is
     * off). Accounts that hid their achievements, or with nothing to feature, are missing.
     *
     * @param  list<string>  $userIds
     * @return array<string, list<AchievementViewDTO>>
     */
    public function featured(array $userIds): array;
}
