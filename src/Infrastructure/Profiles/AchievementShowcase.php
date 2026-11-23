<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\ActivityIdentifierRule;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Application\DTOs\SeasonStandingDTO;
use Modules\Achievements\Application\DTOs\ShowcaseItemDTO;
use Modules\Achievements\Application\Enums\AchievementStatus;
use Modules\Achievements\Application\Services\AchievementCatalogueInterface;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\AchievementStatusRule;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Application\Services\CommunityMetricsInterface;
use Modules\Achievements\Application\Services\SeasonClock;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\RuleEvaluator;
use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;
use Modules\Achievements\Domain\ValueObjects\Season;

/**
 * Bound "scoped": the catalogue and each account's choices are read once per request or job, and
 * the section, the count and the badges of one profile share them.
 */
final class AchievementShowcase implements AchievementShowcaseInterface
{
    /** @var array<string, Achievement>|null */
    private ?array $catalogue = null;

    /** @var array<string, AchievementPreferences> */
    private array $preferences = [];

    /** @var array<string, array<string, AchievementGrant>> */
    private array $held = [];

    /** @var array<string, array<string, list<string>>> */
    private array $seasons = [];

    public function __construct(
        private readonly AchievementRepositoryInterface $achievements,
        private readonly AchievementGrantRepositoryInterface $grants,
        private readonly AchievementPreferencesRepositoryInterface $choices,
        private readonly AchievementCatalogueInterface $presenter,
        private readonly AchievementRarityInterface $rarity,
        private readonly AchievementsSettingsInterface $settings,
        private readonly AchievementFactRepositoryInterface $facts,
        private readonly ActivityMetricsInterface $metrics,
        private readonly AchievementStatusRule $status,
        private readonly RuleEvaluator $rules,
        private readonly TimezoneServiceInterface $time,
        private readonly SeasonClock $clock,
        private readonly CommunityMetricsInterface $community,
    ) {}

    public function preferences(string $userId): AchievementPreferences
    {
        $userId = strtolower($userId);

        return $this->preferences[$userId] ??= $this->choices->find($userId);
    }

    public function isVisibleTo(string $ownerId, ?string $viewerId): bool
    {
        return $this->isOwner($ownerId, $viewerId) || ! $this->preferences($ownerId)->hidden;
    }

    public function earned(string $ownerId, ?string $viewerId): array
    {
        $ownerId = strtolower($ownerId);

        if (! $this->isVisibleTo($ownerId, $viewerId)) {
            return [];
        }

        $isOwner = $this->isOwner($ownerId, $viewerId);
        $held = $this->heldBy($ownerId);
        $catalogue = $this->catalogue();
        $featured = $this->featuredIds($held, $this->preferences($ownerId), $this->rarity());
        $viewerHolds = $isOwner || $viewerId === null ? [] : $this->heldBy(strtolower($viewerId));
        $rarity = $this->rarity();
        $items = [];

        foreach (array_reverse($held, true) as $achievementId => $grant) {
            $achievement = $catalogue[$achievementId] ?? null;

            if (! $achievement instanceof Achievement) {
                continue;
            }

            // A secret shows to its holders, and to everybody once its owner features it
            $revealed = $isOwner || ! $achievement->secret || isset($viewerHolds[$achievementId]) || in_array($achievementId, $featured, true);

            $items[] = new ShowcaseItemDTO(
                achievement: $this->presenter->present($achievement, $revealed),
                // Only the owner gets the instant: grants of one evaluation share their second, so
                // anybody else comparing two profiles would learn who played or went somewhere
                // together. Everybody else gets the display month alone.
                grantedAt: $isOwner ? $grant->grantedAt : null,
                // Held by the owner, so a share of 0 only says they are not active: not shown
                rarity: $revealed ? self::knownShare($rarity?->shareOf($achievementId)) : null,
                featured: $revealed && in_array($achievementId, $featured, true),
                grantedMonth: $this->time->format($grant->grantedAt, 'Y-m'),
                // The instant to sort the owner's list; never kept for anybody else
                grantedOrder: $isOwner ? $grant->grantedAt : null,
                // Which seasons, never what was played in them; none on a masked one
                seasons: $revealed ? ($this->seasonsBy($ownerId)[$achievementId] ?? []) : [],
                points: $achievement->points,
            );
        }

        // The owner: newest first. Anybody else: by month, then by the name shown, so the order
        // never tells which came first within a month
        usort($items, $isOwner
            ? static fn (ShowcaseItemDTO $a, ShowcaseItemDTO $b): int => [$b->grantedOrder, $a->achievement->id] <=> [$a->grantedOrder, $b->achievement->id]
            : static fn (ShowcaseItemDTO $a, ShowcaseItemDTO $b): int => [$b->grantedMonth, self::nameKey($a->achievement->name), $a->achievement->id] <=> [$a->grantedMonth, self::nameKey($b->achievement->name), $b->achievement->id]);

        return $items;
    }

