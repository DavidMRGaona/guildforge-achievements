<?php

declare(strict_types=1);

namespace Modules\Achievements\Notifications;

/**
 * The achievements of one notice as a sentence: the first few by name, then how many more and how
 * many secret ones («A», «B» y un logro secreto), so a notice of hundreds stays short.
 */
final class AchievementList
{
    public const int NAMED = 3;

    public static function quoted(string $name): string
    {
        return '«'.$name.'»';
    }

    /**
     * @param  list<string>  $names  achievements the reader may see named, in the order got
     * @param  int  $secrets  achievements the reader may not see named: only counted
     */
    public static function sentence(array $names, int $secrets = 0): string
    {
        $parts = array_map(self::quoted(...), array_slice($names, 0, self::NAMED));
        $more = count($names) - count($parts);

        if ($more > 0) {
            $parts[] = trans_choice('achievements::notifications.list.more', $more, ['count' => $more]);
        }

        if ($secrets > 0) {
            $parts[] = trans_choice('achievements::notifications.list.secrets', $secrets, ['count' => $secrets]);
        }

        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' '.__('achievements::notifications.list.and').' '.$last;
    }
}
