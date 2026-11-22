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
