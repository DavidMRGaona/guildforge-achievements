<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * Who ran one subject and who created it, as account ids in lower case, sorted and without repeats.
 * Read only to decide who was somebody's mentor; never shown to anybody.
 */
final readonly class SubjectHosts
{
    /** @var list<string> */
    public array $directors;

    /** @var list<string> */
    public array $creators;

    /**
     * @param  list<string>  $directors
     * @param  list<string>  $creators
     */
    public function __construct(array $directors, array $creators)
    {
        $this->directors = $this->ids($directors);
        $this->creators = $this->ids($creators);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_map(strtolower(...), $ids)));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
