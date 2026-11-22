<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Challenges;

use App\Application\Profiles\DTOs\ProfileCardDTO;
use App\Application\Profiles\Services\ProfileCardServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\ChallengeCompleterDTO;
use Modules\Achievements\Application\DTOs\ChallengeViewDTO;
use Modules\Achievements\Application\Services\AchievementCatalogueInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ChallengeBoardInterface;
use Modules\Achievements\Domain\Challenges\ChallengeAudience;
use Modules\Achievements\Domain\Challenges\ChallengeMetric;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use Modules\Achievements\Domain\Challenges\ChallengeState;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Entities\Challenge;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;

/**
 * Who completed a challenge is whoever added to it and holds its reward now (a reward taken away by
 * hand no longer counts). The list asks the core's profile card for each of them, so it only names
 * people whose profile the viewer may see, by their public name, and leaves out whoever hides their
 * achievements; it stops after a few, and the rest are only counted.
 */
final class ChallengeBoard implements ChallengeBoardInterface
{
    /** Cards asked for, at most, to fill the list of one challenge */
    private const int MAX_EXAMINED = 60;

    private const int CHUNK = 500;

    /** @var array<string, Achievement|null> */
    private array $rewards = [];

    public function __construct(
        private readonly AchievementsSettingsInterface $settings,
        private readonly ChallengeRepositoryInterface $challenges,
        private readonly AchievementRepositoryInterface $achievements,
        private readonly AchievementCatalogueInterface $catalogue,
        private readonly AchievementGrantRepositoryInterface $grants,
        private readonly AchievementPreferencesRepositoryInterface $preferences,
        private readonly ProfileCardServiceInterface $cards,
        private readonly TimezoneServiceInterface $time,
    ) {}

    public function shown(?Authenticatable $viewer): array
    {
        return $this->views($viewer, withPeople: true);
    }

    public function forBlock(?string $challengeId, ?Authenticatable $viewer): ?ChallengeViewDTO
    {
        $views = $this->views($viewer, withPeople: false);

        if ($challengeId !== null && $challengeId !== '') {
            foreach ($views as $view) {
                if ($view->id === strtolower($challengeId)) {
                    return $view;
                }
            }

            return null;
        }

        $today = $this->today();
        $started = array_values(array_filter($views, static fn (ChallengeViewDTO $view): bool => $view->startsOn <= $today && $today <= $view->endsOn));

        if ($started !== []) {
            return $started[0];
        }

        $upcoming = array_values(array_filter($views, static fn (ChallengeViewDTO $view): bool => $view->state === ChallengeState::Upcoming));
        usort($upcoming, static fn (ChallengeViewDTO $a, ChallengeViewDTO $b): int => [$a->startsOn, $a->name] <=> [$b->startsOn, $b->name]);

        return $upcoming[0] ?? null;
    }

    public function blockOptions(): array
    {
        $options = [];

        foreach ($this->challenges->all() as $challenge) {
            if (! $challenge->closedAt instanceof DateTimeImmutable) {
                $options[$challenge->id] = $challenge->name;
            }
        }

        return $options;
    }

    /**
     * @return list<ChallengeViewDTO>
     */
    private function views(?Authenticatable $viewer, bool $withPeople): array
    {
        if (! $this->settings->challengesEnabled()) {
            return [];
        }

        $today = $this->today();
        $viewerId = $viewer instanceof Authenticatable ? strtolower((string) $viewer->getAuthIdentifier()) : null;
        $challenges = array_values(array_filter(
            $this->challenges->shown($today, self::AHEAD_DAYS, self::RECENT_DAYS),
            static fn (Challenge $challenge): bool => $challenge->rule instanceof ChallengeRule,
        ));
        $contributions = $viewerId === null ? [] : $this->challenges->contributionsOf($viewerId, array_map(static fn (Challenge $challenge): string => $challenge->id, $challenges));
        $views = [];

        foreach ($challenges as $challenge) {
            $rule = $challenge->rule;
            $reward = $this->reward($challenge->rewardAchievementId);

            if (! $rule instanceof ChallengeRule || ! $reward instanceof Achievement) {
                continue;
            }

            $state = $challenge->state($today);
            [$completers, $people] = $state === ChallengeState::Completed
                ? $this->completers($challenge, $reward, $viewer, $withPeople)
                : [0, []];

            $views[] = new ChallengeViewDTO(
                id: $challenge->id,
                name: $challenge->name,
                description: $challenge->description,
                state: $state,
                progress: $challenge->progress,
                target: $rule->target,
                unit: $this->unit($rule),
                startsOn: $challenge->startsOn,
                endsOn: $challenge->endsOn,
                membersOnly: $challenge->audience === ChallengeAudience::Members,
                reward: $this->catalogue->present($reward, $viewerId !== null && $this->grants->holds($viewerId, $reward->id)),
                contributors: $challenge->contributors,
                completers: $completers,
                people: $people,
                contribution: $viewerId === null ? null : ($contributions[$challenge->id] ?? 0),
            );
        }

        return $views;
    }

    /**
     * @return array{int, list<ChallengeCompleterDTO>}
     */
    private function completers(Challenge $challenge, Achievement $reward, ?Authenticatable $viewer, bool $withPeople): array
    {
        $holders = [];

        foreach (array_chunk($this->challenges->contributorIds($challenge->id), self::CHUNK) as $chunk) {
            $held = $this->grants->heldAchievementIds($chunk);

            foreach ($chunk as $userId) {
                if (in_array($reward->id, $held[$userId] ?? [], true)) {
                    $holders[] = $userId;
                }
            }
        }

        if (! $withPeople || $holders === []) {
            return [count($holders), []];
        }

        $preferences = $this->preferences->forUsers($holders);
        $people = [];
        $examined = 0;

        foreach ($holders as $userId) {
            if (count($people) >= self::MAX_LISTED || $examined >= self::MAX_EXAMINED) {
                break;
            }

            // Whoever hides their achievements is only counted
            if (($preferences[$userId] ?? null)?->hidden === true) {
                continue;
            }

            $examined++;
            $card = $this->cards->card($userId, $viewer);

            if ($card instanceof ProfileCardDTO && $card->publicName !== null && trim($card->publicName) !== '') {
                $people[] = new ChallengeCompleterDTO(trim($card->publicName), $card->url, $card->avatarUrl);
            }
        }

        usort($people, static fn (ChallengeCompleterDTO $a, ChallengeCompleterDTO $b): int => strnatcasecmp($a->publicName, $b->publicName));

        return [count($holders), $people];
    }

    private function reward(string $achievementId): ?Achievement
    {
        if (! array_key_exists($achievementId, $this->rewards)) {
            $this->rewards[$achievementId] = $this->achievements->find($achievementId);
        }

        return $this->rewards[$achievementId];
    }

    /**
     * What the total counts, agreed with the target («40 partidas», «1 persona»).
     */
    private function unit(ChallengeRule $rule): string
    {
        $key = $rule->metric === ChallengeMetric::Subjects ? 'kinds.'.$rule->kind : $rule->metric->value;
        $translationKey = 'achievements::messages.challenges.units.'.$key;
        $unit = trans_choice($translationKey, $rule->target);

        return $unit === $translationKey ? trans_choice('achievements::messages.challenges.units.fallback', $rule->target) : $unit;
    }

    private function today(): string
    {
        return $this->time->today()->format('Y-m-d');
    }
}
