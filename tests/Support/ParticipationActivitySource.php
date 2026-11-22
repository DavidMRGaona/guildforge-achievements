<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\Activity\Contracts\ActivitySourceInterface;
use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\DTOs\ActivityHistoryPageDTO;
use App\Application\Activity\DTOs\ActivitySubjectDTO;
use App\Application\Activity\DTOs\ActivitySubjectFactsDTO;
use App\Domain\Activity\ActivityKind;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

/**
 * Events whose attendees a test writes beforehand, some of them hidden from whoever is viewing (as a
 * draft or a private event would be), and whose facts() can be made to fail.
 */
final class ParticipationActivitySource implements ActivitySourceInterface
{
    public const string TYPE = 'achievements.participation';

    /** @var array<string, array{title: string, facts: list<ActivityFactDTO>}> */
    private static array $subjects = [];

    /** @var array<string, true> */
    private static array $hidden = [];

    private static bool $failingFacts = false;

    private static bool $failingDescriptions = false;

    public static function reset(): void
    {
        self::$subjects = [];
        self::$hidden = [];
        self::$failingFacts = false;
        self::$failingDescriptions = false;
    }

    /**
     * @param  list<ActivityFactDTO>  $facts
     */
    public static function put(string $subjectId, string $title, array $facts, bool $hidden = false): void
    {
        self::$subjects[$subjectId] = ['title' => $title, 'facts' => $facts];

        if ($hidden) {
            self::$hidden[$subjectId] = true;
        }
    }

    public static function failFacts(): void
    {
        self::$failingFacts = true;
    }

    public static function failDescriptions(): void
    {
        self::$failingDescriptions = true;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function kind(): ActivityKind
    {
        return ActivityKind::Event;
    }

    public function label(): string
    {
        return 'Eventos de prueba';
    }

    public function facts(string $subjectId): array
    {
        if (self::$failingFacts) {
            throw new RuntimeException('The source is down.');
        }

        return self::$subjects[$subjectId]['facts'] ?? [];
    }

    public function history(?string $cursor, int $limit): ActivityHistoryPageDTO
    {
        $page = [];

        foreach (self::$subjects as $id => $subject) {
            $page[] = new ActivitySubjectFactsDTO((string) $id, $subject['facts']);
        }

        return new ActivityHistoryPageDTO($page, null);
    }

    public function describe(array $subjectIds, ?Authenticatable $viewer): array
    {
        if (self::$failingDescriptions) {
            throw new RuntimeException('The source is down.');
        }

        $described = [];

        foreach ($subjectIds as $id) {
            if (isset(self::$subjects[$id]) && ! isset(self::$hidden[$id])) {
                $described[$id] = new ActivitySubjectDTO($id, self::$subjects[$id]['title'], '/eventos/'.$id);
            }
        }

        return $described;
    }
}
