import { describe, expect, it } from 'vitest';

/*
 * The module's stylesheet is imported into the `module-utilities` cascade layer, below the
 * core's `utilities`, so the core wins any tie (resources/css/app.css in the core). A variant
 * of the module therefore loses to any plain class the core happens to ship for the same
 * property: with `text-primary-800 dark:text-primary-100` the core's `.text-primary-800` beat
 * the module's dark variant and the text stayed dark (#42); with `text-base-muted
 * hover:text-…` the core's `.text-base-muted` would beat the hover. Whether it breaks depends
 * on what the core uses at the time, so the rule holds for every class list.
 *
 * A class list that changes a colour with a variant (`dark:`, `hover:`, `focus:`, `sm:`…)
 * writes the plain colour of that same property through the variables
 * (`text-(--color-primary-800) dark:text-(--color-primary-100)`, `text-(--color-text-muted)
 * hover:text-(--color-primary-600)`): the core never ships those classes, so the plain colour
 * and its variants all live in the module's layer and the variant wins as it should.
 */

/** Every component and script of the module, as text */
const SOURCES = import.meta.glob<string>('../../resources/js/**/*.{vue,ts}', {
    query: '?raw',
    import: 'default',
    eager: true,
});

const COLOUR_PROPERTY =
    'bg|text|border|ring|fill|stroke|divide|outline|from|to|via|decoration|placeholder|caret|accent';

/** A utility, with its variants, that sets one of those properties */
const UTILITY = new RegExp(
    `(?<![\\w(\\-\\[])((?:[a-z0-9-]+:)*)(${COLOUR_PROPERTY})-([^\\s'"\`]+)`,
    'g'
);

/** Values of those prefixes that are not colours: sizes, widths, alignment, styles, keywords */
const NOT_A_COLOUR =
    /^(?:xs|sm|base|lg|xl|\d?xl|left|right|center|justify|start|end|ellipsis|clip|wrap|nowrap|balance|pretty|\d+(?:\.\d+)?|[xytrbl]|[xytrbl]-\d+|[xytrbl]-0|s|e|s-\d+|e-\d+|solid|dashed|dotted|double|none|hidden|collapse|separate|inset|offset-\d+|offset-[a-z]+|current|transparent|inherit|cover|contain|auto|fixed|local|scroll|repeat|no-repeat|clip-[a-z]+|origin-[a-z]+|gradient-to-[a-z]+|linear-[a-z0-9-]+|underline|overline|line-through|no-underline|\d+%|\[\d[^\]]*\])$/;

interface ColourUtility {
    token: string;
    property: string;
    hasVariant: boolean;
    throughVariable: boolean;
}

function colourUtilities(literal: string): ColourUtility[] {
    return [...literal.matchAll(UTILITY)]
        .filter((match) => !NOT_A_COLOUR.test(match[3] ?? ''))
        .map((match) => ({
            token: match[0],
            property: match[2] ?? '',
            hasVariant: (match[1] ?? '') !== '',
            throughVariable: /^(?:\(--|\[var\(--)/.test(match[3] ?? ''),
        }));
}

/** Every quoted string of a source: class attributes, bindings and class maps alike */
const QUOTED = /"[^"\n]*"|'[^'\n]*'|`[^`]*`/g;

/**
 * The plain colours of a class list that a variant of the same property must beat: only those
 * can lose to a class the core ships, so they are the ones written through the variables
 */
function coloursTheCoreCouldOverride(literal: string): string[] {
    const colours = colourUtilities(literal);
    const varied = new Set(
        colours.filter((colour) => colour.hasVariant).map((colour) => colour.property)
    );

    return colours
        .filter(
            (colour) => !colour.hasVariant && varied.has(colour.property) && !colour.throughVariable
        )
        .map((colour) => colour.token);
}

function offendersIn([path, source]: [string, string]): string[] {
    return (source.match(QUOTED) ?? []).flatMap((literal) =>
        coloursTheCoreCouldOverride(literal).map((token) => `${path}: ${token}`)
    );
}

describe('colours set together with a variant', () => {
    it('reads the module components and scripts', () => {
        const paths = Object.keys(SOURCES);

        expect(paths.some((path) => path.endsWith('.vue'))).toBe(true);
        expect(paths.some((path) => path.endsWith('.ts'))).toBe(true);
    });

    it('are all written through the variables, so the core never overrides them', () => {
        expect(Object.entries(SOURCES).flatMap(offendersIn)).toEqual([]);
    });

    it('catches palette colours next to a dark variant (#42)', () => {
        expect(
            coloursTheCoreCouldOverride(
                "'rounded-full bg-primary-100 text-primary-800 dark:bg-primary-900 dark:text-primary-100'"
            )
        ).toEqual(['bg-primary-100', 'text-primary-800']);
    });

    it('catches a semantic colour under a hover written through a variable', () => {
        expect(
            coloursTheCoreCouldOverride(
                "'text-base-muted hover:text-(--color-primary-600) text-sm'"
            )
        ).toEqual(['text-base-muted']);
    });

    it('lets the variable form, sizes and colours without a variant through', () => {
        expect(
            coloursTheCoreCouldOverride(
                "'bg-(--color-primary-100) text-(--color-primary-800) dark:bg-(--color-primary-900) dark:text-(--color-primary-100)'"
            )
        ).toEqual([]);
        expect(
            coloursTheCoreCouldOverride(
                "'text-primary-800 text-sm border-2 sm:text-lg md:border-0'"
            )
        ).toEqual([]);
        expect(
            coloursTheCoreCouldOverride("'text-base-muted focus:outline-none focus-visible:ring-2'")
        ).toEqual([]);
        expect(
            coloursTheCoreCouldOverride("'text-base-primary focus-visible:ring-primary-500'")
        ).toEqual([]);
        expect(
            coloursTheCoreCouldOverride(
                "'text-[var(--color-primary-link)] hover:underline dark:text-(--color-primary-400)'"
            )
        ).toEqual([]);
    });
});
