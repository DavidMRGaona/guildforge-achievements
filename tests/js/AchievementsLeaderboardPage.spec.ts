import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Leaderboard from '../../resources/js/pages/Achievements/Leaderboard.vue';
import type { LeaderboardEntry } from '../../resources/js/types/achievements';

function entry(overrides: Partial<LeaderboardEntry> = {}): LeaderboardEntry {
    return {
        rank: 1,
        points: 120,
        publicName: 'Ana García',
        profileUrl: '/miembros/ana',
        avatarUrl: null,
        level: { number: 3, name: 'Habitual' },
        isViewer: false,
        ...overrides,
    };
}

const base = {
    pagination: { page: 1, lastPage: 1, total: 2, perPage: 25 },
    currentSeason: 2026,
    catalogueUrl: '/logros',
    leaderboardUrl: '/logros/clasificacion',
};

const stubs = { HelpLink: true };

describe('Achievements/Leaderboard', () => {
    it('lists each person by public name with rank, level and points', () => {
        const wrapper = mount(Leaderboard, {
            props: {
                ...base,
                entries: [
                    entry(),
                    entry({
                        rank: 2,
                        points: 1,
                        publicName: 'Luis',
                        profileUrl: '/miembros/luis',
                        level: null,
                    }),
                ],
                viewer: { entry: null, status: 'no_points' },
                showsLevels: true,
            },
            global: { stubs },
        });

        const rows = wrapper.findAll('[data-test="entries"] li');
        expect(rows).toHaveLength(2);
        expect(rows[0]?.text()).toContain('Ana García');
        expect(rows[0]?.text()).toContain('Nivel 3 · Habitual');
        expect(rows[0]?.text()).toContain('120 puntos');
        expect(rows[0]?.find('a').attributes('href')).toBe('/miembros/ana');
        expect(rows[1]?.text()).toContain('1 punto');
        expect(wrapper.find('[data-test="viewer"]').text()).toContain('Todavía no tienes puntos');
    });

    it('marks the viewer row and says their rank', () => {
        const mine = entry({ rank: 4, isViewer: true, publicName: 'Vera' });
        const wrapper = mount(Leaderboard, {
            props: { ...base, entries: [mine], viewer: { entry: mine, status: 'listed' } },
            global: { stubs },
        });

        expect(wrapper.find('[data-test="viewer"]').text()).toContain(
            'Vas en el puesto 4, con 120 puntos.'
        );
        expect(wrapper.find('[aria-current="true"]').text()).toContain('Vera');
    });

    it('offers all time and the seasons while seasons are on', () => {
        const wrapper = mount(Leaderboard, {
            props: {
                ...base,
                entries: [entry()],
                viewer: { entry: null, status: 'opted_out' },
                season: 2026,
                seasons: [2026, 2025],
            },
            global: { stubs },
        });

        expect(wrapper.find('[data-test="all-time"]').attributes('href')).toBe(
            '/logros/clasificacion'
        );
        expect(wrapper.find('[data-test="current-season"]').attributes('href')).toBe(
            '/logros/clasificacion?temporada=2026'
        );
        expect(wrapper.find('[data-test="current-season"]').attributes('aria-current')).toBe(
            'page'
        );
        expect(wrapper.findAll('[data-test="season-select"] option')).toHaveLength(3);
        expect(wrapper.find('[data-test="viewer"]').text()).toContain('has elegido no salir');
    });

    it('shows nobody when it could not be worked out', () => {
        const wrapper = mount(Leaderboard, {
            props: {
                ...base,
                entries: [],
                available: false,
                viewer: { entry: null, status: null },
            },
            global: { stubs },
        });

        expect(wrapper.find('[data-test="unavailable"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="entries"]').exists()).toBe(false);
        // Nothing said about the viewer: it could be wrong
        expect(wrapper.find('[data-test="viewer"]').exists()).toBe(false);
    });

    it('pages forward keeping the season', () => {
        const wrapper = mount(Leaderboard, {
            props: {
                ...base,
                entries: [entry()],
                pagination: { page: 1, lastPage: 3, total: 60, perPage: 25 },
                viewer: { entry: null, status: 'no_points' },
                season: 2025,
                seasons: [2026, 2025],
            },
            global: { stubs },
        });

        expect(wrapper.find('a[rel="next"]').attributes('href')).toBe(
            '/logros/clasificacion?temporada=2025&page=2'
        );
        expect(wrapper.find('a[rel="prev"]').exists()).toBe(false);
    });
});
