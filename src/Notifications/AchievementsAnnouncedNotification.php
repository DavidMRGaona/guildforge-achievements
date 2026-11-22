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
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;

/**
 * The monthly summary of others' rare and challenge achievements (achievements#6), one notice per
 * reader: «Ana, Bob y 3 más consiguieron logros raros en noviembre».
 *
 * It says the month and nothing finer: no day, no hour, and people are listed by name, never by
 * when or with whom they got something. Everything is asked again when it is delivered, and fails
 * closed: the mechanic still on (settings read strictly), each owner still active, still allowing it
 * and not hiding their achievements, the achievements still held, and each owner's profile visible
 * to this reader (the core's profile page rule, through PublicProfileServiceInterface). A reader is
 * never told about themselves. A secret is named only to a reader who holds it, or once its owner
 * featured it (which reveals it to everybody); anybody else gets it counted.
 *
 * One instance goes to a whole batch (DeliverAchievementAnnouncementsJob): what is about the owners
 * is read once, and what the readers hold in one query, only when some secret needs it.
 */
final class AchievementsAnnouncedNotification extends Notification implements HasMailCategory, HasSiteNotification
{
    public const string GROUP_KEY_PREFIX = 'achievements.announcements:';

    private ?bool $on = null;

    /** @var array<string, array{handle: string, achievements: list<Achievement>, featured: list<string>}|false> */
    private array $owners = [];

    /** @var list<string> */
    private array $readerIds = [];

    /** @var array<string, array<string, int>>|null */
    private ?array $readerHoldings = null;

    /**
     * @param  string  $month  `Y-m` of the display zone
     * @param  array<string, list<string>>  $entries  owner id => achievement ids
     */
    public function __construct(
        public readonly string $month,
        public readonly array $entries,
    ) {}

    /**
     * The accounts this instance goes to, so what they hold is read for all of them at once.
     *
     * @param  list<string>  $readerIds
     */
    public function forReaders(array $readerIds): void
    {
        $this->readerIds = array_values(array_unique(array_map(strtolower(...), $readerIds)));
        $this->readerHoldings = null;
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
        return AchievementNoticeCategories::ANNOUNCEMENTS;
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        if (! $notifiable instanceof Authenticatable || ! $this->isOn()) {
            return null;
        }

        $readerId = strtolower((string) $notifiable->getAuthIdentifier());
        $people = [];

        foreach (array_keys($this->entries) as $ownerId) {
            $person = $this->personFor(strtolower((string) $ownerId), $readerId, $notifiable);

            if ($person !== null) {
                $people[] = $person;
            }
        }

        if ($people === []) {
            return null;
        }

        // By name, never by when: the order says nothing of who got what together
        usort($people, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['name']] <=> [mb_strtolower($b['name']), $b['name']]);

        $title = $this->title($people);

        return new SiteNotificationDTO(
            title: $title,
            url: count($people) === 1 ? $people[0]['url'] : route('achievements.index', [], false),
            body: implode('; ', array_map(
                static fn (array $person): string => __('achievements::notifications.announced.person', [
                    'person' => $person['name'],
                    'achievements' => AchievementList::sentence($person['named'], $person['secrets']),
                ]),
                $people,
            )),
            icon: AchievementNoticeCategories::ICON,
            groupKey: self::GROUP_KEY_PREFIX.$this->month,
            groupedTitle: static fn (): string => $title,
        );
    }

    /**
     * @param  non-empty-list<array{name: string, url: string, named: list<string>, secrets: int}>  $people
     */
    private function title(array $people): string
    {
        $month = $this->monthName();
        $names = array_map(static fn (array $person): string => $person['name'], $people);

        return match (count($names)) {
            1 => __('achievements::notifications.announced.one', ['person' => $names[0], 'month' => $month]),
            2 => __('achievements::notifications.announced.two', ['first' => $names[0], 'second' => $names[1], 'month' => $month]),
            default => trans_choice('achievements::notifications.announced.many', count($names) - 2, [
                'first' => $names[0],
                'second' => $names[1],
                'count' => count($names) - 2,
                'month' => $month,
            ]),
        };
    }

    /**
     * @return array{name: string, url: string, named: list<string>, secrets: int}|null
     */
    private function personFor(string $ownerId, string $readerId, Authenticatable $reader): ?array
    {
        if ($ownerId === $readerId) {
            return null;
        }

        $owner = $this->owner($ownerId);

        if ($owner === false) {
            return null;
        }

        $profile = app(PublicProfileServiceInterface::class)->findByHandle($owner['handle'], $reader)->profile;

        if (! $profile instanceof PublicProfileDTO || strtolower($profile->userId) !== $ownerId) {
            return null;
        }

        $name = $profile->shownPublicName();

        if ($name === null) {
            return null;
        }

        $named = [];
        $secrets = 0;

        foreach ($owner['achievements'] as $achievement) {
            $id = strtolower($achievement->id);

            if (! $achievement->secret || in_array($id, $owner['featured'], true) || $this->readerHolds($readerId, $id)) {
                $named[] = $achievement->name;
            } else {
                $secrets++;
            }
        }

        sort($named, SORT_NATURAL | SORT_FLAG_CASE);

        return ['name' => $name, 'url' => route('profiles.show', ['handle' => $profile->handle], false), 'named' => $named, 'secrets' => $secrets];
    }

    private function readerHolds(string $readerId, string $achievementId): bool
    {
        if (! isset($this->readerHoldings[$readerId])) {
            // The first time, the whole batch in one query; a reader outside it, on its own
            $ids = $this->readerHoldings === null && in_array($readerId, $this->readerIds, true) ? $this->readerIds : [$readerId];
            $found = app(AchievementGrantRepositoryInterface::class)->heldAchievementIds($ids);

            foreach ($ids as $id) {
                $this->readerHoldings[$id] = array_flip($found[$id] ?? []);
            }
        }

        return isset($this->readerHoldings[$readerId][$achievementId]);
    }

    private function isOn(): bool
    {
        if ($this->on === null) {
            $settings = app(AchievementsSettingsInterface::class)->readStrictly();
            $this->on = $settings instanceof AchievementsSettings && $settings->announcements;
        }

        return $this->on;
    }

    /**
     * What is about one owner, read once for the whole batch; false when nothing may be told.
     *
     * @return array{handle: string, achievements: list<Achievement>, featured: list<string>}|false
     */
    private function owner(string $ownerId): array|false
    {
        return $this->owners[$ownerId] ??= $this->readOwner($ownerId);
    }

    /**
     * @return array{handle: string, achievements: list<Achievement>, featured: list<string>}|false
     */
    private function readOwner(string $ownerId): array|false
    {
        $account = UserModel::query()->find($ownerId);

        // Deactivated (not found) or anonymized meanwhile: nothing is told about them
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

        foreach ($this->entries[$ownerId] ?? [] as $id) {
            $achievement = isset($held[strtolower($id)]) ? $catalogue->find($id) : null;

            if ($achievement instanceof Achievement) {
                $achievements[] = $achievement;
            }
        }

        return $achievements === [] ? false : ['handle' => $card->handle, 'achievements' => $achievements, 'featured' => $preferences->featured];
    }

    private function monthName(): string
    {
        $month = Date::createFromFormat('!Y-m', $this->month);

        if (! $month instanceof CarbonInterface) {
            return $this->month;
        }

        $month->locale(app()->getLocale());

        return $month->translatedFormat('F');
    }
}
