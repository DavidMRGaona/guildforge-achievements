/** An achievement as the server sends it: never its rule, points or holders */
export interface AchievementSummary {
    /** Null for a masked secret: its id would tell who else holds it */
    id: string | null;
    name: string;
    description: string;
    /** One of the core's badge icons (`ProfileBadgeIcons::NAMES`) */
    icon: string;
    /** One of the core's badge colours (`ProfileBadgeColour`) */
    colour: string;
    /** https image of Cloudinary, square, or null: draw the icon */
    imageUrl: string | null;
    /** A secret one the viewer does not hold: «Logro secreto» with a generic look */
    masked: boolean;
    /** Can be got again each season (a calendar year); never for a masked one */
    repeats: boolean;
}

/** How far someone is: `inMonths` for a streak (months in a row) */
export interface AchievementProgress {
    value: number;
    threshold: number;
    inMonths: boolean;
}

/** An achievement the profile's owner holds (never masked: secret ones the viewer may not see are only counted) */
export interface EarnedAchievement extends AchievementSummary {
    /** ISO instant in the display zone: only the owner gets it */
    grantedAt: string | null;
    /** Display month (`YYYY-MM`): all anybody else gets, since the instant tells who played together */
    grantedMonth: string | null;
    /** Share of active people holding it, in percent; null when not shown */
    rarity: number | null;
    featured: boolean;
    /** Seasons it was got in («2027»), newest first; empty when it does not repeat */
    seasons: string[];
}

/** What the owner does not hold yet (only ever sent to them) */
export interface LockedAchievement extends AchievementSummary {
    progress: AchievementProgress | null;
    /** Granted by hand only: no progress to show */
    manual: boolean;
    /** The season the progress is about, for one that repeats */
    season: string | null;
}

/** The owner's own controls in the section */
export interface ShowcaseOwner {
    hidden: boolean;
    /** The ids they starred, in their order (empty: the rarest are featured) */
    chosen: string[];
    maxFeatured: number;
    updateUrl: string;
}

/** Where the viewer stands in an achievement that repeats: only ever their own */
export interface SeasonStanding {
    /** The season going on */
    season: string;
    heldNow: boolean;
    /** Earlier seasons they got it in, newest first */
    pastSeasons: string[];
    /** This season's progress; null for a guest, while progress is off or once held */
    progress: AchievementProgress | null;
}

/** An achievement of the `/logros` page */
export interface CatalogueAchievement extends AchievementSummary {
    /** Held at some point (in any season, for one that repeats) */
    held: boolean;
    rarity: number | null;
    standing: SeasonStanding | null;
}

/** The account settings' «Logros» tab (`/perfil?tab=achievements`) */
export interface AccountAchievements {
    /** Others hear of the owner's rare and challenge achievements (off until turned on) */
    announceRare: boolean;
    /** The owner appears in the leaderboard (on until left) */
    inLeaderboard: boolean;
    /** The owner hides their achievements: nothing is announced then */
    hidden: boolean;
    /** Announcing is offered only while announcements are on */
    offersAnnouncements: boolean;
    /** Whether there is a leaderboard now; its choice is offered anyway, to leave it beforehand */
    leaderboardOn: boolean;
    /** Percent of active people under which an achievement is rare */
    threshold: number;
    updateUrl: string;
    /** The «Avisos» tab, where the bell's categories are chosen */
    noticesUrl: string;
}

/** Where a collective challenge is */
export type ChallengeState = 'upcoming' | 'running' | 'completed' | 'ended' | 'closed';

/** Somebody who completed a challenge, as the viewer may see them: public name and profile only */
export interface ChallengeCompleter {
    name: string;
    url: string;
    avatarUrl: string | null;
}

/** A collective challenge: totals only, never which tables or events anybody took part in */
export interface Challenge {
    id: string;
    name: string;
    description: string;
    state: ChallengeState;
    /** False while it was never counted: its 0 means nothing yet */
    counted: boolean;
    progress: number;
    target: number;
    /** What the total counts, agreed with the target («partidas») */
    unit: string;
    /** Calendar days of the association (`AAAA-MM-DD`), both included */
    startsOn: string;
    endsOn: string;
    /** The days carry their year: they are not both of the current year */
    showsYear: boolean;
    /** Its last day is over */
    ended: boolean;
    membersOnly: boolean;
    reward: AchievementSummary;
    /** People who added to it */
    contributors: number;
    /** Some of whoever got the reward, whose profile the viewer may see (only on `/logros`); how many got it is never told */
    people: ChallengeCompleter[];
    /** The viewer's own contribution; null for a guest, and for whoever is not a member in a challenge of members */
    contribution: number | null;
}
