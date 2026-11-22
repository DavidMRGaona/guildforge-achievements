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
        held: false,
        rarity: 50,
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
                    achievement({ id: 'c', name: 'Común', rarity: 0 }),
                    achievement({ id: 'r', name: 'Rarísimo', rarity: 0.04 }),
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
        expect(items[3]?.text()).toContain('Lo tiene el < 0,1 % de la gente activa');
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

    it('says so when there is none', () => {
        const wrapper = mount(Index, { props: { achievements: [] }, global: { stubs } });

        expect(wrapper.text()).toContain('Todavía no hay logros.');
    });
});
