import { describe, expect, it } from 'vitest';

/*
 * The module's stylesheet is imported into the `module-utilities` cascade layer, below the
 * core's `utilities`, so the core wins any tie (resources/css/app.css in the core). A colour
 * written as `text-primary-800 dark:text-primary-100` therefore breaks in dark mode whenever
 * the core happens to ship `.text-primary-800` and not `.dark\:text-primary-100`: the core's
 * plain class beats the module's variant and the text stays dark (#42). Whether it breaks
 * depends on what the core uses at the time, so the rule is kept for every class list.
 *
 * A class list that changes a colour in dark mode writes its colours through the palette's
 * variables (`text-(--color-primary-800) dark:text-(--color-primary-100)`): the core never
 * ships those classes, so the light colour and its dark variant both live in the module's
 * layer and the variant wins as it should.
 */

/** Every component and script of the module, as text */
const SOURCES = import.meta.glob<string>('../../resources/js/**/*.{vue,ts}', {
    query: '?raw',
    import: 'default',
    eager: true,
});

const PALETTE =
    'primary|accent|neutral|red|green|blue|amber|yellow|orange|stone|gray|slate|zinc|emerald|teal|sky|indigo|violet|purple|pink|rose|lime|cyan|fuchsia';
const PROPERTY =
    'bg|text|border|ring|fill|stroke|divide|outline|from|to|via|decoration|placeholder';

/** A colour from the palette written as a plain utility, with or without variants */
const PLAIN_COLOUR = new RegExp(
    `(?<![\\w(\\-\\[])(?:[a-z-]+:)*(?:${PROPERTY})-(?:(?:${PALETTE})-\\d{2,3}|white|black)(?:/\\d+)?(?![\\w\\-\\]])`,
    'g'
);

/** A dark-mode variant that sets a colour */
const DARK_COLOUR = new RegExp(`dark:(?:[a-z-]+:)*(?:${PROPERTY})-`);

/** Every quoted string of a source: class attributes, bindings and class maps alike */
const QUOTED = /"[^"\n]*"|'[^'\n]*'|`[^`]*`/g;

function plainColoursNextToDarkVariants([path, source]: [string, string]): string[] {
    const found: string[] = [];

    for (const literal of source.match(QUOTED) ?? []) {
        if (!DARK_COLOUR.test(literal)) {
            continue;
        }

        for (const plain of literal.match(PLAIN_COLOUR) ?? []) {
            found.push(`${path}: ${plain}`);
        }
    }

    return found;
}

describe('colours that change in dark mode', () => {
    it('reads the module sources', () => {
        expect(Object.keys(SOURCES).length).toBeGreaterThan(10);
    });

    it('are written through the palette variables, so the core never overrides them', () => {
        const offenders = Object.entries(SOURCES).flatMap(plainColoursNextToDarkVariants);

        expect(offenders).toEqual([]);
    });

    it('catches a plain colour next to a dark variant', () => {
        const literal =
            "'rounded-full bg-primary-100 text-primary-800 dark:bg-primary-900 dark:text-primary-100'";

        expect(DARK_COLOUR.test(literal)).toBe(true);
        expect(literal.match(PLAIN_COLOUR)).toEqual([
            'bg-primary-100',
            'text-primary-800',
            'dark:bg-primary-900',
            'dark:text-primary-100',
        ]);
    });

    it('lets the variable form and colours without a dark variant through', () => {
        const variables =
            "'bg-(--color-primary-100) text-(--color-primary-800) dark:bg-(--color-primary-900) dark:text-(--color-primary-100)'";

        expect(variables.match(PLAIN_COLOUR)).toBeNull();
        expect(DARK_COLOUR.test("'text-primary-800 hover:underline'")).toBe(false);
    });
});
