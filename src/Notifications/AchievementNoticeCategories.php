<?php

declare(strict_types=1);

namespace Modules\Achievements\Notifications;

use App\Application\Mail\DTOs\MailCategoryDTO;

/**
 * The module's notice categories (achievements#6), in the bell only and never mailed, so there is no
 * digest to offer. Both optional and on by default: each person turns them off in «Avisos».
 */
final class AchievementNoticeCategories
{
    /** «Has conseguido…»: the owner hears of what they just got */
    public const string EARNED = 'achievements.earned';

    /** «Ana, Bob y 3 más consiguieron…»: the monthly summary of rare and challenge achievements of people whose profile one may see */
    public const string ANNOUNCEMENTS = 'achievements.announcements';

    public const string ICON = 'trophy';

    /**
     * @return list<MailCategoryDTO>
     */
    public static function all(): array
    {
        return [
            self::category(self::EARNED, 'earned'),
            self::category(self::ANNOUNCEMENTS, 'announcements'),
        ];
    }

    private static function category(string $key, string $name): MailCategoryDTO
    {
        return MailCategoryDTO::optional(
            key: $key,
            section: 'achievements::notifications.categories.section',
            label: 'achievements::notifications.categories.'.$name.'.label',
            description: 'achievements::notifications.categories.'.$name.'.description',
            icon: self::ICON,
            site: true,
            mail: false,
        );
    }
}
