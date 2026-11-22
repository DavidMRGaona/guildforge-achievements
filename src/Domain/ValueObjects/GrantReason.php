<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * The note someone writes when granting or revoking by hand: one plain line of text, without control
 * or bidi characters, at most MAX_LENGTH characters. Blank means no reason.
 */
final class GrantReason
{
    public const int MAX_LENGTH = 500;

    public static function normalise(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        // Line breaks become spaces; other controls and bidi overrides go
        $reason = (string) preg_replace('/[\r\n\t]+/u', ' ', $reason);
        $reason = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $reason);
        $reason = trim((string) preg_replace('/\s{2,}/u', ' ', $reason));

        if ($reason === '') {
            return null;
        }

        return mb_substr($reason, 0, self::MAX_LENGTH);
    }
}
