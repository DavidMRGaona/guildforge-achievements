<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Challenges;

use App\Application\Authorization\Services\AssociationMembershipServiceInterface;
use App\Application\Profiles\DTOs\ProfileCardDTO;
use App\Application\Profiles\Services\ProfileCardServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Modules\Achievements\Application\DTOs\ChallengeCompleterDTO;
use Modules\Achievements\Application\DTOs\ChallengeViewDTO;
use Modules\Achievements\Application\Services\AchievementCatalogueInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Application\Services\ChallengeBoardInterface;
use Modules\Achievements\Domain\Challenges\ChallengeAudience;
use Modules\Achievements\Domain\Challenges\ChallengeMetric;
use Modules\Achievements\Domain\Challenges\ChallengeRewardRule;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use Modules\Achievements\Domain\Challenges\ChallengeState;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Entities\Challenge;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;
use Throwable;

/**
 * Who completed a challenge is whoever added to it and holds its reward now (a reward taken away by
 * hand no longer counts). The list asks the core's profile card for each of them, so it only names
 * people whose profile the viewer may see, by their public name, and leaves out whoever hides their
 * achievements; in a challenge of members, also whoever does not show the membership. It stops
 * after a few. How many got the reward is never told next to how many added: together they would
 * tell a reward was taken away.
 */
final class ChallengeBoard implements ChallengeBoardInterface
{
    /** Cards asked for, at most, to fill the list of one challenge: the list and a few spare */
    private const int MAX_EXAMINED = self::MAX_LISTED + 4;

    private const string CACHE_PREFIX = 'achievements:challenge-people:v1:';

    /** How long one viewer's list is kept: a profile hidden meanwhile drops out within this */
    private const int CACHE_SECONDS = 300;

    private ?bool $viewerIsMember = null;

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
        private readonly ActivityMetricsInterface $metrics,
        private readonly AssociationMembershipServiceInterface $membership,
        private readonly Repository $cache,
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
        $settings = $this->settings->settings();

        if (! $settings->challenges) {
            return [];
        }

        $today = $this->today();
        $kinds = $this->metrics->kindsWithSource();
        $viewerId = $viewer instanceof Authenticatable ? strtolower((string) $viewer->getAuthIdentifier()) : null;
        // Like the catalogue: what has no source or is switched off is not offered (its total would mislead)
        $challenges = array_values(array_filter(
            $this->challenges->shown($today, self::AHEAD_DAYS, self::RECENT_DAYS),
            static fn (Challenge $challenge): bool => $challenge->rule instanceof ChallengeRule
                && isset($kinds[$challenge->rule->kind])
                && $settings->metricKindEnabled($challenge->rule->kind),
        ));
        $contributions = $viewerId === null ? [] : $this->challenges->contributionsOf($viewerId, array_map(static fn (Challenge $challenge): string => $challenge->id, $challenges));
        $views = [];

        foreach ($challenges as $challenge) {
            $rule = $challenge->rule;
            $reward = $this->reward($challenge->rewardAchievementId);

            // A reward that stopped being one (switched off, secret, granted by a rule): not shown
            if (! $rule instanceof ChallengeRule || ! $reward instanceof Achievement || ! ChallengeRewardRule::isUsable($reward)) {
                continue;
            }

            $state = $challenge->state($today);
            $membersOnly = $challenge->audience === ChallengeAudience::Members;

            $views[] = new ChallengeViewDTO(
                id: $challenge->id,
                name: $challenge->name,
                description: $challenge->description,
                state: $state,
                counted: $challenge->progressAt instanceof DateTimeImmutable,
                progress: $challenge->progress,
                target: $rule->target,
                unit: $this->unit($rule),
                startsOn: $challenge->startsOn,
                endsOn: $challenge->endsOn,
                // The year unless both days are of this one
                showsYear: substr($challenge->startsOn, 0, 4) !== substr($today, 0, 4) || substr($challenge->endsOn, 0, 4) !== substr($today, 0, 4),
                ended: $challenge->hasEnded($today),
                membersOnly: $membersOnly,
                reward: $this->catalogue->present($reward, $viewerId !== null && $this->grants->holds($viewerId, $reward->id)),
                contributors: $challenge->contributors,
                people: $withPeople && $state === ChallengeState::Completed ? $this->completers($challenge, $reward, $viewer, $membersOnly) : [],
                // In a challenge of members, only a member is told they have not added yet
                contribution: $viewer instanceof Authenticatable && (! $membersOnly || $this->viewerIsMember($viewer))
                    ? ($contributions[$challenge->id] ?? 0)
                    : null,
            );
        }

