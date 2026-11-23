<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

/**
 * How many recognitions of one type somebody received: what everybody may see, never who gave
 * them or for what.
 */
final readonly class RecognitionTotalDTO
{
    public function __construct(
        public RecognitionTypeViewDTO $type,
        public int $count,
    ) {}

    /**
     * @return array{type: array{id: string, name: string, icon: string, colour: string}, count: int}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->toArray(), 'count' => $this->count];
    }
}
