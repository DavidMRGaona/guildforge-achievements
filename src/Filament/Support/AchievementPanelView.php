<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use Modules\Achievements\Application\Enums\AchievementStatus;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\AchievementStatusRule;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MetricType;
use Modules\Achievements\Domain\Rules\RuleWindow;
use Modules\Achievements\Domain\Rules\WindowType;
use Modules\Achievements\Domain\Rules\YearsBasis;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionTypeModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementRepository;

/**
 * What the catalogue's table shows of an achievement: its status (the evaluator's own decision) and
 * a short reading of its rule. The settings and the sources come from scoped services, so they are
 * read once per request.
 */
final class AchievementPanelView
{
    public static function status(AchievementModel $record): AchievementStatus
    {
        $settings = app(AchievementsSettingsInterface::class);

        return app(AchievementStatusRule::class)->status(
            EloquentAchievementRepository::toEntity($record),
            // The panel shows what the evaluator would do; unreadable settings fall back to the page's values
            $settings->readStrictly() ?? $settings->settings(),
            app(ActivityMetricsInterface::class)->kindsWithSource(),
        );
    }

    public static function ruleSummary(AchievementModel $record): string
    {
        $rule = AchievementRule::tryFrom($record->getRawOriginal('rule'));

        if (! $rule instanceof AchievementRule) {
            return self::text('summary.invalid');
        }

        $summary = match ($rule->metric) {
            MetricType::Manual => self::text('summary.manual'),
            MetricType::Years => self::text($rule->basis === YearsBasis::Membership ? 'summary.membership_years' : 'summary.account_years', ['threshold' => (string) $rule->threshold]),
            MetricType::Count, MetricType::Distinct => self::activitySummary($rule),
            MetricType::People => self::peopleSummary($rule),
            MetricType::Recognitions => self::recognitionsSummary($rule),
            MetricType::Mentor => self::text('summary.mentor', ['threshold' => (string) $rule->threshold]),
        };

        $window = self::windowSummary($rule->window);

        return $window === null ? $summary : $summary.' · '.$window;
    }

    /**
     * «Reconocimientos recibidos: 10», or of one type by its name now (a type deleted since, or
     * never known, counts any).
     */
    private static function recognitionsSummary(AchievementRule $rule): string
    {
        $type = $rule->recognitionType === null ? null : RecognitionTypeModel::query()->whereKey($rule->recognitionType)->value('name');

        return is_string($type)
            ? self::text('summary.recognitions_of_type', ['type' => $type, 'threshold' => (string) $rule->threshold])
            : self::text('summary.recognitions', ['threshold' => (string) $rule->threshold]);
    }

    /**
     * «por temporada», «racha de 3 meses», «racha de 3 meses en la temporada»; null for every fact.
     */
    public static function windowSummary(RuleWindow $window): ?string
    {
        return match ($window->type) {
            WindowType::AllTime => null,
            WindowType::Season => self::text('summary.season'),
            WindowType::Streak => trans_choice(
                'achievements::messages.catalogue.rule.summary.'.($window->perSeason ? 'season_streak' : 'streak'),
                (int) $window->months,
                ['months' => (string) $window->months],
            ),
        };
    }

    private static function peopleSummary(AchievementRule $rule): string
    {
        $roles = AchievementRuleForm::roleOptions();
        $parts = [AchievementRuleForm::kindLabel((string) $rule->kind)];

        if ($rule->role !== null) {
            $parts[] = $roles[$rule->role] ?? $rule->role;
        }

        if ($rule->withRole !== null) {
            // Mid-phrase: «con jugador», never «con Jugador»
            $parts[] = self::text('summary.with_role', ['role' => mb_strtolower($roles[$rule->withRole] ?? $rule->withRole)]);
        }

        return self::text('summary.people', ['threshold' => (string) $rule->threshold]).' · '.implode(' · ', $parts);
    }

    private static function activitySummary(AchievementRule $rule): string
    {
        $kind = (string) $rule->kind;
        $parts = [AchievementRuleForm::kindLabel($kind)];

        if ($rule->role !== null) {
            $parts[] = AchievementRuleForm::roleOptions()[$rule->role] ?? $rule->role;
        }

        if ($rule->attributeValue !== null) {
            $parts[] = $rule->attributeLabel ?? $rule->attributeValue;
        }

        if ($rule->placementMax !== null) {
            $parts[] = self::text('summary.placement', ['placement' => (string) $rule->placementMax]);
        }

        $head = $rule->metric === MetricType::Distinct
            ? self::text('summary.distinct', [
                'threshold' => (string) $rule->threshold,
                'attribute' => app(ActivityMetricsInterface::class)->attributes($kind)[(string) $rule->distinctKey] ?? (string) $rule->distinctKey,
            ])
            : (string) $rule->threshold;

        return $head.' · '.implode(' · ', $parts);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        return (string) __('achievements::messages.catalogue.rule.'.$key, $replace);
    }
}
