<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Enums\AchievementStatus;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MetricType;

/**
 * The one decision of whether an achievement is evaluated, shared by the evaluator and the panel.
 * What the rule cannot tell comes first (unreadable, manual, without a source), so the panel shows
 * «Sin fuente» also on an achievement switched off for that reason. A seasonal or streak window
 * while seasons and streaks are off stops even a manual rule: the mechanic is off, so nobody gets
 * a season of it, by hand or not.
 */
final class AchievementStatusRule
{
    /**
     * @param  array<string, true>  $kindsWithSource
     */
    public function status(Achievement $achievement, AchievementsSettings $settings, array $kindsWithSource): AchievementStatus
    {
        $rule = $achievement->rule;

        if (! $rule instanceof AchievementRule) {
            return AchievementStatus::InvalidRule;
        }

        // Recognitions received come from no source of activity: only the mechanic's own switch
        if ($rule->metric === MetricType::Recognitions) {
            return match (true) {
                ! $achievement->active => AchievementStatus::Inactive,
                $achievement->secret && ! $settings->secrets => AchievementStatus::SecretsOff,
                ! $settings->recognitions => AchievementStatus::RecognitionsOff,
                default => AchievementStatus::Evaluated,
            };
        }

        $kind = $rule->evaluatedKind();

        $seasonsOff = ! $rule->window->isAllTime() && ! $settings->seasonsAndStreaks;

        if ($rule->isManual() || $kind === null) {
            return $seasonsOff ? AchievementStatus::SeasonsOff : AchievementStatus::Manual;
        }

        if (! isset($kindsWithSource[$kind])) {
            return AchievementStatus::NoSource;
        }

        if (! $achievement->active) {
            return AchievementStatus::Inactive;
        }

        if (! $settings->metricKindEnabled($kind)) {
            return AchievementStatus::KindOff;
        }

        if ($achievement->secret && ! $settings->secrets) {
            return AchievementStatus::SecretsOff;
        }

        if ($seasonsOff) {
            return AchievementStatus::SeasonsOff;
        }

        if ($rule->isCommunity() && ! $settings->community) {
            return AchievementStatus::CommunityOff;
        }

        return AchievementStatus::Evaluated;
    }
}
