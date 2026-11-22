<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * What one person chose about their own achievements.
 *
 * - `hidden`: only the owner sees them (the profile section, the count and the featured badges).
 * - `featured`: ids of up to three achievements, in the owner's order. Only held ones are ever
 *   shown, so an id that stopped being held (revoked, deleted) is skipped where it is read; an empty
 *   list means «not chosen», and the rarest held ones are featured instead.
 * - `announceRare`: whoever may see the profile hears of the owner's rare achievements and those of
 *   challenges (achievements#6); off until the owner turns it on.
 * - `inLeaderboard`: the owner appears in the leaderboard; on until the owner leaves it.
 */
final readonly class AchievementPreferences
{
    public const int MAX_FEATURED = 3;

    /** @var list<string> */
    public array $featured;

    /**
     * @param  list<string>  $featured
     */
    public function __construct(
        public string $userId,
        public bool $hidden = false,
        array $featured = [],
        public bool $announceRare = false,
        public bool $inLeaderboard = true,
    ) {
        $this->featured = self::normalise($featured);
    }

    public static function none(string $userId): self
    {
        return new self(strtolower($userId));
    }

    /**
     * Ids in lower case, each once, at most MAX_FEATURED, in the order given.
     *
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    public static function normalise(array $ids): array
    {
        $kept = [];

        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }

            $id = strtolower($id);

            if (! in_array($id, $kept, true)) {
                $kept[] = $id;
            }

            if (count($kept) === self::MAX_FEATURED) {
                break;
            }
        }

        return $kept;
    }

    public function hasChosenFeatured(): bool
    {
        return $this->featured !== [];
    }

    /**
     * @param  list<string>  $featured
     */
    public function with(bool $hidden, array $featured): self
    {
        return new self($this->userId, $hidden, $featured, $this->announceRare, $this->inLeaderboard);
    }

    /**
     * The choices of the account settings' tab, keeping what the profile section chose.
     */
    public function withChoices(bool $announceRare, bool $inLeaderboard): self
    {
        return new self($this->userId, $this->hidden, $this->featured, $announceRare, $inLeaderboard);
    }

    /**
     * Whether the person may appear in the leaderboard by their own choice (#10): not while their
     * achievements are hidden, nor once they left it.
     */
    public function appearsInLeaderboard(): bool
    {
        return ! $this->hidden && $this->inLeaderboard;
    }
}
