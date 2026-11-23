<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

/**
 * Where one person stands in an achievement that repeats per season, for `/logros`: the season going
 * on (its key), whether they hold it this season, the earlier seasons they got it in (newest first)
 * and, while progress is on and they do not hold it yet, how far they are this season against
 * `target` (months in a row when `inMonths`). Only ever about the person viewing; nobody else's
 * standing is built.
 */
final readonly class SeasonStandingDTO
{
    /**
     * @param  list<string>  $pastSeasons
     */
    public function __construct(
        public string $season,
        public bool $heldNow = false,
        public array $pastSeasons = [],
        public ?int $progress = null,
        public ?int $target = null,
        public bool $inMonths = false,
    ) {}
}
