<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Integration\Infrastructure;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Achievements\Domain\Entities\AchievementFact;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\FactAttribute;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Modules\ModuleTestCase;

final class EloquentAchievementFactRepositoryTest extends ModuleTestCase
{
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private const string TYPE = 'game-tables.session';

    public function test_it_stores_the_facts_of_a_subject(): void
    {
        $replacement = $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [
            $this->recordedFact(self::ANA, 'director', system: 'pf2'),
            $this->recordedFact(self::LUIS, 'player', system: 'pf2', placement: 1),
        ]);

        $this->assertTrue($replacement->changed);
        $this->assertSame([], $replacement->userIdsBefore);
        $this->assertSame([self::ANA, self::LUIS], $replacement->userIdsAfter);
        $this->assertSame([self::ANA, self::LUIS], $replacement->affectedUserIds());

        $facts = $this->repository()->forUser(self::LUIS);
        $this->assertCount(1, $facts);
        $fact = $facts[0];
        $this->assertSame(self::TYPE, $fact->sourceType);
        $this->assertSame('42', $fact->subjectId);
        $this->assertSame('player', $fact->role);
        $this->assertSame('game_session', $fact->kind);
        $this->assertSame(1, $fact->placement);
        $this->assertEquals(['game_system' => new FactAttribute('pf2', 'Pf2')], $fact->attributes);
        $this->assertSame('2026-03-14 18:00:00', $fact->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_the_instant_is_stored_in_the_storage_zone(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [
            $this->recordedFact(self::ANA, when: '2026-03-14T19:00:00+01:00'),
        ]);

        // The storage zone is whatever the site stores in (UTC on new sites, Madrid on older ones)
        $expected = new DateTimeImmutable('2026-03-14T18:00:00+00:00')->setTimezone(new DateTimeZone(date_default_timezone_get()));

        $this->assertSame($expected->format('Y-m-d H:i:s'), DB::table('achievements_facts')->value('occurred_at'));
        $this->assertSame($expected->getTimestamp(), $this->repository()->forUser(self::ANA)[0]->occurredAt->getTimestamp());
    }

    public function test_replacing_drops_who_is_no_longer_there_and_reports_both_sides(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [
            $this->recordedFact(self::ANA, 'director'),
            $this->recordedFact(self::LUIS),
        ]);

