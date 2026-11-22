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
    /** ISO instant in the display zone */
    grantedAt: string | null;
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
