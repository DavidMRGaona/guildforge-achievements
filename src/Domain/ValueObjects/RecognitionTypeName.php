<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * The name of a recognition type: one line, no control or bidi characters, at most 40 characters
 * (it is drawn as a short label next to a number).
 */
final class RecognitionTypeName
{
    public const int MAX_LENGTH = 40;

    public static function normalise(string $name): string
    {
        $name = (string) preg_replace('/[\r\n\t]+/u', ' ', $name);
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name);
        $name = trim((string) preg_replace('/\s{2,}/u', ' ', $name));

        return mb_substr($name, 0, self::MAX_LENGTH);
    }
}
