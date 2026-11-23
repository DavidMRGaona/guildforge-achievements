import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '../../resources/js/pages/Achievements/Index.vue';
import type { CatalogueAchievement } from '../../resources/js/types/achievements';

function achievement(overrides: Partial<CatalogueAchievement> = {}): CatalogueAchievement {
    return {
        id: 'a1',
        name: 'Primera partida',
        description: 'Jugó su primera partida.',
        icon: 'dices',
        colour: 'amber',
        imageUrl: null,
        masked: false,
        repeats: false,
        held: false,
        rarity: 50,
        heldByNobody: false,
        standing: null,
        ...overrides,
    };
}

const stubs = { HelpLink: true };

describe('Achievements/Index', () => {
    it('lists every achievement with its rarity', () => {
        const wrapper = mount(Index, {
            props: {
                achievements: [
                    achievement(),
                    achievement({ id: null, name: 'Logro secreto', masked: true, rarity: null }),
                    achievement({ id: 'c', name: 'Común', rarity: null, heldByNobody: true }),
                    achievement({ id: 'r', name: 'Rarísimo', rarity: 0 }),
                ],
                showsRarity: true,
            },
            global: { stubs },
        });

        const items = wrapper.findAll('[data-test="catalogue"] li');
        expect(items).toHaveLength(4);
        expect(items[0]?.text()).toContain('Lo tiene el 50 % de la gente activa');
        expect(items[1]?.text()).toContain('Logro secreto');
        expect(items[1]?.text()).not.toContain('gente activa');
        expect(items[2]?.text()).toContain('Ninguna persona activa lo tiene todavía');
        expect(items[3]?.text()).toContain('Lo tiene el < 1 % de la gente activa');
    });

    it('marks what the signed-in person holds', () => {
        const wrapper = mount(Index, {
            props: {
                achievements: [achievement({ held: true }), achievement({ id: 'b' })],
                signedIn: true,
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Tienes 1 de 2.');
        expect(wrapper.text()).toContain('Lo tienes');
    });

    it('tells the viewer where they stand this season in one that repeats', () => {
        const wrapper = mount(Index, {
            props: {
                achievements: [
                    achievement({
                        repeats: true,
                        held: true,
                        standing: {
                            season: '2027',
                            heldNow: false,
                            pastSeasons: ['2026', '2025'],
                            progress: { value: 3, threshold: 10, inMonths: false },
                        },
                    }),
                    achievement({
                        id: 'b',
                        repeats: true,
                        held: true,
                        standing: {
                            season: '2027',
                            heldNow: true,
                            pastSeasons: [],
                            progress: null,
                        },
                    }),
                ],
                signedIn: true,
                season: '2027',
            },
            global: { stubs },
        });

        const items = wrapper.findAll('[data-test="catalogue"] li');
        expect(wrapper.find('[data-test="season-explained"]').text()).toContain('temporada 2027');
        expect(wrapper.text()).toContain('Tienes 1 de 2.');
        expect(items[0]?.find('[data-test="repeats"]').text()).toBe('Cada temporada');
        expect(items[0]?.find('[data-test="standing"]').text()).toContain(
            'Esta temporada: 3 de 10'
        );
        expect(items[0]?.find('[data-test="standing"]').text()).toContain('También en 2025 y 2026');
        expect(items[0]?.text()).not.toContain('Lo tienes');
        expect(items[1]?.text()).toContain('Lo tienes esta temporada');
    });

    it('says so when there is none', () => {
        const wrapper = mount(Index, { props: { achievements: [] }, global: { stubs } });

        expect(wrapper.text()).toContain('Todavía no hay logros.');
    });
});
