<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Domain\ValueObjects\Level;

/**
 * One row of the leaderboard: a profile the viewer may see, by its public name (never the account
 * name), with its points and level. The rank counts only the rows the viewer sees.
 */
final readonly class LeaderboardEntryDTO
{
    public function __construct(
        public int $rank,
        public int $points,
        public string $publicName,
        public string $profileUrl,
        public ?string $avatarUrl,
        public ?Level $level,
        public bool $isViewer,
    ) {}

    /**
     * @return array{rank: int, points: int, publicName: string, profileUrl: string, avatarUrl: string|null, level: array{number: int, name: string}|null, isViewer: bool}
     */
    public function toArray(): array
    {
        return [
            'rank' => $this->rank,
            'points' => $this->points,
            'publicName' => $this->publicName,
            'profileUrl' => $this->profileUrl,
            'avatarUrl' => $this->avatarUrl,
            'level' => $this->level instanceof Level ? ['number' => $this->level->number, 'name' => $this->level->name] : null,
            'isViewer' => $this->isViewer,
        ];
    }
}
