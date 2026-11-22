<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use App\Application\Activity\DTOs\ActivitySubjectDTO;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\BulkGrantCountDTO;
use Modules\Achievements\Application\DTOs\BulkGrantRequestDTO;

/**
 * «Conceder en lote»: an achievement to everybody who held a role in one subject of an activity
 * source, read through the core's ActivityReaderInterface. It fails closed at every step: the
 * settings are read strictly and the switch must be on, the achievement must exist and be on, the
 * source must describe the subject to whoever grants (what describe() does not show them is never
 * offered nor granted from), and its facts must be read (`Read`), never guessed.
 *
 * It answers in numbers only (BulkGrantCountDTO): whoever grants never gets a list of the people,
 * since playing or attending is not public. Permissions are the caller's (AchievementPolicy::grant()).
 */
interface BulkGrantInterface
{
    /** How many subjects are offered at most */
    public const int MAX_SUBJECTS = 50;

    /** How many of the most recent subjects of the ledger are described to search in */
    public const int RECENT_SUBJECTS = 200;

    /**
     * The most recent subjects of a source that the viewer may see, whose title contains `search`
     * (any case); empty when the source is missing or fails.
     *
     * @return list<ActivitySubjectDTO>
     */
    public function subjects(string $sourceType, ?string $search, Authenticatable $viewer): array;

    /** The subject as the viewer sees it, or null */
    public function subject(string $sourceType, string $subjectId, Authenticatable $viewer): ?ActivitySubjectDTO;

    public function preview(BulkGrantRequestDTO $request, Authenticatable $viewer): BulkGrantCountDTO;

    /**
     * Grants it, in one transaction, with GrantSource::Bulk, `actor` as who did it and the same
     * reason for everybody; `silent` grants send no notice.
     */
    public function grant(BulkGrantRequestDTO $request, bool $silent, ?string $reason, Authenticatable $actor): BulkGrantCountDTO;
}
