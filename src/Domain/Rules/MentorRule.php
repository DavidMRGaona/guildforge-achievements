<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

use DateTimeImmutable;
use Modules\Achievements\Domain\ValueObjects\SubjectHosts;

/**
 * Who welcomed a newcomer (achievements#24): whoever ran their first game session as a player.
 *
 * A first game is decided only once it is more than GRACE_DAYS old: a table is often marked
 * completed days after it was played, so an earlier one has time to arrive and nobody becomes the
 * mentor of a game that was not really the first. The nightly batch decides it and grants with its
 * own date, never the game's, so the grant does not tell others which game it was.
 *
 * A newcomer has at most one mentor:
 * - two first games at the same instant (a sign-up mistake, say): none, there is no first one;
 * - one director (other than the newcomer): that one;
 * - several directors: the one who also created the table, who brought the newcomer in; when none
 *   of them created it there is no fair pick, so none (a pick by account id would be a lottery).
 */
final class MentorRule
{
    public const int GRACE_DAYS = AnnouncementWindow::ACTIVITY_DAYS;

    /**
     * When a first game can be decided on: GRACE_DAYS after it. The instant the mentor rule counts
     * for that newcomer, so a grant is announced only when that decision is recent.
     */
    public static function decidedAt(DateTimeImmutable $firstGameAt): DateTimeImmutable
    {
        return $firstGameAt->modify('+'.self::GRACE_DAYS.' days');
    }

    /**
     * More than GRACE_DAYS have gone by since the first game.
     */
    public static function isDecided(DateTimeImmutable $firstGameAt, DateTimeImmutable $now): bool
    {
        return self::decidedAt($firstGameAt) < $now;
    }

    /**
     * The newcomer's mentor, or null when there is none.
     *
     * @param  list<SubjectHosts>  $firstGames  the subjects of the newcomer's first game
     */
    public static function mentor(string $newcomerId, array $firstGames): ?string
    {
        if (count($firstGames) !== 1) {
            return null;
        }

        $newcomerId = strtolower($newcomerId);
        $directors = array_values(array_filter($firstGames[0]->directors, static fn (string $id): bool => $id !== $newcomerId));

        if (count($directors) <= 1) {
            return $directors[0] ?? null;
        }

        $creators = array_values(array_intersect($directors, $firstGames[0]->creators));

        return count($creators) === 1 ? $creators[0] : null;
    }
}