    public function locked(string $ownerId): array
    {
        if (! $this->settings->progressEnabled()) {
            return [];
        }

        $ownerId = strtolower($ownerId);
        $settings = $this->settings->settings();
        $kindsWithSource = $this->metrics->kindsWithSource();
        $candidates = [];
        $kinds = [];

        foreach ($this->catalogue() as $achievement) {
            // One that repeats is still to get while this season's is not held
            if ($this->holdsNow($ownerId, $achievement) || ! $achievement->active) {
                continue;
            }

            if (! $this->canBeGot($achievement, $settings, $kindsWithSource)) {
                continue;
            }

            $status = $this->status->status($achievement, $settings, $kindsWithSource);
            // A secret one the owner got in an earlier season is theirs: shown with its name, never
            // masked next to that named copy
            $revealed = isset($this->heldBy($ownerId)[strtolower($achievement->id)]);

            $candidates[] = [$achievement, $status, $revealed];
            $kind = $achievement->rule?->evaluatedKind();

            if ($status === AchievementStatus::Evaluated && (! $achievement->secret || $revealed) && $kind !== null) {
                $kinds[$kind] = true;
            }
        }

        $facts = $kinds === [] ? [] : $this->facts->forUser($ownerId, array_keys($kinds));
        $now = DateTimeImmutable::createFromInterface(Date::now());
        $zone = $this->time->displayTimezone();
        $items = [];

        foreach ($candidates as [$achievement, $status, $revealed]) {
            $view = $this->presenter->present($achievement, $revealed);
            $rule = $achievement->rule;

            if ($view->masked || $status === AchievementStatus::Manual || ! $rule instanceof AchievementRule) {
                $items[] = new ShowcaseItemDTO(
                    achievement: $view,
                    manual: ! $view->masked && $status === AchievementStatus::Manual,
                    // A manual one per season is still this season's to get
                    season: ! $view->masked && $rule instanceof AchievementRule && $rule->isSeasonal() ? $this->clock->current()->key() : null,
                );

                continue;
            }

            // Community rules count other people: the number only, from the cache; unknown shows nothing
            $value = $rule->isCommunity()
                ? $this->community->progress($rule, $ownerId)
                : $this->rules->value($rule, $facts, $now, $zone);

            $items[] = new ShowcaseItemDTO(
                achievement: $view,
                progress: $value === null ? null : min($rule->target(), $value),
                threshold: $value === null ? null : $rule->target(),
                season: $rule->isSeasonal() ? $this->clock->current()->key() : null,
                inMonths: $rule->window->isStreak(),
            );
        }

        usort($items, static fn (ShowcaseItemDTO $a, ShowcaseItemDTO $b): int => [
            $a->achievement->masked,
            -self::completion($a),
            self::nameKey($a->achievement->name),
            $a->achievement->id,
        ] <=> [
            $b->achievement->masked,
            -self::completion($b),
            self::nameKey($b->achievement->name),
            $b->achievement->id,
        ]);

        return $items;
    }

    public function catalogueFor(?string $viewerId): array
    {
        $viewerId = $viewerId === null ? null : ActivityIdentifierRule::userId($viewerId);
        $held = $viewerId === null ? [] : $this->heldBy($viewerId);
        $settings = $this->settings->settings();
        $kindsWithSource = $this->metrics->kindsWithSource();
        $views = [];

        foreach ($this->catalogue() as $id => $achievement) {
            if (isset($held[$id])) {
                $views[] = $this->presenter->present($achievement, true);

                continue;
            }

            if ($achievement->active && $this->canBeGot($achievement, $settings, $kindsWithSource)) {
                $views[] = $this->presenter->present($achievement, false);
            }
        }

        // By the name shown, then id: the catalogue's order (by the real name) would tell where a
        // masked one falls
        usort($views, static fn (AchievementViewDTO $a, AchievementViewDTO $b): int => [self::nameKey($a->name), $a->id] <=> [self::nameKey($b->name), $b->id]);

        return $views;
    }

