<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\OwnRecognitionDTO;
use Modules\Achievements\Application\DTOs\RecognitionSubjectDTO;
use Modules\Achievements\Application\DTOs\RecognitionTotalDTO;
use Modules\Achievements\Application\Enums\GiveRecognitionOutcome;

/**
 * Recognitions between people who played together (#11).
 *
 * Who played with whom is not public, so:
 * - the giver is offered only the other participants of a session or campaign they took part in
 *   (the core's activity reader, read now; nothing when it is not Read), within the window, whose
 *   profile the giver may see (the core's access rule), with a public name and not hiding their
 *   achievements; tournaments never;
 * - the receiver sees who gave it, with the month and year, never the subject;
 * - what was given is seen by the giver alone;
 * - anybody else sees totals per type, counted up to the start of the month (RecognitionCut).
 */
interface RecognitionsInterface
{
    /**
     * Whether recognitions are on and some type can be given.
     */
    public function available(): bool;

    /**
     * The sessions and campaigns the giver may still recognise people of, the most recent first;
     * those with nobody to offer are left out.
     *
     * @return list<RecognitionSubjectDTO>
     */
    public function openSubjects(Authenticatable $giver): array;

    public function give(Authenticatable $giver, string $sourceType, string $subjectId, string $receiverHandle, string $typeId): GiveRecognitionOutcome;

    /**
     * What the account received, newest first, with who gave each one.
     *
     * @return list<OwnRecognitionDTO>
     */
    public function received(Authenticatable $owner): array;

    /**
     * What the account gave, newest first, with who got each one.
     *
     * @return list<OwnRecognitionDTO>
     */
    public function given(Authenticatable $owner): array;

    /**
     * The receiver takes one away; false when it is not theirs or is already gone.
     */
    public function deleteReceived(Authenticatable $owner, string $recognitionId): bool;

    /**
     * Totals per type the viewer may see of the owner: the owner's own now, anybody else's as of
     * the start of the month. The caller decides whether the viewer may see them at all.
     *
     * @return list<RecognitionTotalDTO> most first, then by name; types with none left out
     */
    public function totals(string $ownerId, ?string $viewerId): array;
}
