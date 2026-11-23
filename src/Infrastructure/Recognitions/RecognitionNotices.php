<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Recognitions;

use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Each «Ana te ha reconocido…» notice is about its recognition (subject `achievements.recognition`
 * with the recognition's id). Once a recognition is gone, whatever deleted it (the receiver, the
 * moderation, the ledger, an anonymization), its notice goes too: it would name who gave
 * something that no longer exists. A failure is logged and never stops the deletion.
 */
final readonly class RecognitionNotices
{
    public const string SUBJECT_TYPE = 'achievements.recognition';

    public function __construct(
        private MailSubjectMuteServiceInterface $subjects,
    ) {}

    /**
     * @param  list<string>  $recognitionIds
     */
    public function forget(array $recognitionIds): void
    {
        foreach ($recognitionIds as $id) {
            try {
                $this->subjects->forgetSubject(self::SUBJECT_TYPE, $id);
            } catch (Throwable $exception) {
                Log::warning('[Achievements] The notice of a deleted recognition could not be forgotten.', [
                    'reason' => 'recognition_notice_not_forgotten',
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