        $replacement = $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [
            $this->recordedFact(self::ANA, 'director'),
            $this->recordedFact(self::MARTA),
        ]);

        $this->assertTrue($replacement->changed);
        $this->assertSame([self::ANA, self::LUIS], $replacement->userIdsBefore);
        $this->assertSame([self::ANA, self::MARTA], $replacement->userIdsAfter);
        $this->assertSame([self::ANA, self::LUIS, self::MARTA], $replacement->affectedUserIds());
        $this->assertSame([], $this->repository()->forUser(self::LUIS));
        $this->assertSame([self::ANA, self::MARTA], $this->repository()->userIdsOfSubject(self::TYPE, '42'));
    }

    public function test_a_changed_detail_updates_the_row_in_place(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [$this->recordedFact(self::ANA, system: 'pf2')]);
        $id = DB::table('achievements_facts')->value('id');

        $replacement = $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [
            $this->recordedFact(self::ANA, when: '2026-03-15 10:00:00', system: 'dnd5', placement: 2),
        ]);

        $this->assertTrue($replacement->changed);
        $this->assertSame($id, DB::table('achievements_facts')->value('id'));
        $fact = $this->repository()->forUser(self::ANA)[0];
        $this->assertSame('dnd5', $fact->attributes['game_system']->id);
        $this->assertSame(2, $fact->placement);
        $this->assertSame('2026-03-15 10:00:00', $fact->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_replacing_with_the_same_facts_changes_nothing(): void
    {
        $facts = [$this->recordedFact(self::ANA, 'director', system: 'pf2'), $this->recordedFact(self::LUIS)];
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', $facts);
        $before = DB::table('achievements_facts')->orderBy('id')->get()->all();

        $replacement = $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', $facts);

        $this->assertFalse($replacement->changed);
        $this->assertSame([self::ANA, self::LUIS], $replacement->affectedUserIds());
        $this->assertEquals($before, DB::table('achievements_facts')->orderBy('id')->get()->all());
    }

    public function test_no_facts_removes_the_subject(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [$this->recordedFact(self::ANA)]);

        $replacement = $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', []);

        $this->assertTrue($replacement->changed);
        $this->assertSame([self::ANA], $replacement->userIdsBefore);
        $this->assertSame([], $replacement->userIdsAfter);
        $this->assertSame(0, DB::table('achievements_facts')->count());
    }

    public function test_previewing_reports_the_change_without_writing(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [$this->recordedFact(self::ANA)]);

        $replacement = $this->repository()->previewReplacement(self::TYPE, '42', 'game_session', [$this->recordedFact(self::LUIS)]);

        $this->assertTrue($replacement->changed);
        $this->assertSame([self::ANA, self::LUIS], $replacement->affectedUserIds());
        $this->assertSame([self::ANA], $this->repository()->userIdsOfSubject(self::TYPE, '42'));
    }

    public function test_subjects_are_kept_apart_by_type_and_id(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [$this->recordedFact(self::ANA)]);
        $this->repository()->replaceSubject(self::TYPE, '43', 'game_session', [$this->recordedFact(self::ANA)]);
        $this->repository()->replaceSubject('tournaments.tournament', '42', 'tournament', [$this->recordedFact(self::ANA, 'organizer')]);

        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', []);

        $this->assertSame(['43'], $this->repository()->subjectIds(self::TYPE));
        $this->assertSame(['42'], $this->repository()->subjectIds('tournaments.tournament'));
        $this->assertCount(2, $this->repository()->forUser(self::ANA));
    }

    public function test_facts_of_a_person_can_be_read_by_kind(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [$this->recordedFact(self::ANA, when: '2026-03-02 10:00:00')]);
        $this->repository()->replaceSubject(self::TYPE, '43', 'game_session', [$this->recordedFact(self::ANA, when: '2026-03-01 10:00:00')]);
        $this->repository()->replaceSubject('tournaments.tournament', '7', 'tournament', [$this->recordedFact(self::ANA, 'organizer')]);

        $sessions = $this->repository()->forUser(self::ANA, ['game_session']);

        $this->assertSame(['43', '42'], array_map(static fn (AchievementFact $fact): string => $fact->subjectId, $sessions));
        $this->assertCount(3, $this->repository()->forUser(self::ANA));
        $this->assertSame(2, $this->repository()->countForUser(self::ANA, 'game_session'));
        $this->assertSame(0, $this->repository()->countForUser(self::ANA, 'game_session', 'director'));
        $this->assertSame(1, $this->repository()->countForUser(self::ANA, 'tournament', 'organizer'));
    }

    public function test_facts_of_an_account_already_anonymized_when_written_are_dropped(): void
    {
        $anonymized = (string) UserModel::factory()->create(['anonymized_at' => now()])->id;

        $replacement = $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [
            $this->recordedFact(strtoupper($anonymized)),
            $this->recordedFact(self::LUIS),
        ]);

        $this->assertSame([self::LUIS], $replacement->userIdsAfter);
        $this->assertSame([self::LUIS], $this->repository()->userIdsOfSubject(self::TYPE, '42'));
        $this->assertSame([self::LUIS], $this->repository()->previewReplacement(self::TYPE, '43', 'game_session', [
            $this->recordedFact($anonymized),
            $this->recordedFact(self::LUIS),
        ])->userIdsAfter);
    }

    public function test_facts_of_several_accounts_are_read_at_once_by_kind(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '1', 'game_session', [$this->recordedFact(self::ANA), $this->recordedFact(self::LUIS)]);
        $this->repository()->replaceSubject(self::TYPE, '2', 'game_session', [$this->recordedFact(self::ANA, when: '2026-01-01 10:00:00')]);
        $this->repository()->replaceSubject('core.account', self::ANA, 'account', [$this->recordedFact(self::ANA, 'owner')]);

        $facts = $this->repository()->forUsers([strtoupper(self::ANA), self::LUIS, self::MARTA], ['game_session']);

        $this->assertSame([self::ANA, self::LUIS], array_keys($facts));
        $this->assertSame(['2', '1'], array_map(static fn (AchievementFact $fact): string => $fact->subjectId, $facts[self::ANA]));
        $this->assertCount(1, $facts[self::LUIS]);
        $this->assertCount(3, $this->repository()->forUsers([self::ANA])[self::ANA]);
    }

    public function test_accounts_with_facts_of_some_kinds_are_listed_in_keyset_pages(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '1', 'game_session', [$this->recordedFact(self::MARTA), $this->recordedFact(self::ANA)]);
        $this->repository()->replaceSubject(self::TYPE, '2', 'game_session', [$this->recordedFact(self::ANA)]);
        $this->repository()->replaceSubject('core.account', self::LUIS, 'account', [$this->recordedFact(self::LUIS, 'owner')]);

        $this->assertSame([self::ANA, self::MARTA], $this->repository()->userIdsWithKinds(['game_session'], null, 10));
        $this->assertSame([self::ANA], $this->repository()->userIdsWithKinds(['game_session', 'account'], null, 1));
        $this->assertSame([self::LUIS, self::MARTA], $this->repository()->userIdsWithKinds(['game_session', 'account'], self::ANA, 10));
        $this->assertSame([], $this->repository()->userIdsWithKinds([], null, 10));
    }

    public function test_an_account_id_is_read_in_lower_case(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [$this->recordedFact(self::ANA)]);

        $this->assertCount(1, $this->repository()->forUser(strtoupper(self::ANA)));
        $this->assertSame(1, $this->repository()->deleteForUser(strtoupper(self::ANA)));
    }

    public function test_every_fact_of_an_account_can_be_deleted(): void
    {
        $this->repository()->replaceSubject(self::TYPE, '42', 'game_session', [$this->recordedFact(self::ANA), $this->recordedFact(self::LUIS)]);
        $this->repository()->replaceSubject('tournaments.tournament', '7', 'tournament', [$this->recordedFact(self::ANA, 'organizer')]);

        $this->assertSame(2, $this->repository()->deleteForUser(self::ANA));

        $this->assertSame([], $this->repository()->forUser(self::ANA));
        $this->assertCount(1, $this->repository()->forUser(self::LUIS));
    }

    public function test_one_row_per_subject_person_and_role(): void
    {
        $row = [
            'source_type' => self::TYPE,
            'subject_id' => '42',
            'user_id' => self::ANA,
            'role' => 'player',
            'kind' => 'game_session',
            'occurred_at' => '2026-03-14 18:00:00',
            'attributes' => '{}',
        ];
        DB::table('achievements_facts')->insert(['id' => (string) Str::uuid7()] + $row);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('achievements_facts')->insert(['id' => (string) Str::uuid7()] + $row);
    }

    private function repository(): AchievementFactRepositoryInterface
    {
        return $this->app->make(AchievementFactRepositoryInterface::class);
    }
}
