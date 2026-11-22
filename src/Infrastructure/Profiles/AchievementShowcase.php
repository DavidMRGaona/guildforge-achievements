<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\ActivityIdentifierRule;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Application\DTOs\ShowcaseItemDTO;
use Modules\Achievements\Application\Enums\AchievementStatus;
use Modules\Achievements\Application\Services\AchievementCatalogueInterface;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\AchievementStatusRule;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\RuleEvaluator;
use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;

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
                grantedAt: $grant->grantedAt,
                // Held by the owner, so a share of 0 only says they are not active: not shown
                rarity: $revealed ? self::knownShare($rarity?->shareOf($achievementId)) : null,
                featured: $revealed && in_array($achievementId, $featured, true),
            );
        }

        usort($items, static fn (ShowcaseItemDTO $a, ShowcaseItemDTO $b): int => [$b->grantedAt, $a->achievement->id] <=> [$a->grantedAt, $b->achievement->id]);

        return $items;
    }

    public function locked(string $ownerId): array
    {
        if (! $this->settings->progressEnabled()) {
            return [];
        }

        $ownerId = strtolower($ownerId);
        $held = $this->heldBy($ownerId);
        $settings = $this->settings->settings();
        $kindsWithSource = $this->metrics->kindsWithSource();
        $candidates = [];
        $kinds = [];

        foreach ($this->catalogue() as $id => $achievement) {
            if (isset($held[$id]) || ! $achievement->active) {
                continue;
            }

            if (! $this->canBeGot($achievement, $settings, $kindsWithSource)) {
                continue;
            }

            $status = $this->status->status($achievement, $settings, $kindsWithSource);

            $candidates[] = [$achievement, $status];
            $kind = $achievement->rule?->evaluatedKind();

            if ($status === AchievementStatus::Evaluated && ! $achievement->secret && $kind !== null) {
                $kinds[$kind] = true;
            }
        }

        $facts = $kinds === [] ? [] : $this->facts->forUser($ownerId, array_keys($kinds));
        $now = DateTimeImmutable::createFromInterface(Date::now());
        $zone = $this->time->displayTimezone();
        $items = [];

        foreach ($candidates as [$achievement, $status]) {
            $view = $this->presenter->present($achievement, false);
            $rule = $achievement->rule;

            if ($view->masked || $status === AchievementStatus::Manual || ! $rule instanceof AchievementRule) {
                $items[] = new ShowcaseItemDTO(achievement: $view, manual: ! $view->masked && $status === AchievementStatus::Manual);

                continue;
            }

            $items[] = new ShowcaseItemDTO(
                achievement: $view,
                progress: min($rule->threshold, $this->rules->value($rule, $facts, $now, $zone)),
                threshold: $rule->threshold,
            );
        }

        usort($items, static fn (ShowcaseItemDTO $a, ShowcaseItemDTO $b): int => [
            $a->achievement->masked,
            -self::completion($a),
            mb_strtolower($a->achievement->name),
            $a->achievement->id,
        ] <=> [
            $b->achievement->masked,
            -self::completion($b),
            mb_strtolower($b->achievement->name),
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
        usort($views, static fn (AchievementViewDTO $a, AchievementViewDTO $b): int => [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id]);

        return $views;
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
     * the newest first among equals (and only by date while rarity is off).
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
                // An unknown share (rarity off, or 0: nobody active holds it) goes after every known one
                $candidates[] = [self::knownShare($rarity?->shareOf($id)) ?? 101.0, -$grant->grantedAt->getTimestamp(), $id];
            }
        }

        sort($candidates);

        return array_map(static fn (array $candidate): string => $candidate[2], array_slice($candidates, 0, AchievementPreferences::MAX_FEATURED));
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
     * Each achievement an account holds with its first grant, oldest first.
     *
     * @return array<string, AchievementGrant>
     */
    private function heldBy(string $userId): array
    {
        return $this->held[$userId] ??= $this->byAchievement($this->grants->forUser($userId));
    }

    /**
     * @param  list<AchievementGrant>  $grants  oldest first
     * @return array<string, AchievementGrant>
     */
    private function byAchievement(array $grants): array
    {
        $held = [];

        foreach ($grants as $grant) {
            $held[strtolower($grant->achievementId)] ??= $grant;
        }

        return $held;
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
