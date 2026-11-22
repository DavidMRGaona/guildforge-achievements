<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\DTOs\ActivityHistoryPageDTO;
use App\Domain\Activity\ActivityKind;
use RuntimeException;
use Tests\Support\Activity\FakeActivitySource;

/**
 * A source whose answers a test writes beforehand: the facts of each subject and the history page
 * of each cursor. A missing answer (or a null page) throws, as a broken source would.
 */
final class ScriptedActivitySource extends FakeActivitySource
{
    public const string TYPE = 'achievements.scripted';

    /** @var array<string, list<ActivityFactDTO>> */
    private static array $facts = [];

    /** @var array<string, ActivityHistoryPageDTO|null> keyed by cursor, '' for the first page */
    private static array $pages = [];

    /** @var array<string, true>|true subjects whose facts() throws, or true for every one */
    private static array|bool $failingFacts = [];

    public static function reset(): void
    {
        self::$facts = [];
        self::$pages = [];
        self::$failingFacts = [];
    }

    /**
     * @param  list<ActivityFactDTO>  $facts
     */
    public static function putFacts(string $subjectId, array $facts): void
    {
        self::$facts[$subjectId] = $facts;
    }

    public static function failFacts(?string $subjectId = null): void
    {
        if ($subjectId === null) {
            self::$failingFacts = true;
        } elseif (is_array(self::$failingFacts)) {
            self::$failingFacts[$subjectId] = true;
        }
    }

    public static function putPage(?string $cursor, ?ActivityHistoryPageDTO $page): void
    {
        self::$pages[$cursor ?? ''] = $page;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function kind(): ActivityKind
    {
        return ActivityKind::Tournament;
    }

    public function facts(string $subjectId): array
    {
        if (self::$failingFacts === true || isset(self::$failingFacts[$subjectId])) {
            throw new RuntimeException('The source is down.');
        }

        return self::$facts[$subjectId] ?? [];
    }

    public function history(?string $cursor, int $limit): ActivityHistoryPageDTO
    {
        return self::$pages[$cursor ?? ''] ?? throw new RuntimeException('The source is down.');
    }
}
