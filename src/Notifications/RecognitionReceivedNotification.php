<?php

declare(strict_types=1);

namespace Modules\Achievements\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Mail\Contracts\HasMailSubject;
use App\Application\Mail\DTOs\MailSubjectDTO;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use Illuminate\Notifications\Notification;
use Modules\Achievements\Infrastructure\Recognitions\RecognitionNotices;

/**
 * «Ana te ha reconocido: «Gran narración»» (#11), in the receiver's bell only. It names who gave
 * it (the receiver was there too) but never the game: the list on /logros/reconocimientos does not
 * either. Built before it is sent (NotifyRecognitionReceivedJob), so a failure fails the job.
 */
final class RecognitionReceivedNotification extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification
{
    public function __construct(
        public readonly SiteNotificationDTO $notice,
        public readonly MailSubjectDTO $subject,
    ) {}

    /**
     * About the recognition itself, so deleting it forgets the notice (RecognitionNotices). One
     * notice per recognition, never grouped: the core merges unread notices of a group under one
     * subject, so deleting one recognition would take the others' notice with it. The giver is its
     * actor, so anonymizing them forgets their name in it at once (the core's forgetActor()).
     */
    public static function for(string $recognitionId, string $giverId, ?string $giverName, string $typeName): self
    {
        $subject = new MailSubjectDTO(
            RecognitionNotices::SUBJECT_TYPE,
            $recognitionId,
            $typeName,
            'achievements::notifications.recognitions.subject_type',
        );

        return new self(new SiteNotificationDTO(
            title: $giverName === null
                ? __('achievements::notifications.recognitions.received_anonymous', ['type' => $typeName])
                : __('achievements::notifications.recognitions.received', ['person' => $giverName, 'type' => $typeName]),
            url: route('achievements.recognitions', [], false),
            icon: AchievementNoticeCategories::RECOGNITION_ICON,
            actorId: strtolower($giverId),
        ), $subject);
    }

    public function mailSubject(): MailSubjectDTO
    {
        return $this->subject;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [HasSiteNotification::CHANNEL];
    }

    public function mailCategory(): string
    {
        return AchievementNoticeCategories::RECOGNITIONS;
    }

    public function toSiteNotification(object $notifiable): SiteNotificationDTO
    {
        return $this->notice;
    }
}
