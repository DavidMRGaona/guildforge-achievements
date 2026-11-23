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
 * reader and month: «Ana, Bob y 3 más consiguieron logros raros en noviembre».
 *
 * It says the month and nothing finer: no day, no hour, and people are listed by name, never by
 * when or with whom they got something. Everything is asked again when it is delivered, and fails
 * closed: the mechanic still on, each owner still active, still allowing it and not hiding their
 * achievements, the achievements still held (in any season), and each owner's profile visible to
 * this reader (the core's profile page rule, through PublicProfileServiceInterface). A reader is
 * never told about themselves. A secret is named only to a reader who holds it, or once its owner
 * featured it (which reveals it to everybody); anybody else gets it counted.
 *
 * One instance goes to a whole batch (DeliverAchievementAnnouncementsJob), which calls prepare()
 * first: what is about the owners, and what the readers hold (in one query, only when some secret
 * needs it), is read there, so a failure there is the job's, before anybody is told.
 */
final class AchievementsAnnouncedNotification extends Notification implements HasMailCategory, HasSiteNotification
{
    public const string GROUP_KEY_PREFIX = 'achievements.announcements:';

    /** An achievement held by fewer active people than the threshold */
    public const string RARE = 'rare';

    /** The reward of a collective challenge */
    public const string CHALLENGE = 'challenge';

    private bool $prepared = false;

    private bool $on = false;

    /** @var array<string, array{handle: string, achievements: list<array{achievement: Achievement, kind: string}>, featured: list<string>}> */
    private array $owners = [];

    /** @var array<string, array<string, int>> */
    private array $readerHoldings = [];

    /**
     * @param  string  $month  `Y-m` of the display zone
     * @param  array<string, array<string, string>>  $entries  owner id => achievement id => kind (RARE, CHALLENGE)
     */
    public function __construct(
        public readonly string $month,
        public readonly array $entries,
    ) {}

    /**
     * Reads everything the batch shares; anything that fails, fails here.
     *
     * @param  list<string>  $readerIds
     */
    public function prepare(AchievementsSettings $settings, array $readerIds): void
    {
        $this->prepared = true;
        $this->on = $settings->announcements;
        $this->owners = [];
        $this->readerHoldings = [];

        if (! $this->on) {
            return;
        }

        $needsHoldings = false;

        foreach (array_keys($this->entries) as $ownerId) {
            $owner = $this->readOwner(strtolower((string) $ownerId));

            if ($owner === null) {
                continue;
            }

            $this->owners[strtolower((string) $ownerId)] = $owner;

            foreach ($owner['achievements'] as $item) {
                $needsHoldings = $needsHoldings || ($item['achievement']->secret && ! in_array(strtolower($item['achievement']->id), $owner['featured'], true));
            }
        }

        if ($needsHoldings && $readerIds !== []) {
            $readerIds = array_values(array_unique(array_map(strtolower(...), $readerIds)));
            $found = app(AchievementGrantRepositoryInterface::class)->heldAchievementIds($readerIds);

            foreach ($readerIds as $readerId) {
                $this->readerHoldings[$readerId] = array_flip($found[$readerId] ?? []);
            }
        }
    }

    public function hasAnything(): bool
    {
        return $this->on && $this->owners !== [];
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
        if (! $notifiable instanceof Authenticatable) {
            return null;
        }

        $readerId = strtolower((string) $notifiable->getAuthIdentifier());

        // Sent on its own, outside a batch: read what it needs now
        if (! $this->prepared) {
            $settings = app(AchievementsSettingsInterface::class)->readStrictly();

            if (! $settings instanceof AchievementsSettings) {
                return null;
            }

            $this->prepare($settings, [$readerId]);
        }

        if (! $this->hasAnything()) {
            return null;
        }

        $people = [];

        foreach ($this->owners as $ownerId => $owner) {
            $person = $this->personFor((string) $ownerId, $owner, $readerId, $notifiable);

            if ($person !== null) {
                $people[] = $person;
            }
        }

        if ($people === []) {
            return null;
        }

        // By name, never by when: the order says nothing of who got what together; the same name
        // twice is ordered by account
        usort($people, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['name'], $a['ownerId']] <=> [mb_strtolower($b['name']), $b['name'], $b['ownerId']]);

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
     * Who, what (one achievement or several; rare, of challenges or both) and the month.
     *
     * @param  non-empty-list<array{ownerId: string, name: string, url: string, named: list<string>, secrets: int, kinds: list<string>}>  $people
     */
    private function title(array $people): string
    {
        $count = array_sum(array_map(static fn (array $person): int => count($person['named']) + $person['secrets'], $people));
        $kinds = array_values(array_unique(array_merge(...array_map(static fn (array $person): array => $person['kinds'], $people))));
        $kind = count($kinds) > 1 ? 'mixed' : ($kinds[0] ?? self::RARE);
        $what = trans_choice('achievements::notifications.announced.what.'.$kind, $count);
        $names = array_map(static fn (array $person): string => $person['name'], $people);
        $month = $this->monthName();

        return match (count($names)) {
            1 => __('achievements::notifications.announced.one', ['person' => $names[0], 'what' => $what, 'month' => $month]),
            2 => __('achievements::notifications.announced.two', ['first' => $names[0], 'second' => $names[1], 'what' => $what, 'month' => $month]),
            default => trans_choice('achievements::notifications.announced.many', count($names) - 2, [
                'first' => $names[0],
                'second' => $names[1],
                'count' => count($names) - 2,
                'what' => $what,
                'month' => $month,
            ]),
        };
    }

    /**
     * @param  array{handle: string, achievements: list<array{achievement: Achievement, kind: string}>, featured: list<string>}  $owner
     * @return array{ownerId: string, name: string, url: string, named: list<string>, secrets: int, kinds: list<string>}|null
     */
    private function personFor(string $ownerId, array $owner, string $readerId, Authenticatable $reader): ?array
    {
        if ($ownerId === $readerId) {
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
        $kinds = [];

        foreach ($owner['achievements'] as $item) {
            $achievement = $item['achievement'];
            $id = strtolower($achievement->id);
            $kinds[$item['kind']] = true;

            if (! $achievement->secret || in_array($id, $owner['featured'], true) || $this->readerHolds($readerId, $id)) {
                $named[] = $achievement->name;
            } else {
                $secrets++;
            }
        }

        sort($named, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'ownerId' => $ownerId,
            'name' => $name,
            'url' => route('profiles.show', ['handle' => $profile->handle], false),
            'named' => $named,
            'secrets' => $secrets,
            'kinds' => array_keys($kinds),
        ];
    }

    private function readerHolds(string $readerId, string $achievementId): bool
    {
        // A reader outside the batch prepared (a single send) is read on its own
        if (! isset($this->readerHoldings[$readerId])) {
            $found = app(AchievementGrantRepositoryInterface::class)->heldAchievementIds([$readerId]);
            $this->readerHoldings[$readerId] = array_flip($found[$readerId] ?? []);
        }

        return isset($this->readerHoldings[$readerId][$achievementId]);
    }

    /**
     * What is about one owner; null when nothing may be told about them.
     *
     * @return array{handle: string, achievements: list<array{achievement: Achievement, kind: string}>, featured: list<string>}|null
     */
    private function readOwner(string $ownerId): ?array
    {
        $account = UserModel::query()->find($ownerId);

        // Deactivated (not found) or anonymized meanwhile: nothing is told about them
        if (! $account instanceof UserModel || $account->anonymized_at !== null) {
            return null;
        }

        $preferences = app(AchievementPreferencesRepositoryInterface::class)->find($ownerId);

        if (! $preferences->announceRare || $preferences->hidden) {
            return null;
        }

        $card = app(ProfileCardServiceInterface::class)->card($ownerId, $account);

        if (! $card instanceof ProfileCardDTO) {
            return null;
        }

        // Any season counts: what is still held, in whichever
        $held = array_flip(app(AchievementGrantRepositoryInterface::class)->heldAchievementIds([$ownerId])[$ownerId] ?? []);
        $catalogue = app(AchievementRepositoryInterface::class);
        $achievements = [];

        foreach ($this->entries[$ownerId] ?? [] as $id => $kind) {
            $achievement = isset($held[strtolower((string) $id)]) ? $catalogue->find((string) $id) : null;

            if ($achievement instanceof Achievement) {
                $achievements[] = ['achievement' => $achievement, 'kind' => $kind === self::CHALLENGE ? self::CHALLENGE : self::RARE];
            }
        }

        return $achievements === [] ? null : ['handle' => $card->handle, 'achievements' => $achievements, 'featured' => $preferences->featured];
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
