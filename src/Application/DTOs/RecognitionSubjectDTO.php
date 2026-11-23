<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

/**
 * A session or a campaign the giver took part in and may still recognise people of, titled as its
 * source describes it to the giver. `closesOn` is the last display day it can be done.
 */
final readonly class RecognitionSubjectDTO
{
    /**
     * @param  list<RecognisablePersonDTO>  $people
     */
    public function __construct(
        public string $sourceType,
        public string $subjectId,
        public string $title,
        public ?string $url,
        public string $closesOn,
        public array $people,
    ) {}

    /**
     * @return array{sourceType: string, subjectId: string, title: string, url: string|null, closesOn: string, people: list<array{handle: string, publicName: string, profileUrl: string, avatarUrl: string|null, givenTypeId: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'sourceType' => $this->sourceType,
            'subjectId' => $this->subjectId,
            'title' => $this->title,
            'url' => $this->url,
            'closesOn' => $this->closesOn,
            'people' => array_map(static fn (RecognisablePersonDTO $person): array => $person->toArray(), $this->people),
        ];
    }
}
