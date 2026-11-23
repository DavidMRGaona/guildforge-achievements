<?php

declare(strict_types=1);

namespace Modules\Achievements\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use Illuminate\Notifications\Notification;
use Modules\Achievements\Application\DTOs\RecognitionSubjectDTO;

/**
 * «¿Quieres reconocer a alguien de «…»?» (#11): to one participant about their own sessions and
 * campaigns, titled as the source describes them to that person. Nobody else hears of it.
 */
final class RecognitionPromptNotification extends Notification implements HasMailCategory, HasSiteNotification
{
    public const string GROUP_KEY = 'achievements.recognitions.prompt';

    public function __construct(
        public readonly SiteNotificationDTO $notice,
    ) {}

    /**
     * @param  non-empty-list<RecognitionSubjectDTO>  $subjects  the most recent first
     */
    public static function for(array $subjects, string $lastDay): self
    {
        $single = count($subjects) === 1 ? $subjects[0] : null;

        return new self(new SiteNotificationDTO(
            title: $single instanceof RecognitionSubjectDTO
                ? __('achievements::notifications.recognitions.prompt_one', ['title' => $single->title])
                : __('achievements::notifications.recognitions.prompt_many'),
            url: route('achievements.recognitions', [], false),
            body: $single instanceof RecognitionSubjectDTO
                ? __('achievements::notifications.recognitions.prompt_body', ['date' => $lastDay])
                : AchievementList::sentence(array_map(static fn (RecognitionSubjectDTO $subject): string => $subject->title, $subjects)),
            icon: AchievementNoticeCategories::RECOGNITION_ICON,
            groupKey: self::GROUP_KEY,
            groupedTitle: static fn (): string => __('achievements::notifications.recognitions.prompt_grouped'),
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
