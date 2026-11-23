<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\Mail\DTOs\MailSubjectDTO;
use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use App\Domain\Mail\ValueObjects\MutedMailSubject;
use RuntimeException;

/**
 * The core's mail subjects with forgetting a subject failing (the notices table down, say).
 */
final class FailingMailSubjects implements MailSubjectMuteServiceInterface
{
    public function mutedFor(object $user): array
    {
        return [];
    }

    public function isMuted(object $user, MailSubjectDTO $subject): bool
    {
        return false;
    }

    public function mute(object $user, MailSubjectDTO $subject): void {}

    public function unmute(object $user, string $type, string $id): ?MutedMailSubject
    {
        return null;
    }

    public function forgetSubject(string $type, string $id): void
    {
        throw new RuntimeException('The notices are down.');
    }
}