        return $views;
    }

    /**
     * The people who got the reward, as the viewer may see them: whoever added and holds it now,
     * minus whoever hides their achievements, through the core's card (visible profiles, public
     * name). In a challenge of members, only those whose card already shows the membership (and the
     * viewer's own): the list must never tell who is a member. At most MAX_LISTED, from a few more
     * cards asked; the answer is kept for a short while per viewer.
     *
     * @return list<ChallengeCompleterDTO>
     */
    private function completers(Challenge $challenge, Achievement $reward, ?Authenticatable $viewer, bool $membersOnly): array
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

        if ($holders === []) {
            return [];
        }

        $viewerKey = $viewer instanceof Authenticatable ? strtolower((string) $viewer->getAuthIdentifier()) : 'guest';
        $key = self::CACHE_PREFIX.sha1((string) json_encode([$challenge->id, $viewerKey, $holders, $membersOnly]));

        try {
            $cached = $this->cache->get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            return array_values(array_filter(array_map($this->fromCache(...), $cached)));
        }

        $people = $this->peopleAmong($holders, $viewer, $membersOnly);

        try {
            $this->cache->put($key, array_map(static fn (ChallengeCompleterDTO $person): array => [$person->publicName, $person->url, $person->avatarUrl], $people), self::CACHE_SECONDS);
        } catch (Throwable) {
            // Without the cache, the next page asks the cards again
        }

        return $people;
    }

    /**
     * @param  list<string>  $holders
     * @return list<ChallengeCompleterDTO>
     */
    private function peopleAmong(array $holders, ?Authenticatable $viewer, bool $membersOnly): array
    {
        $preferences = $this->preferences->forUsers($holders);
        $people = [];
        $examined = 0;

        foreach ($holders as $userId) {
            if (count($people) >= self::MAX_LISTED || $examined >= self::MAX_EXAMINED) {
                break;
            }

            // Whoever hides their achievements is never named
            if (($preferences[$userId] ?? null)?->hidden === true) {
                continue;
            }

            $examined++;
            $card = $this->cards->card($userId, $viewer);

            if (! $card instanceof ProfileCardDTO || $card->publicName === null || trim($card->publicName) === '') {
                continue;
            }

            if ($membersOnly && ! $card->showsMembership && ! $card->isOwner) {
                continue;
            }

            $people[] = new ChallengeCompleterDTO(trim($card->publicName), $card->url, $card->avatarUrl);
        }

        usort($people, static fn (ChallengeCompleterDTO $a, ChallengeCompleterDTO $b): int => strnatcasecmp($a->publicName, $b->publicName));

        return $people;
    }

    private function fromCache(mixed $row): ?ChallengeCompleterDTO
    {
        if (! is_array($row) || ! is_string($row[0] ?? null) || ! is_string($row[1] ?? null) || (($row[2] ?? null) !== null && ! is_string($row[2]))) {
            return null;
        }

        return new ChallengeCompleterDTO($row[0], $row[1], $row[2] ?? null);
    }

    /**
     * Fails closed: a check that throws says no.
     */
    private function viewerIsMember(Authenticatable $viewer): bool
    {
        if ($this->viewerIsMember === null) {
            try {
                $this->viewerIsMember = $this->membership->isAssociationMember($viewer);
            } catch (Throwable) {
                $this->viewerIsMember = false;
            }
        }

        return $this->viewerIsMember;
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
