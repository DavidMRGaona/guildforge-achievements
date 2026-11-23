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

    /** «Ana te ha reconocido…» and «¿Quieres reconocer a alguien de…?» (#11): only about one's own games */
    public const string RECOGNITIONS = 'achievements.recognitions';

    public const string ICON = 'trophy';

    public const string RECOGNITION_ICON = 'heart';

    /**
     * @return list<MailCategoryDTO>
     */
    public static function all(): array
    {
        return [
            self::category(self::EARNED, 'earned'),
            self::category(self::ANNOUNCEMENTS, 'announcements'),
            self::category(self::RECOGNITIONS, 'recognitions', self::RECOGNITION_ICON),
        ];
    }

    private static function category(string $key, string $name, string $icon = self::ICON): MailCategoryDTO
    {
        return MailCategoryDTO::optional(
            key: $key,
            section: 'achievements::notifications.categories.section',
            label: 'achievements::notifications.categories.'.$name.'.label',
            description: 'achievements::notifications.categories.'.$name.'.description',
            icon: $icon,
            site: true,
            mail: false,
        );
    }
}
