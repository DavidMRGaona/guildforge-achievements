/** An achievement as the server sends it: never its rule or holders */
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
    /** What it gives; absent while points are off */
    points?: number;
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
    /** «Aparecer en la clasificación»; absent while there is no leaderboard */
    leaderboard?: boolean;
}

/** A level of the scale */
export interface AchievementLevel {
    number: number;
    name: string;
}

/** The owner's totals as the viewer may see them (only while points are on): secrets masked to the viewer are not added */
export interface PointsSummary {
    total: number;
    /** The current season's points; null while seasons are off */
    season: number | null;
    seasonYear: number;
    level: (AchievementLevel & { threshold: number }) | null;
    /** Only for the owner: the level they climb to next */
    nextLevel: (AchievementLevel & { threshold: number }) | null;
    leaderboardUrl: string | null;
}

/** A row of the leaderboard: somebody the viewer may see, by their public name */
export interface LeaderboardEntry {
    rank: number;
    points: number;
    publicName: string;
    profileUrl: string;
    avatarUrl: string | null;
    level: AchievementLevel | null;
    isViewer: boolean;
}

export type LeaderboardViewerStatus =
    'listed' | 'no_points' | 'hidden' | 'opted_out' | 'no_public_name' | 'pending';

export interface LeaderboardPagination {
    page: number;
    lastPage: number;
    total: number;
    perPage: number;
}

/** An achievement of the `/logros` page */
export interface CatalogueAchievement extends AchievementSummary {
    held: boolean;
    rarity: number | null;
    /** What it gives; null while points are off or for a masked secret */
    points?: number | null;
}

/** The account settings' «Logros» tab (`/perfil?tab=achievements`) */
export interface AccountAchievements {
    /** Others hear of the owner's rare and challenge achievements (off until turned on) */
    announceRare: boolean;
    /** The owner appears in the leaderboard (on until left) */
    inLeaderboard: boolean;
    /** The owner hides their achievements: nothing is announced then */
    hidden: boolean;
    /** Each choice is offered only while its mechanic is on */
    offersAnnouncements: boolean;
    offersLeaderboard: boolean;
    /** Percent of active people under which an achievement is rare */
    threshold: number;
    updateUrl: string;
    /** The «Avisos» tab, where the bell's categories are chosen */
    noticesUrl: string;
}
