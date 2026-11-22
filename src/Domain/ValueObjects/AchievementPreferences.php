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
 * - `leaderboard`: whether they appear in the leaderboard (#10); on unless they opt out.
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
        public bool $leaderboard = true,
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
        return new self($this->userId, $hidden, $featured, $this->leaderboard);
    }

    public function withLeaderboard(bool $leaderboard): self
    {
        return new self($this->userId, $this->hidden, $this->featured, $leaderboard);
    }

    /**
     * Whether the person may appear in the leaderboard by their own choice: not while their
     * achievements are hidden, nor once they opted out.
     */
    public function appearsInLeaderboard(): bool
    {
        return ! $this->hidden && $this->leaderboard;
    }
}
