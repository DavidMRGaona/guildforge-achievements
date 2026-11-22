/** An achievement as the server sends it: never its rule, points or holders */
export interface AchievementSummary {
    id: string;
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

/** An achievement the profile's owner holds */
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
