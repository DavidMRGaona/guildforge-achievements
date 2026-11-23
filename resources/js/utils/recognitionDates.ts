/**
 * Recognitions show only the month and year (`AAAA-MM`): the day would point at the game. Read in
 * UTC, so no browser zone moves it into another month.
 */
export function formatRecognitionMonth(month: string, locale: string): string {
    const [year, monthNumber] = month.split('-').map(Number);

    if (!year || !monthNumber) {
        return month;
    }

    return new Date(Date.UTC(year, monthNumber - 1, 15)).toLocaleDateString(locale, {
        timeZone: 'UTC',
        month: 'long',
        year: 'numeric',
    });
}
