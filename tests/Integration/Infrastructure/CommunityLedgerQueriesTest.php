<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Integration\Infrastructure;

use App\Application\Services\SettingsServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Time\StorageTimezoneResolver;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Modules\ModuleTestCase;

final class CommunityLedgerQueriesTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private const string SESSIONS = 'game-tables.session';

    private const string PEPA = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e62';

    private const string RAUL = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e63';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::ANA, self::LUIS, self::MARTA, self::PEPA, self::RAUL] as $id) {
            $this->account($id);
        }
    }

    public function test_each_person_met_counts_once_from_the_first_shared_subject(): void
    {
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::ANA], players: [self::LUIS, self::MARTA]);
        $this->ledgerSession('s2', '2026-02-10 18:00:00', director: [self::ANA], players: [self::LUIS, self::PEPA]);

        $meetings = $this->repository()->firstMeetings([self::LUIS], 'game_session', 'player', null);

        // Ana directed both, Marta and Pepa played once each: three people, Ana and Marta first met in January
        $this->assertSame([self::LUIS], array_keys($meetings));
        $this->assertSame(['2026-01-10 18:00:00', '2026-01-10 18:00:00', '2026-02-10 18:00:00'], $this->formatted($meetings[self::LUIS]));
    }

    public function test_the_roles_say_whose_part_counts(): void
    {
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::ANA], players: [self::LUIS, self::MARTA]);
        $this->ledgerSession('s2', '2026-02-10 18:00:00', director: [self::LUIS], players: [self::ANA, self::PEPA]);

        $directed = $this->repository()->firstMeetings([self::ANA, self::LUIS], 'game_session', 'director', 'player');
        $playedWithPlayers = $this->repository()->firstMeetings([self::LUIS], 'game_session', 'player', 'player');

        $this->assertCount(2, $directed[self::ANA]);
        $this->assertCount(2, $directed[self::LUIS]);
        // In s1 Luis played with Marta only (Ana directed it); in s2 he directed
        $this->assertCount(1, $playedWithPlayers[self::LUIS]);
    }

    public function test_nobody_counts_themselves_and_an_account_with_nobody_is_missing(): void
    {
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::ANA], players: [self::ANA]);

        $this->assertSame([], $this->repository()->firstMeetings([self::ANA, self::RAUL], 'game_session', null, null));
    }

    public function test_anonymized_people_never_count_but_deactivated_ones_do(): void
    {
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::ANA], players: [self::LUIS, self::MARTA, self::PEPA]);
        UserModel::query()->whereKey(self::MARTA)->update(['anonymized_at' => now()]);
        UserModel::query()->whereKey(self::PEPA)->delete();

        $meetings = $this->repository()->firstMeetings([self::ANA], 'game_session', 'director', 'player');

        $this->assertCount(2, $meetings[self::ANA]);
    }

    public function test_accounts_deleted_for_good_never_count(): void
    {
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::ANA], players: [self::LUIS, self::MARTA]);
        // Their facts stay without a foreign key until anything else drops them
        UserModel::withTrashed()->findOrFail(self::MARTA)->forceDelete();

        $meetings = $this->repository()->firstMeetings([self::ANA], 'game_session', 'director', 'player');
        $this->assertCount(1, $meetings[self::ANA]);
        $this->assertCount(1, DB::table('achievements_facts')->where('user_id', self::MARTA)->get());
    }

    public function test_one_person_met_in_two_subjects_at_the_same_instant_counts_once(): void
    {
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::ANA], players: [self::LUIS]);
        $this->ledgerSession('s2', '2026-01-10 18:00:00', director: [self::ANA], players: [self::LUIS]);

        $this->assertCount(1, $this->repository()->firstMeetings([self::LUIS], 'game_session', null, null)[self::LUIS]);
    }

    public function test_instants_come_back_as_the_same_instant_when_stored_in_madrid(): void
    {
        $this->storeIn('Europe/Madrid');
        // Summer time: 18:00 UTC is 20:00 in Madrid, the wall time written to the table
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', [
            $this->recordedFact(self::ANA, 'director', '2026-07-10T18:00:00+00:00'),
            $this->recordedFact(self::LUIS, 'player', '2026-07-10T18:00:00+00:00'),
        ]);

        $this->assertSame('2026-07-10 20:00:00', (string) DB::table('achievements_facts')->where('user_id', self::LUIS)->value('occurred_at'));
        $met = $this->repository()->firstMeetings([self::LUIS], 'game_session', null, null)[self::LUIS][0];

        $this->assertSame(new DateTimeImmutable('2026-07-10T18:00:00+00:00')->getTimestamp(), $met->getTimestamp());
    }

    public function test_it_lists_who_shares_any_subject_with_an_account(): void
    {
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::ANA], players: [self::LUIS]);
        $this->ledgerSubject('tournaments.tournament', 't1', 'tournament', [$this->recordedFact(self::LUIS), $this->recordedFact(self::MARTA)]);

        $this->assertSame([self::ANA, self::MARTA], $this->repository()->userIdsSharingSubjectsWith(self::LUIS));
        $this->assertSame([], $this->repository()->userIdsSharingSubjectsWith(self::RAUL));
    }

    public function test_other_kinds_never_count(): void
    {
        $this->ledgerSubject('tournaments.tournament', 't1', 'tournament', [$this->recordedFact(self::ANA), $this->recordedFact(self::LUIS)]);

        $this->assertSame([], $this->repository()->firstMeetings([self::ANA], 'game_session', null, null));
        $this->assertCount(1, $this->repository()->firstMeetings([self::ANA], 'tournament', null, null)[self::ANA]);
    }

    /**
     * @param  list<string>  $director
     * @param  list<string>  $players
     */
    private function ledgerSession(string $id, string $when, array $director, array $players): void
    {
        $facts = [
            ...array_map(fn (string $user): RecordedFact => $this->recordedFact($user, 'director', $when), $director),
            ...array_map(fn (string $user): RecordedFact => $this->recordedFact($user, 'player', $when), $players),
        ];

        $this->ledgerSubject(self::SESSIONS, $id, 'game_session', $facts);
    }

    private function storeIn(string $zone): void
    {
        $now = now()->toDateTimeString();
        DB::table('settings')->updateOrInsert(
            ['key' => StorageTimezoneResolver::SETTING_KEY],
            ['id' => (string) Str::uuid(), 'value' => $zone, 'created_at' => $now, 'updated_at' => $now],
        );
        (new StorageTimezoneResolver)->apply($this->app);
        $this->app->make(SettingsServiceInterface::class)->clearCache();
    }

    /**
     * @param  list<DateTimeImmutable>  $instants
     * @return list<string>
     */
    private function formatted(array $instants): array
    {
        return array_map(static fn (DateTimeImmutable $instant): string => $instant->format('Y-m-d H:i:s'), $instants);
    }

    private function repository(): AchievementFactRepositoryInterface
    {
        return $this->app->make(AchievementFactRepositoryInterface::class);
    }
}
