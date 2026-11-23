<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

/**
 * One recognition as its owner lists it: the other person (who gave it, for the receiver; who got
 * it, for the giver), the type and the month and year alone, never the subject. `personName` is
 * null when that person has no public name; `personUrl` only while the owner may see their profile.
 */
final readonly class OwnRecognitionDTO
{
    public function __construct(
        public string $id,
        public RecognitionTypeViewDTO $type,
        public ?string $personName,
        public ?string $personUrl,
        public string $month,
    ) {}

    /**
     * @return array{id: string, type: array{id: string, name: string, icon: string, colour: string}, personName: string|null, personUrl: string|null, month: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->toArray(),
            'personName' => $this->personName,
            'personUrl' => $this->personUrl,
            'month' => $this->month,
        ];
    }
}
