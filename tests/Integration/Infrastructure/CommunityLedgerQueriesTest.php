<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Integration\Infrastructure;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
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

    public function test_other_kinds_never_count(): void
    {
        $this->ledgerSubject('tournaments.tournament', 't1', 'tournament', [$this->recordedFact(self::ANA), $this->recordedFact(self::LUIS)]);

        $this->assertSame([], $this->repository()->firstMeetings([self::ANA], 'game_session', null, null));
        $this->assertCount(1, $this->repository()->firstMeetings([self::ANA], 'tournament', null, null)[self::ANA]);
    }

    public function test_a_newcomer_counts_for_whoever_ran_their_first_game(): void
    {
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::ANA], players: [self::LUIS]);
        // Luis's second game: Marta is not his mentor; Pepa's first one, so she is Marta's newcomer
        $this->ledgerSession('s2', '2026-02-10 18:00:00', director: [self::MARTA], players: [self::LUIS, self::PEPA]);

        $welcomed = $this->repository()->newcomersWelcomed([self::ANA, self::MARTA], 'game_session', 'director', 'player');

        $this->assertSame(['2026-01-10 18:00:00'], $this->formatted($welcomed[self::ANA]));
        $this->assertSame(['2026-02-10 18:00:00'], $this->formatted($welcomed[self::MARTA]));
        $this->assertSame([self::ANA, self::MARTA], $this->repository()->hostsOfFirstFacts([self::LUIS, self::PEPA], 'game_session', 'director', 'player'));
    }

    public function test_a_first_game_run_by_two_people_counts_for_both_and_only_player_facts_are_first_games(): void
    {
        // Luis directed before he ever played: his first game as a player is still s2
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::LUIS], players: [self::PEPA]);
        $this->ledgerSession('s2', '2026-02-10 18:00:00', director: [self::ANA, self::MARTA], players: [self::LUIS]);

        $welcomed = $this->repository()->newcomersWelcomed([self::ANA, self::MARTA, self::LUIS], 'game_session', 'director', 'player');

        $this->assertCount(1, $welcomed[self::ANA]);
        $this->assertCount(1, $welcomed[self::MARTA]);
        $this->assertCount(1, $welcomed[self::LUIS]);
    }

    public function test_an_anonymized_newcomer_is_nobody_s_newcomer(): void
    {
        $this->ledgerSession('s1', '2026-01-10 18:00:00', director: [self::ANA], players: [self::LUIS]);
        UserModel::query()->whereKey(self::LUIS)->update(['anonymized_at' => now()]);

        $this->assertSame([], $this->repository()->newcomersWelcomed([self::ANA], 'game_session', 'director', 'player'));
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
