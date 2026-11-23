<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Recognitions;

use DateTimeImmutable;

/**
 * One recognition (#11): `giverId` recognised `receiverId` with a type for a subject they both
 * took part in. Who gave it is seen by the giver and the receiver only; the subject by the giver
 * alone; anybody else only counts them, by type, as of the start of the month.
 */
final readonly class Recognition
{
    public function __construct(
        public string $id,
        public string $typeId,
        public string $giverId,
        public string $receiverId,
        public string $sourceType,
        public string $subjectId,
        public string $kind,
        public DateTimeImmutable $givenAt,
    ) {}
}
