<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\EvaluationResultDTO;

/**
 * Grants what the ledger says is now met. It only adds: an achievement held stays held whatever the
 * facts say later. It looks only at active achievements with a readable, non-manual rule whose kind
 * has a source offered now and is switched on in the settings (secret ones only while secret
 * achievements are on), see AchievementStatusRule.
 *
 * It fails closed: when the settings cannot be read (AchievementsSettingsInterface::readStrictly())
 * it evaluates and grants nothing, warns once a day and answers SettingsUnreadable. An achievement
 * whose rule cannot be read is skipped and warned once a day too.
 *
 * A grant is announced (not silent) only when the evaluation is not silent and the rule was met
 * just now (AnnouncementWindow, on RuleEvaluator::metAt()): what was met long ago is granted
 * silently whatever triggered the evaluation. Silent grants never send a notice (#6). Granting twice
 * grants nothing, also from two jobs at once.
 */
interface AchievementEvaluatorInterface
{
    /** Accounts read from the ledger at once */
    public const int USERS_PER_CHUNK = 200;

    /**
     * Evaluates these accounts only (ids that are not account UUIDs are ignored), optionally only some
     * achievements.
     *
     * @param  list<string>  $userIds
     * @param  list<string>|null  $achievementIds  null for every achievement
     *
     * @phpstan-impure
     */
    public function evaluate(array $userIds, bool $silent, ?array $achievementIds = null, bool $dryRun = false): EvaluationResultDTO;

    /**
     * Evaluates every account with facts of the kinds the achievements read, from the ledger (the
     * sources are not read again), in chunks. `$onlyAccountYears` keeps only the achievements of
     * account years, which change with the date alone (the daily run); `$onlyKinds` only the
     * achievements of those kinds (a module just enabled).
     *
     * @param  list<string>|null  $achievementIds  null for every achievement
     * @param  list<string>|null  $onlyKinds  null for every kind
     *
     * @phpstan-impure
     */
    public function evaluateEverybody(?array $achievementIds, bool $silent, bool $dryRun = false, bool $onlyAccountYears = false, ?array $onlyKinds = null): EvaluationResultDTO;
}
