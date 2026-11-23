/**
 * The days of a challenge are calendar days of the association (`AAAA-MM-DD`), not instants: they
 * are drawn as they are, read in UTC, so no browser zone ever moves them a day.
 */
export function formatChallengeDay(day: string, locale: string, withYear = false): string {
    const date = new Date(`${day}T00:00:00Z`);

    if (Number.isNaN(date.getTime())) {
        return day;
    }

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'long',
        ...(withYear ? { year: 'numeric' } : {}),
        timeZone: 'UTC',
    }).format(date);
}

/** The share of the target reached, between 0 and 100 */
export function challengeShare(progress: number, target: number): number {
    if (target <= 0) {
        return 0;
    }

    return Math.max(0, Math.min(100, Math.round((progress / target) * 100)));
}
