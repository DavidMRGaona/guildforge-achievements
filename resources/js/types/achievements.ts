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
}

/** An achievement the profile's owner holds (never masked: secret ones the viewer may not see are only counted) */
export interface EarnedAchievement extends AchievementSummary {
    /** ISO instant in the display zone */
    grantedAt: string | null;
    /** Share of active people holding it, in percent; null when not shown */
    rarity: number | null;
    featured: boolean;
}

/** What the owner does not hold yet (only ever sent to them) */
export interface LockedAchievement extends AchievementSummary {
    progress: { value: number; threshold: number } | null;
    /** Granted by hand only: no progress to show */
    manual: boolean;
}

/** The owner's own controls in the section */
export interface ShowcaseOwner {
    hidden: boolean;
    /** The ids they starred, in their order (empty: the rarest are featured) */
    chosen: string[];
    maxFeatured: number;
    updateUrl: string;
}

/** An achievement of the `/logros` page */
export interface CatalogueAchievement extends AchievementSummary {
    held: boolean;
    rarity: number | null;
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
    progress: number;
    target: number;
    /** What the total counts, agreed with the target («partidas») */
    unit: string;
    /** Calendar days of the association (`AAAA-MM-DD`), both included */
    startsOn: string;
    endsOn: string;
    membersOnly: boolean;
    reward: AchievementSummary;
    /** People who added to it */
    contributors: number;
    /** People who got the reward */
    completers: number;
    /** Those of them whose profile the viewer may see (only on `/logros`) */
    people: ChallengeCompleter[];
    /** The viewer's own contribution; null for a guest */
    contribution: number | null;
}
