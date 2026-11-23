<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Rarity;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use stdClass;
use Throwable;

/**
 * The nightly photo (`achievements_snapshots`, key `daily`): its instant (03:30 of the display day)
 * and every rarity, taken only by RefreshAchievementRarityJob, never on a grant or a revoke, and never
 * computed on reading. Without a readable photo there is no rarity and others see nothing new.
 *
 * Active people: a fact in the twelve months before the photo, an account that still exists, not
 * deactivated or anonymized. Holders: those of them holding each achievement (any season).
 * Bound "scoped": a page reads the photo once.
 */
final class NightlyAchievementRarity implements AchievementRarityInterface
{
    public const string KEY = 'daily';

    public const int MONTHS_ACTIVE = 12;

    /** The photo's time of day, in the display zone (the nightly schedule's) */
    public const string TAKEN_AT = '03:30';

    /** Older than this, the nightly job has stopped: warned once a day */
    public const int STALE_AFTER_HOURS = 26;

    private const string TABLE = 'achievements_snapshots';

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    private ?AchievementRarityDTO $read = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TimezoneServiceInterface $time,
        private readonly AchievementWarnings $warnings,
    ) {}

    public function current(): AchievementRarityDTO
    {
        if ($this->read instanceof AchievementRarityDTO) {
            return $this->read;
        }

        try {
            $row = $this->db->table(self::TABLE)->where('key', self::KEY)->first(['taken_at', 'data']);
        } catch (Throwable $exception) {
            // Fails closed: no rarity, and nobody else sees anybody's achievements
            $this->warn('snapshot_unreadable', $exception);

            return $this->read = AchievementRarityDTO::unknown();
        }

        $read = $row instanceof stdClass ? $this->fromRow($row) : AchievementRarityDTO::unknown();
        $this->warnIfStale($read->takenAt);

        return $this->read = $read;
    }

    public function refresh(): AchievementRarityDTO
    {
        $takenAt = $this->photoInstant();
        $stamp = $takenAt->format(self::STORAGE_FORMAT);
        $since = $this->time->toStorage($this->time->today()->modify('-'.self::MONTHS_ACTIVE.' months'))->format(self::STORAGE_FORMAT);
        $activePeople = $this->activePeople($since)->distinct()->count('achievements_facts.user_id');
        $shares = [];

        if ($activePeople > 0) {
            $rows = $this->db->table('achievements_grants')
                ->whereIn('user_id', $this->activePeople($since)->distinct()->select('achievements_facts.user_id'))
                // Strictly before: a grant written in the photo's own second waits for the next one
                ->where('granted_at', '<', $stamp)
                ->selectRaw('achievement_id, count(distinct user_id) as holders')
                ->groupBy('achievement_id')
                ->get();

            foreach ($rows as $row) {
                $shares[strtolower((string) $row->achievement_id)] = round(100 * (int) $row->holders / $activePeople, 2);
            }
        }

        $this->db->table(self::TABLE)->upsert(
            [[
                'key' => self::KEY,
                'taken_at' => $stamp,
                'data' => json_encode(['activePeople' => $activePeople, 'shares' => $shares], JSON_THROW_ON_ERROR),
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]],
            ['key'],
            ['taken_at', 'data', 'updated_at'],
        );

        return $this->read = new AchievementRarityDTO($activePeople, $shares, $this->time->iso($takenAt), $takenAt);
    }

    /**
     * 03:30 of today in the display zone, as a storage instant (yesterday's before that time): a
     * queue that runs late never moves the line to midday.
     */
    private function photoInstant(): DateTimeImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', self::TAKEN_AT));
        $today = $this->time->today()->setTime($hour, $minute);
        $now = DateTimeImmutable::createFromInterface(Date::now());

        return $this->time->toStorage($today > $now ? $today->modify('-1 day') : $today);
    }

    private function warnIfStale(?DateTimeImmutable $takenAt): void
    {
        if (! $takenAt instanceof DateTimeImmutable) {
            return;
        }

        $age = Date::now()->getTimestamp() - $takenAt->getTimestamp();

        if ($age > self::STALE_AFTER_HOURS * 3600) {
            $this->warnings->warn('snapshot_stale', '[Achievements] The nightly photo is older than a day: is the scheduler running?', [
                'reason' => 'snapshot_stale',
                'hours' => intdiv($age, 3600),
            ]);
        }
    }

    private function fromRow(stdClass $row): AchievementRarityDTO
    {
        try {
            $data = json_decode((string) $row->data, true, 8, JSON_THROW_ON_ERROR);
            $takenAt = new DateTimeImmutable(substr((string) $row->taken_at, 0, 19), new DateTimeZone(date_default_timezone_get()));
        } catch (Throwable $exception) {
            $this->warn('snapshot_malformed', $exception);

            return AchievementRarityDTO::unknown();
        }

        $read = AchievementRarityDTO::fromArray($data);

        if (! $read instanceof AchievementRarityDTO) {
            $this->warnings->warn('snapshot_malformed', '[Achievements] The nightly photo cannot be read.', ['reason' => 'snapshot_malformed']);

            return AchievementRarityDTO::unknown();
        }

        return new AchievementRarityDTO($read->activePeople, $read->shares, $this->time->iso($takenAt), $takenAt);
    }

    private function activePeople(string $since): Builder
    {
        return $this->db->table('achievements_facts')
            ->join('users', 'users.id', '=', 'achievements_facts.user_id')
            ->where('achievements_facts.occurred_at', '>=', $since)
            ->whereNull('users.deleted_at')
            ->whereNull('users.anonymized_at');
    }

    private function warn(string $reason, Throwable $exception): void
    {
        $this->warnings->warn($reason, '[Achievements] The nightly photo could not be read.', [
            'reason' => $reason,
            'exception' => $exception::class,
        ]);
    }
}