    public function seasonStandings(?string $viewerId): array
    {
        $viewerId = $viewerId === null ? null : ActivityIdentifierRule::userId($viewerId);
        $settings = $this->settings->settings();

        if (! $settings->seasonsAndStreaks) {
            return [];
        }

        $current = $this->clock->current()->key();
        $seasonal = [];
        $kinds = [];

        foreach ($this->catalogue() as $id => $achievement) {
            $rule = $achievement->rule;

            if ($rule instanceof AchievementRule && $rule->isSeasonal()) {
                $seasonal[$id] = [$achievement, $rule];
                $kind = $rule->evaluatedKind();

                if ($kind !== null && $viewerId !== null && $settings->progress && ! $achievement->secret) {
                    $kinds[$kind] = true;
                }
            }
        }

        $facts = $kinds === [] || $viewerId === null ? [] : $this->facts->forUser($viewerId, array_keys($kinds));
        $now = DateTimeImmutable::createFromInterface(Date::now());
        $zone = $this->time->displayTimezone();
        $standings = [];

        foreach ($seasonal as $id => [$achievement, $rule]) {
            $seasons = $viewerId === null ? [] : ($this->seasonsBy($viewerId)[$id] ?? []);
            $heldNow = in_array($current, $seasons, true);
            $measured = ! $heldNow && ! $rule->isManual() && $viewerId !== null && $settings->progress && ! $achievement->secret;

            $standings[$id] = new SeasonStandingDTO(
                season: $current,
                heldNow: $heldNow,
                pastSeasons: array_values(array_filter($seasons, static fn (string $season): bool => $season !== $current)),
                progress: $measured ? min($rule->target(), $this->rules->value($rule, $facts, $now, $zone)) : null,
                target: $measured ? $rule->target() : null,
                inMonths: $rule->window->isStreak(),
            );
        }

        return $standings;
    }

    public function heldCount(string $ownerId): int
    {
        return count(array_intersect_key($this->heldBy(strtolower($ownerId)), $this->catalogue()));
    }

