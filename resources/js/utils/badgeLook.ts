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
    slate: 'bg-slate-700 text-white dark:bg-slate-300 dark:text-neutral-950',
    red: 'bg-red-700 text-white dark:bg-red-300 dark:text-neutral-950',
    orange: 'bg-orange-700 text-white dark:bg-orange-300 dark:text-neutral-950',
    amber: 'bg-amber-700 text-white dark:bg-amber-300 dark:text-neutral-950',
    green: 'bg-green-700 text-white dark:bg-green-300 dark:text-neutral-950',
    teal: 'bg-teal-700 text-white dark:bg-teal-300 dark:text-neutral-950',
    sky: 'bg-sky-700 text-white dark:bg-sky-300 dark:text-neutral-950',
    blue: 'bg-blue-700 text-white dark:bg-blue-300 dark:text-neutral-950',
    violet: 'bg-violet-700 text-white dark:bg-violet-300 dark:text-neutral-950',
    pink: 'bg-pink-700 text-white dark:bg-pink-300 dark:text-neutral-950',
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
 * A share in percent as people read it: one decimal under 10 % (so a rare one never reads «0 %»),
 * whole numbers above, and «< 0,1» below a tenth (never «0»).
 */
export function formatShare(share: number, locale: string): string {
    const format = (value: number): string =>
        new Intl.NumberFormat(locale, {
            maximumFractionDigits: value < 10 ? 1 : 0,
            minimumFractionDigits: 0,
        }).format(value);

    return share < 0.1 ? `< ${format(0.1)}` : format(share);
}
