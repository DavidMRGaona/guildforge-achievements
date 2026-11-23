<?php

declare(strict_types=1);

namespace Modules\Achievements\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Profiles\DTOs\ProfileCardDTO;
use App\Application\Profiles\Services\ProfileCardServiceInterface;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notification;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;

/**
 * «Has conseguido…» (achievements#6), in the owner's bell only: one notice for every achievement of
 * one delivery, so an evaluation that grants many tells them once. What the owner no longer holds
 * (revoked meanwhile, in any season) is left out; with nothing left, there is no notice. Their own
 * achievements, so secret ones are named.
 *
 * Built by for() before it is sent (DeliverAchievementNoticesJob does it right then, in its
 * transaction): the core's site channel swallows what fails while it builds a notice, so whatever
 * may fail happens before, where it fails the job and leaves the grants waiting for the retry.
 */
final class AchievementsEarnedNotification extends Notification implements HasMailCategory, HasSiteNotification
{
    public const string GROUP_KEY = 'achievements.earned';

    private function __construct(
        public readonly SiteNotificationDTO $notice,
    ) {}

    /**
     * The notice for these achievements of the owner, in the order they were got; null when none is
     * still held.
     *
     * @param  list<string>  $achievementIds
     */
    public static function for(Authenticatable $owner, array $achievementIds): ?self
    {
        $ownerId = strtolower((string) $owner->getAuthIdentifier());
        $held = array_flip(app(AchievementGrantRepositoryInterface::class)->heldAchievementIds([$ownerId])[$ownerId] ?? []);
        $catalogue = app(AchievementRepositoryInterface::class);
        $achievements = [];

        foreach ($achievementIds as $id) {
            $achievement = isset($held[strtolower($id)]) ? $catalogue->find($id) : null;

            if ($achievement instanceof Achievement) {
                $achievements[] = $achievement;
            }
        }

        if ($achievements === []) {
            return null;
        }

        $single = count($achievements) === 1 ? $achievements[0] : null;

        return new self(new SiteNotificationDTO(
            title: $single instanceof Achievement
                ? __('achievements::notifications.earned.one', ['name' => AchievementList::quoted($single->name)])
                : __('achievements::notifications.earned.many', ['count' => count($achievements)]),
            url: self::url($ownerId, $owner),
            body: $single instanceof Achievement
                ? ($single->description === '' ? null : $single->description)
                : AchievementList::sentence(array_map(static fn (Achievement $achievement): string => $achievement->name, $achievements)),
            icon: AchievementNoticeCategories::ICON,
            groupKey: self::GROUP_KEY,
            groupedTitle: static fn (): string => __('achievements::notifications.earned.grouped'),
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
        return AchievementNoticeCategories::EARNED;
    }

    public function toSiteNotification(object $notifiable): SiteNotificationDTO
    {
        return $this->notice;
    }

    /**
     * The owner's public profile, where the section lists them; the catalogue without one.
     */
    private static function url(string $ownerId, Authenticatable $owner): string
    {
        $card = app(ProfileCardServiceInterface::class)->card($ownerId, $owner);

        return $card instanceof ProfileCardDTO
            ? route('profiles.show', ['handle' => $card->handle], false)
            : route('achievements.index', [], false);
    }
}
