<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\Services\AchievementRarityInterface;

/**
 * Rarity as a test sets it: what the ledger would say is not computed, and a grant changes nothing.
 * Without a `takenAt` of its own the photo is taken whenever it is read, unless `photo` is false.
 */
final readonly class FixedAchievementRarity implements AchievementRarityInterface
{
    public function __construct(
        private AchievementRarityDTO $rarity,
        private bool $photo = true,
    ) {}

    public function current(): AchievementRarityDTO
    {
        if (! $this->photo) {
            return new AchievementRarityDTO($this->rarity->activePeople, $this->rarity->shares);
        }

        if ($this->rarity->takenAt instanceof DateTimeImmutable) {
            return $this->rarity;
        }

        $now = DateTimeImmutable::createFromInterface(Date::now());

        return new AchievementRarityDTO($this->rarity->activePeople, $this->rarity->shares, $now->format(DATE_ATOM), $now);
    }

    public function refresh(): AchievementRarityDTO
    {
        return $this->current();
    }
}
