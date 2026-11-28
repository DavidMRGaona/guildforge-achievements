import type { Component } from 'vue';
import {
    Award,
    BookOpen,
    CalendarCheck,
    Castle,
    Compass,
    Crown,
    Dices,
    Feather,
    Flag,
    Flame,
    Gem,
    HandHeart,
    Handshake,
    Heart,
    Hourglass,
    Map as MapIcon,
    MapPin,
    Medal,
    MessageSquare,
    Mountain,
    Puzzle,
    Rocket,
    ScrollText,
    Shield,
    Sparkles,
    Sprout,
    Star,
    Sword,
    Swords,
    Target,
    Ticket,
    Trophy,
    Users,
    Vote,
    WandSparkles,
    Zap,
} from '@lucide/vue';

/**
 * The icons an achievement may have: the core's closed list of badge icons
 * (`ProfileBadgeIcons::NAMES`), which a PHP test keeps equal to this map.
 */
export const badgeIcons: Record<string, Component> = {
    award: Award,
    'book-open': BookOpen,
    'calendar-check': CalendarCheck,
    castle: Castle,
    compass: Compass,
    crown: Crown,
    dices: Dices,
    feather: Feather,
    flag: Flag,
    flame: Flame,
    gem: Gem,
    'hand-heart': HandHeart,
    handshake: Handshake,
    heart: Heart,
    hourglass: Hourglass,
    map: MapIcon,
    'map-pin': MapPin,
    medal: Medal,
    'message-square': MessageSquare,
    mountain: Mountain,
    puzzle: Puzzle,
    rocket: Rocket,
    'scroll-text': ScrollText,
    shield: Shield,
    sparkles: Sparkles,
    sprout: Sprout,
    star: Star,
    sword: Sword,
    swords: Swords,
    target: Target,
    ticket: Ticket,
    trophy: Trophy,
    users: Users,
    vote: Vote,
    'wand-sparkles': WandSparkles,
    zap: Zap,
};

/** An icon the browser does not know (a newer server) is drawn as an award */
export function badgeIcon(icon: string): Component {
    return (Object.hasOwn(badgeIcons, icon) ? badgeIcons[icon] : undefined) ?? Award;
}

/**
 * The core's badge colours (`ProfileBadgeColour`): a 700 shade with white text in the light scheme,
 * a 300 shade with dark text in the dark one, at least 4.5:1 in both. Written out in full so
 * Tailwind finds every class.
 */
export const badgeColours: Record<string, string> = {
    slate: 'bg-(--color-slate-700) text-(--color-white) dark:bg-(--color-slate-300) dark:text-(--color-neutral-950)',
    red: 'bg-(--color-red-700) text-(--color-white) dark:bg-(--color-red-300) dark:text-(--color-neutral-950)',
    orange: 'bg-(--color-orange-700) text-(--color-white) dark:bg-(--color-orange-300) dark:text-(--color-neutral-950)',
    amber: 'bg-(--color-amber-700) text-(--color-white) dark:bg-(--color-amber-300) dark:text-(--color-neutral-950)',
    green: 'bg-(--color-green-700) text-(--color-white) dark:bg-(--color-green-300) dark:text-(--color-neutral-950)',
    teal: 'bg-(--color-teal-700) text-(--color-white) dark:bg-(--color-teal-300) dark:text-(--color-neutral-950)',
    sky: 'bg-(--color-sky-700) text-(--color-white) dark:bg-(--color-sky-300) dark:text-(--color-neutral-950)',
    blue: 'bg-(--color-blue-700) text-(--color-white) dark:bg-(--color-blue-300) dark:text-(--color-neutral-950)',
    violet: 'bg-(--color-violet-700) text-(--color-white) dark:bg-(--color-violet-300) dark:text-(--color-neutral-950)',
    pink: 'bg-(--color-pink-700) text-(--color-white) dark:bg-(--color-pink-300) dark:text-(--color-neutral-950)',
};

/** An unknown colour is drawn in slate */
export function badgeColour(colour: string): string {
    return (
        (Object.hasOwn(badgeColours, colour) ? badgeColours[colour] : undefined) ??
        badgeColours.slate ??
        ''
    );
}

/**
 * A rarity as the server sends it: a whole percent, refreshed once a night, with 0 for anything
 * under 1 % (never «0 %»). The exact share never reaches the browser.
 */
export function formatShare(share: number, locale: string): string {
    return share < 1
        ? `< ${new Intl.NumberFormat(locale).format(1)}`
        : new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(share);
}
