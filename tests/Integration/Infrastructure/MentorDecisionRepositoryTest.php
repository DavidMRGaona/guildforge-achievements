<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Integration\Infrastructure;

use App\Application\Services\SettingsServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Time\StorageTimezoneResolver;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Achievements\Domain\Repositories\MentorDecisionRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\MentorDecision;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Modules\ModuleTestCase;

final class MentorDecisionRepositoryTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private const string SESSIONS = 'game-tables.session';

    private const string PEPA = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e62';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::ANA, self::LUIS, self::MARTA, self::PEPA] as $id) {
            $this->account($id);
        }
    }

    public function test_it_lists_the_players_not_decided_yet_in_pages(): void
    {
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', [
            $this->recordedFact(self::ANA, 'director'),
            $this->recordedFact(self::LUIS),
            $this->recordedFact(self::MARTA),
            $this->recordedFact(self::PEPA),
        ]);
        $this->ledgerSubject('game-tables.campaign', 'c1', 'campaign', [$this->recordedFact(self::ANA)]);
        $this->repository()->record([new MentorDecision(self::MARTA, self::ANA, new DateTimeImmutable('2026-03-28 18:00:00'))]);

        $this->assertSame([self::LUIS], $this->repository()->undecidedNewcomers('game_session', 'player', null, 1));
        $this->assertSame([self::PEPA], $this->repository()->undecidedNewcomers('game_session', 'player', self::LUIS, 1));
        $this->assertSame([], $this->repository()->undecidedNewcomers('game_session', 'player', self::PEPA, 1));
    }

    public function test_anonymized_and_deleted_accounts_are_never_listed(): void
    {
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', [$this->recordedFact(self::LUIS), $this->recordedFact(self::MARTA), $this->recordedFact(self::PEPA)]);
        UserModel::query()->whereKey(self::MARTA)->update(['anonymized_at' => now()]);
        UserModel::withTrashed()->findOrFail(self::PEPA)->forceDelete();

        $this->assertSame([self::LUIS], $this->repository()->undecidedNewcomers('game_session', 'player', null, 10));
    }

    public function test_a_decision_is_written_once_and_never_replaced(): void
    {
        $when = new DateTimeImmutable('2026-03-28 18:00:00');

        $this->assertSame(1, $this->repository()->record([new MentorDecision(self::LUIS, self::ANA, $when)]));
        $this->assertSame(0, $this->repository()->record([new MentorDecision(self::LUIS, self::MARTA, $when)]));

        $this->assertSame(self::ANA, DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->value('mentor_id'));
    }

    public function test_a_newcomer_anonymized_after_being_listed_is_never_written(): void
    {
        $when = new DateTimeImmutable('2026-03-28 18:00:00');
        // Listed as undecided, then anonymized (and its listener done) before the batch writes
        UserModel::query()->whereKey(self::LUIS)->update(['anonymized_at' => now()]);
        UserModel::withTrashed()->findOrFail(self::PEPA)->forceDelete();

        $written = $this->repository()->record([new MentorDecision(self::LUIS, self::ANA, $when), new MentorDecision(self::PEPA, self::ANA, $when), new MentorDecision(self::MARTA, self::ANA, $when)]);

        $this->assertSame(1, $written);
        $this->assertSame([self::MARTA], DB::table('achievements_mentor_decisions')->pluck('newcomer_id')->all());
    }

    public function test_a_mentor_anonymized_after_the_first_games_were_read_is_written_as_nobody(): void
    {
        UserModel::query()->whereKey(self::ANA)->update(['anonymized_at' => now()]);

        $this->repository()->record([new MentorDecision(self::LUIS, self::ANA, new DateTimeImmutable('2026-03-28 18:00:00'))]);

        $this->assertSame(1, DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->count());
        $this->assertNull(DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->value('mentor_id'));
    }

    public function test_it_counts_each_mentor_s_newcomers_with_when_they_were_decided(): void
    {
        $this->repository()->record([
            new MentorDecision(self::LUIS, self::ANA, new DateTimeImmutable('2026-03-28 18:00:00')),
            new MentorDecision(self::MARTA, self::ANA, new DateTimeImmutable('2026-02-01 18:00:00')),
            new MentorDecision(self::PEPA, null, new DateTimeImmutable('2026-02-01 18:00:00')),
        ]);

        $welcomed = $this->repository()->welcomed([self::ANA, self::LUIS]);

        $this->assertSame([self::ANA], array_keys($welcomed));
        $this->assertSame(['2026-02-01 18:00:00', '2026-03-28 18:00:00'], array_map(static fn (DateTimeImmutable $at): string => $at->format('Y-m-d H:i:s'), $welcomed[self::ANA]));
    }

    public function test_a_newcomer_anonymized_or_deleted_meanwhile_never_counts(): void
    {
        $when = new DateTimeImmutable('2026-03-28 18:00:00');
        $this->repository()->record([new MentorDecision(self::LUIS, self::ANA, $when), new MentorDecision(self::MARTA, self::ANA, $when)]);
        UserModel::query()->whereKey(self::LUIS)->update(['anonymized_at' => now()]);
        UserModel::withTrashed()->findOrFail(self::MARTA)->forceDelete();

        $this->assertSame([], $this->repository()->welcomed([self::ANA]));
    }

    public function test_forgetting_an_account_erases_it_as_newcomer_and_empties_it_as_mentor(): void
    {
        $when = new DateTimeImmutable('2026-03-28 18:00:00');
        $this->repository()->record([new MentorDecision(self::LUIS, self::ANA, $when), new MentorDecision(self::ANA, self::MARTA, $when)]);

        $this->repository()->forgetAccount(self::ANA);

        $this->assertNull(DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->value('mentor_id'));
        $this->assertSame(1, DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->count());
        $this->assertSame(0, DB::table('achievements_mentor_decisions')->where('newcomer_id', self::ANA)->count());
    }

    public function test_the_instant_is_kept_when_stored_in_madrid(): void
    {
        $now = now()->toDateTimeString();
        DB::table('settings')->updateOrInsert(
            ['key' => StorageTimezoneResolver::SETTING_KEY],
            ['id' => (string) Str::uuid(), 'value' => 'Europe/Madrid', 'created_at' => $now, 'updated_at' => $now],
        );
        (new StorageTimezoneResolver)->apply($this->app);
        $this->app->make(SettingsServiceInterface::class)->clearCache();
        $when = new DateTimeImmutable('2026-07-10T18:00:00+00:00');

        $this->repository()->record([new MentorDecision(self::LUIS, self::ANA, $when)]);

        $this->assertSame('2026-07-10 20:00:00', (string) DB::table('achievements_mentor_decisions')->value('decided_at'));
        $this->assertSame($when->getTimestamp(), $this->repository()->welcomed([self::ANA])[self::ANA][0]->getTimestamp());
    }

    private function repository(): MentorDecisionRepositoryInterface
    {
        return $this->app->make(MentorDecisionRepositoryInterface::class);
    }
}
