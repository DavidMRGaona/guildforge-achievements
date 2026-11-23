<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Recognitions;

/**
 * A kind of recognition as the site shows it: its name and the core's badge icon and colour.
 */
final readonly class RecognitionType
{
    public function __construct(
        public string $id,
        public string $name,
        public string $icon,
        public string $colour,
        public bool $active,
    ) {}
}
