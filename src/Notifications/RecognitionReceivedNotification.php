<?php

declare(strict_types=1);

namespace Modules\Achievements\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use Illuminate\Notifications\Notification;

/**
 * «Ana te ha reconocido: «Gran narración»» (#11), in the receiver's bell only. It names who gave
 * it (the receiver was there too) but never the game: the list on /logros/reconocimientos does not
 * either. Built before it is sent (NotifyRecognitionReceivedJob), so a failure fails the job.
 */
final class RecognitionReceivedNotification extends Notification implements HasMailCategory, HasSiteNotification
{
    public const string GROUP_KEY = 'achievements.recognitions.received';

    public function __construct(
        public readonly SiteNotificationDTO $notice,
    ) {}

    public static function for(?string $giverName, string $typeName): self
    {
        return new self(new SiteNotificationDTO(
            title: $giverName === null
                ? __('achievements::notifications.recognitions.received_anonymous', ['type' => $typeName])
                : __('achievements::notifications.recognitions.received', ['person' => $giverName, 'type' => $typeName]),
            url: route('achievements.recognitions', [], false),
            icon: AchievementNoticeCategories::RECOGNITION_ICON,
            groupKey: self::GROUP_KEY,
            groupedTitle: static fn (): string => __('achievements::notifications.recognitions.received_grouped'),
        ));
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
