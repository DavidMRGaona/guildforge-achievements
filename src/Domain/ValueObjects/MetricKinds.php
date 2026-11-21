<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * The kinds of activity the module can count, as plain strings.
 *
 * The list follows `App\Domain\Activity\ActivityKind`, the core's enum (core#774): the values are
 * the same, so the settings written here keep working when the module starts reading the enum.
 * The settings page offers a switch only for the kinds with an active source (a later issue);
 * until then every kind is listed.
 */
final class MetricKinds
{
    public const string GAME_SESSION = 'game_session';

    public const string CAMPAIGN = 'campaign';

    public const string TOURNAMENT = 'tournament';

    public const string EVENT = 'event';

    public const string FORUM_POST = 'forum_post';

    public const string FORUM_DISCUSSION = 'forum_discussion';

    public const string MEMBERSHIP_YEAR = 'membership_year';

    public const string SURVEY_RESPONSE = 'survey_response';

    public const string VENUE_BOOKING = 'venue_booking';

    public const string ACCOUNT = 'account';

    public const string PROFILE_COMPLETED = 'profile_completed';

    public const string PROPOSAL = 'proposal';

    /**
     * @var list<string>
     */
    public const array ALL = [
        self::GAME_SESSION,
        self::CAMPAIGN,
        self::TOURNAMENT,
        self::EVENT,
        self::FORUM_POST,
        self::FORUM_DISCUSSION,
        self::MEMBERSHIP_YEAR,
        self::SURVEY_RESPONSE,
        self::VENUE_BOOKING,
        self::ACCOUNT,
        self::PROFILE_COMPLETED,
        self::PROPOSAL,
    ];

    public static function isKnown(string $kind): bool
    {
        return in_array($kind, self::ALL, true);
    }
}
