<?php

declare(strict_types=1);

namespace Modules\Achievements\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Profiles\DTOs\ProfileCardDTO;
use App\Application\Profiles\DTOs\PublicProfileDTO;
use App\Application\Profiles\Services\ProfileCardServiceInterface;
use App\Application\Profiles\Services\PublicProfileServiceInterface;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notification;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;

/**
 * «Ana ha conseguido…» (achievements#6), in the bell of someone else: rare and challenge achievements
 * of one delivery, told together.
 *
 * Everything is asked again when it is delivered, and fails closed: the mechanic still on (settings
 * read strictly), the owner still active, still allowing it and not hiding their achievements, the
 * achievements still held, and the owner's profile visible to this reader (the core's profile page
 * rule, through PublicProfileServiceInterface). A secret is named only to a reader who holds it, or
 * once the owner featured it (which reveals it to everybody); anybody else gets it counted. Nothing
 * says where or with whom it was got: only the achievement's name.
 *
 * One instance goes to a whole batch (DeliverAchievementAnnouncementsJob), so what is about the owner
 * is read once.
 */
final class AchievementsAnnouncedNotification extends Notification implements HasMailCategory, HasSiteNotification
{
    public const string GROUP_KEY_PREFIX = 'achievements.announcements:';

    /** @var array{handle: string, achievements: list<Achievement>, featured: list<string>}|false|null */
    private array|false|null $owner = null;

    /**
     * @param  list<string>  $achievementIds  in the order they were got
     */
    public function __construct(
        public readonly string $ownerId,
        public readonly array $achievementIds,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [HasSiteNotification::CHANNEL];
    }

    public function mailCategory(): string
    {
        return AchievementNoticeCategories::ANNOUNCEMENTS;
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $owner = $this->owner();

        if ($owner === false || ! $notifiable instanceof Authenticatable) {
            return null;
        }

        $readerId = strtolower((string) $notifiable->getAuthIdentifier());

        if ($readerId === strtolower($this->ownerId)) {
            return null;
        }

        $profile = app(PublicProfileServiceInterface::class)->findByHandle($owner['handle'], $notifiable)->profile;

        if (! $profile instanceof PublicProfileDTO || strtolower($profile->userId) !== strtolower($this->ownerId)) {
            return null;
        }

        $person = $profile->shownPublicName();

        if ($person === null) {
            return null;
        }

        $readerHolds = array_flip(app(AchievementGrantRepositoryInterface::class)->heldAchievementIds([$readerId])[$readerId] ?? []);
        $named = [];
        $secrets = 0;

        foreach ($owner['achievements'] as $achievement) {
            $id = strtolower($achievement->id);

            if (! $achievement->secret || isset($readerHolds[$id]) || in_array($id, $owner['featured'], true)) {
                $named[] = $achievement->name;
            } else {
                $secrets++;
            }
        }

        $count = count($named) + $secrets;

        return new SiteNotificationDTO(
            title: match (true) {
                $count > 1 => __('achievements::notifications.announced.many', ['person' => $person, 'count' => $count]),
                $named !== [] => __('achievements::notifications.announced.one', ['person' => $person, 'name' => AchievementList::quoted($named[0])]),
                default => __('achievements::notifications.announced.secret', ['person' => $person]),
            },
            url: route('profiles.show', ['handle' => $profile->handle], false),
            body: $count > 1 ? AchievementList::sentence($named, $secrets) : null,
            icon: AchievementNoticeCategories::ICON,
            groupKey: self::GROUP_KEY_PREFIX.strtolower($this->ownerId),
            actorId: strtolower($this->ownerId),
            groupedTitle: static fn (): string => __('achievements::notifications.announced.grouped', ['person' => $person]),
        );
    }

    /**
     * What is about the owner, read once for the whole batch; false when nothing may be told.
     *
     * @return array{handle: string, achievements: list<Achievement>, featured: list<string>}|false
     */
    private function owner(): array|false
    {
        return $this->owner ??= $this->readOwner();
    }

    /**
     * @return array{handle: string, achievements: list<Achievement>, featured: list<string>}|false
     */
    private function readOwner(): array|false
    {
        $settings = app(AchievementsSettingsInterface::class)->readStrictly();

        if (! $settings instanceof AchievementsSettings || ! $settings->announcements) {
            return false;
        }

        $ownerId = strtolower($this->ownerId);
        $account = UserModel::query()->find($ownerId);

        if (! $account instanceof UserModel || $account->anonymized_at !== null) {
            return false;
        }

        $preferences = app(AchievementPreferencesRepositoryInterface::class)->find($ownerId);

        if (! $preferences->announceRare || $preferences->hidden) {
            return false;
        }

        $card = app(ProfileCardServiceInterface::class)->card($ownerId, $account);

        if (! $card instanceof ProfileCardDTO) {
            return false;
        }

        $held = array_flip(app(AchievementGrantRepositoryInterface::class)->heldAchievementIds([$ownerId])[$ownerId] ?? []);
        $catalogue = app(AchievementRepositoryInterface::class);
        $achievements = [];

        foreach ($this->achievementIds as $id) {
            $achievement = isset($held[strtolower($id)]) ? $catalogue->find($id) : null;

            if ($achievement instanceof Achievement) {
                $achievements[] = $achievement;
            }
        }

        return $achievements === [] ? false : ['handle' => $card->handle, 'achievements' => $achievements, 'featured' => $preferences->featured];
    }
}