    public function featured(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map(
            ActivityIdentifierRule::userId(...),
            $userIds,
        )));

        if ($userIds === []) {
            return [];
        }

        foreach ($this->choices->forUsers($userIds) as $userId => $preferences) {
            $this->preferences[$userId] = $preferences;
        }

        $shown = array_values(array_filter($userIds, fn (string $id): bool => ! $this->preferences[$id]->hidden));
        $grants = $shown === [] ? [] : $this->grants->forUsers($shown);
        $catalogue = $this->catalogue();
        $rarity = $this->rarity();
        $featured = [];

        foreach ($shown as $userId) {
            $this->held[$userId] = $this->byAchievement($grants[$userId] ?? []);
            $this->seasons[$userId] = $this->seasonKeys($grants[$userId] ?? []);
            $views = [];

            foreach ($this->featuredIds($this->held[$userId], $this->preferences[$userId], $rarity) as $achievementId) {
                $views[] = $this->presenter->present($catalogue[$achievementId], true);
            }

            if ($views !== []) {
                $featured[$userId] = $views;
            }
        }

        return $featured;
    }

    /**
     * The achievements featured for one account: the held ones it chose, in its order; when it
     * chose none (or none it chose is held any longer), the rarest held ones that are not secret,
     * among equals (and alone while rarity is off) the newest month first, then by name and id: never
     * by the instant, since the three newest in order would tell the order of grants within a month.
     *
     * @param  array<string, AchievementGrant>  $held
     * @return list<string>
     */
    private function featuredIds(array $held, AchievementPreferences $preferences, ?AchievementRarityDTO $rarity): array
    {
        $catalogue = $this->catalogue();
        $chosen = array_values(array_filter(
            $preferences->featured,
            static fn (string $id): bool => isset($held[$id], $catalogue[$id]),
        ));

        if ($chosen !== []) {
            return $chosen;
        }

        $candidates = [];

        foreach ($held as $id => $grant) {
            $achievement = $catalogue[$id] ?? null;

            if ($achievement instanceof Achievement && ! $achievement->secret) {
                // An unknown share (rarity off, or 0: nobody active holds it) goes after every known
                // one; among equals the newest month first, then the name: never the instant, which
                // would tell the order of grants within a month
                $candidates[] = [
                    self::knownShare($rarity?->shareOf($id)) ?? 101.0,
                    -(int) $this->time->format($grant->grantedAt, 'Ym'),
                    self::nameKey($achievement->name),
                    $id,
                ];
            }
        }

        sort($candidates);

        return array_map(static fn (array $candidate): string => $candidate[3], array_slice($candidates, 0, AchievementPreferences::MAX_FEATURED));
    }

    /**
     * Whether an achievement can be got now, automatically or by hand: not without a source, of a
     * kind switched off, secret while secrets are off, inactive or with a broken rule.
     *
     * @param  array<string, true>  $kindsWithSource
     */
    private function canBeGot(Achievement $achievement, AchievementsSettings $settings, array $kindsWithSource): bool
    {
        $status = $this->status->status($achievement, $settings, $kindsWithSource);

        return $status === AchievementStatus::Evaluated || $status === AchievementStatus::Manual;
    }

    /**
     * Each achievement an account holds with the grant of its newest season (one that repeats may
     * get a past season after a newer one: a recalculation), in the order of those grants.
     *
     * @return array<string, AchievementGrant>
     */
    private function heldBy(string $userId): array
    {
        if (! isset($this->held[$userId])) {
            $grants = $this->grants->forUser($userId);
            $this->held[$userId] = $this->byAchievement($grants);
            $this->seasons[$userId] = $this->seasonKeys($grants);
        }

        return $this->held[$userId];
    }

    /**
     * The seasons in which an account holds each achievement that repeats, newest first.
     *
     * @return array<string, list<string>>
     */
    private function seasonsBy(string $userId): array
    {
        $this->heldBy($userId);

        return $this->seasons[$userId] ?? [];
    }

    /**
     * Whether the account holds the achievement as far as getting it goes: this season's grant for
     * one that repeats, any grant otherwise.
     */
    private function holdsNow(string $userId, Achievement $achievement): bool
    {
        $id = strtolower($achievement->id);

        if (! isset($this->heldBy($userId)[$id])) {
            return false;
        }

        $season = $this->clock->forGrantOf($achievement);

        return ! $season instanceof Season || in_array($season->key(), $this->seasonsBy($userId)[$id] ?? [], true);
    }

    /**
     * @param  list<AchievementGrant>  $grants  oldest first
     * @return array<string, AchievementGrant>
     */
    private function byAchievement(array $grants): array
    {
        $held = [];

        foreach ($grants as $grant) {
            $id = strtolower($grant->achievementId);

            // The newest season wins (season keys are years of four digits, so they sort as text);
            // among grants without a season, the latest
            if (isset($held[$id]) && strcmp((string) $grant->season, (string) $held[$id]->season) < 0) {
                continue;
            }

            // Moved to the end, so the order follows each one's newest grant
            unset($held[$id]);
            $held[$id] = $grant;
        }

        return $held;
    }

    /**
     * @param  list<AchievementGrant>  $grants
     * @return array<string, list<string>>
     */
    private function seasonKeys(array $grants): array
    {
        $seasons = [];

        foreach ($grants as $grant) {
            if ($grant->season !== null && $grant->season !== '') {
                $seasons[strtolower($grant->achievementId)][] = $grant->season;
            }
        }

        foreach ($seasons as $id => $keys) {
            $keys = array_values(array_unique($keys));
            rsort($keys, SORT_STRING);
            $seasons[$id] = $keys;
        }

        return $seasons;
    }

    /**
     * @return array<string, Achievement>
     */
    private function catalogue(): array
    {
        if ($this->catalogue === null) {
            $this->catalogue = [];

            foreach ($this->achievements->all() as $achievement) {
                $this->catalogue[strtolower($achievement->id)] = $achievement;
            }
        }

        return $this->catalogue;
    }

    private function rarity(): ?AchievementRarityDTO
    {
        return $this->settings->rarityEnabled() ? $this->rarity->current() : null;
    }

    private function isOwner(string $ownerId, ?string $viewerId): bool
    {
        return $viewerId !== null && strtolower($viewerId) === strtolower($ownerId);
    }

    /**
     * A share worth showing for something somebody holds: 0 only means no active person holds it,
     * which is never true of an achievement someone is looking at as theirs.
     */
    public static function knownShare(?float $share): ?float
    {
        return $share === null || $share <= 0.0 ? null : $share;
    }

    /**
     * A name as it sorts for people: without case or accents, so «Árbol» sits among the A and not
     * after the Z (ñ sorts with n).
     */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(Str::ascii($name));
    }

    /**
     * How close a locked achievement is, from 0 to 1; -1 for one without progress (by hand).
     */
    private static function completion(ShowcaseItemDTO $item): float
    {
        if ($item->progress === null || $item->threshold === null || $item->threshold === 0) {
            return -1.0;
        }

        return $item->progress / $item->threshold;
    }
}
