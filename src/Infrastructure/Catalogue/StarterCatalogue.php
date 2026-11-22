<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Catalogue;

use App\Domain\Activity\ActivityKind;
use App\Domain\Activity\ActivityRole;
use App\Domain\Profiles\ProfileBadgeColour;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Application\Services\StarterCatalogueInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\YearsBasis;
use Modules\Achievements\Infrastructure\Observers\EvaluateChangedAchievements;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;

/**
 * Every key created is written to `achievements_starter_keys`: one deleted later and created again
 * starts switched off, so running the command never brings back a badge somebody chose to remove
 * as a working one. One evaluation is queued for everything it created.
 */
final readonly class StarterCatalogue implements StarterCatalogueInterface
{
    private const string KEYS_TABLE = 'achievements_starter_keys';

    public function __construct(
        private ActivityMetricsInterface $metrics,
        private ConnectionInterface $db,
    ) {}

    public function install(): array
    {
        $created = [];
        $active = [];
        $createdBefore = array_fill_keys(array_map(strval(...), $this->db->table(self::KEYS_TABLE)->pluck('starter_key')->all()), true);

        EvaluateChangedAchievements::muted(function () use (&$created, &$active, $createdBefore): void {
            foreach ($this->definitions() as $key => $definition) {
                $model = $this->create($key, $definition, isset($createdBefore[$key]));

                if ($model instanceof AchievementModel) {
                    $created[] = $key;

                    if ($model->is_active) {
                        $active[] = $model->id;
                    }
                }
            }
        });

        if ($active !== []) {
            EvaluateAchievementsJob::dispatch($active);
        }

        return $created;
    }

    /**
     * @param  array{string, ProfileBadgeColour, int, AchievementRule}  $definition
     */
    private function create(string $key, array $definition, bool $createdBefore): ?AchievementModel
    {
        [$icon, $colour, $points, $rule] = $definition;

        if (AchievementModel::query()->where('starter_key', $key)->exists()) {
            return null;
        }

        $kind = $rule->evaluatedKind();

        try {
            return $this->db->transaction(function () use ($key, $icon, $colour, $points, $rule, $kind, $createdBefore): AchievementModel {
                $this->db->table(self::KEYS_TABLE)->insertOrIgnore([
                    'starter_key' => $key,
                    'created_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                ]);

                return AchievementModel::query()->create([
                    'name' => (string) __('achievements::starter.'.$key.'.name'),
                    'description' => (string) __('achievements::starter.'.$key.'.description'),
                    'icon' => $icon,
                    'colour' => $colour->value,
                    'points' => $points,
                    'is_secret' => false,
                    // Created again after a deletion: switched off, the site chose to drop it
                    'is_active' => ! $createdBefore && $kind !== null && $this->metrics->hasSource($kind),
                    'rule' => $rule->toArray(),
                    'starter_key' => $key,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Another run created it meanwhile
            return null;
        }
    }

    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return array<string, array{string, ProfileBadgeColour, int, AchievementRule}>
     */
    private function definitions(): array
    {
        $session = ActivityKind::GameSession->value;
        $player = ActivityRole::Player->value;
        $director = ActivityRole::Director->value;

        return [
            'welcome_first_game' => ['dices', ProfileBadgeColour::Green, 5, $this->count($session)],
            'welcome_profile_completed' => ['sprout', ProfileBadgeColour::Teal, 5, $this->count(ActivityKind::ProfileCompleted->value)],
            'welcome_first_forum_post' => ['message-square', ProfileBadgeColour::Sky, 5, $this->count(ActivityKind::ForumPost->value, role: ActivityRole::Author->value)],
            'games_played_1' => ['dices', ProfileBadgeColour::Blue, 5, $this->count($session, role: $player)],
            'games_played_10' => ['dices', ProfileBadgeColour::Blue, 10, $this->count($session, 10, $player)],
            'games_played_50' => ['star', ProfileBadgeColour::Blue, 25, $this->count($session, 50, $player)],
            'games_played_100' => ['trophy', ProfileBadgeColour::Amber, 50, $this->count($session, 100, $player)],
            'games_run_1' => ['book-open', ProfileBadgeColour::Violet, 10, $this->count($session, role: $director)],
            'games_run_10' => ['scroll-text', ProfileBadgeColour::Violet, 25, $this->count($session, 10, $director)],
            'systems_3' => ['compass', ProfileBadgeColour::Orange, 10, $this->rule(AchievementRule::distinct($session, 'game_system', 3))],
            'systems_10' => ['map', ProfileBadgeColour::Orange, 25, $this->rule(AchievementRule::distinct($session, 'game_system', 10))],
            'campaign_completed' => ['castle', ProfileBadgeColour::Red, 20, $this->count(ActivityKind::Campaign->value, role: $player)],
            'tournament_first' => ['swords', ProfileBadgeColour::Pink, 10, $this->count(ActivityKind::Tournament->value, role: $player)],
            'tournament_podium' => ['medal', ProfileBadgeColour::Amber, 25, $this->rule(AchievementRule::count(ActivityKind::Tournament->value, 1, $player, placementMax: 3))],
            'events_5' => ['calendar-check', ProfileBadgeColour::Green, 10, $this->count(ActivityKind::Event->value, 5, ActivityRole::Attendee->value)],
            'years_1' => ['hourglass', ProfileBadgeColour::Teal, 10, $this->rule(AchievementRule::years(YearsBasis::Account, 1))],
            'years_3' => ['hourglass', ProfileBadgeColour::Teal, 20, $this->rule(AchievementRule::years(YearsBasis::Account, 3))],
            'years_5' => ['gem', ProfileBadgeColour::Teal, 30, $this->rule(AchievementRule::years(YearsBasis::Account, 5))],
            'first_proposal' => ['vote', ProfileBadgeColour::Sky, 10, $this->count(ActivityKind::Proposal->value, role: ActivityRole::Proposer->value)],
            'first_table_played' => ['flag', ProfileBadgeColour::Pink, 10, $this->count($session, role: ActivityRole::Creator->value)],
        ];
    }

    private function count(string $kind, int $threshold = 1, ?string $role = null): AchievementRule
    {
        return $this->rule(AchievementRule::count($kind, $threshold, $role));
    }

    private function rule(?AchievementRule $rule): AchievementRule
    {
        return $rule ?? throw new LogicException('A rule of the starter catalogue cannot be read.');
    }
}
