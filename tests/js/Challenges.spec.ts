import { describe, expect, it } from 'vitest';
import { computed } from 'vue';
import { mount } from '@vue/test-utils';
import { SITE_BLOCK_PLACEMENT } from '@/composables/useSiteBlockPlacement';
import ChallengeBlock from '../../resources/js/components/ChallengeBlock.vue';
import ChallengeCard from '../../resources/js/components/ChallengeCard.vue';
import Index from '../../resources/js/pages/Achievements/Index.vue';
import type { Challenge } from '../../resources/js/types/achievements';
import { challengeShare, formatChallengeDay } from '../../resources/js/utils/challengeDays';

function challenge(overrides: Partial<Challenge> = {}): Challenge {
    return {
        id: 'c1',
        name: '40 partidas en diciembre',
        description: 'Entre todos',
        state: 'running',
        counted: true,
        progress: 12,
        target: 40,
        unit: 'partidas',
        startsOn: '2026-12-01',
        endsOn: '2026-12-31',
        showsYear: false,
        ended: false,
        membersOnly: false,
        reward: {
            id: 'r1',
            name: 'Diciembre en la mesa',
            description: 'Contribuyó al reto',
            icon: 'trophy',
            colour: 'amber',
            imageUrl: null,
            masked: false,
            repeats: false,
        },
        contributors: 5,
        people: [],
        contribution: null,
        ...overrides,
    };
}

describe('challenge days', () => {
    it('draws the calendar day as it is, whatever the browser zone', () => {
        expect(formatChallengeDay('2026-12-01', 'es')).toBe('1 de diciembre');
        expect(formatChallengeDay('2027-01-15', 'es', true)).toBe('15 de enero de 2027');
        expect(formatChallengeDay('not a day', 'es')).toBe('not a day');
    });

    it('caps the share of the target between 0 and 100', () => {
        expect(challengeShare(12, 40)).toBe(30);
        expect(challengeShare(50, 40)).toBe(100);
        expect(challengeShare(3, 0)).toBe(0);
    });
});

describe('ChallengeCard', () => {
    it('shows the totals, the days and the reward', () => {
        const wrapper = mount(ChallengeCard, { props: { challenge: challenge() } });

        expect(wrapper.text()).toContain('40 partidas en diciembre');
        expect(wrapper.text()).toContain('Del 1 de diciembre al 31 de diciembre');
        expect(wrapper.get('[data-test="challenge-progress"]').text()).toBe('12 de 40 partidas');
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuetext')).toBe(
            '12 de 40 partidas'
        );
        expect(wrapper.text()).toContain('5 personas han contribuido');
        expect(wrapper.text()).toContain('Diciembre en la mesa');
        expect(wrapper.find('[data-test="challenge-contribution"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="challenge-completers"]').exists()).toBe(false);
    });

    it('says a challenge never counted is not counted yet, without a 0', () => {
        const wrapper = mount(ChallengeCard, {
            props: { challenge: challenge({ counted: false, progress: 0 }) },
        });

        expect(wrapper.get('[data-test="challenge-not-counted"]').text()).toBe(
            'Aún no se ha contado. Objetivo: 40 partidas.'
        );
        expect(wrapper.find('[role="progressbar"]').exists()).toBe(false);
    });

    it('gives the year of each day when it crosses New Year', () => {
        const wrapper = mount(ChallengeCard, {
            props: {
                challenge: challenge({
                    startsOn: '2026-12-15',
                    endsOn: '2027-01-15',
                    showsYear: true,
                }),
            },
        });

        expect(wrapper.text()).toContain('Del 15 de diciembre de 2026 al 15 de enero de 2027');
    });

    it('tells a signed-in person their own part', () => {
        const mine = mount(ChallengeCard, { props: { challenge: challenge({ contribution: 3 }) } });
        const none = mount(ChallengeCard, { props: { challenge: challenge({ contribution: 0 }) } });

        expect(mine.get('[data-test="challenge-contribution"]').text()).toBe(
            'Has participado 3 veces'
        );
        expect(none.get('[data-test="challenge-contribution"]').text()).toBe(
            'Todavía no has contribuido'
        );
    });

    it('names the visible people who completed it and counts the rest', () => {
        const wrapper = mount(ChallengeCard, {
            props: {
                challenge: challenge({
                    state: 'completed',
                    progress: 41,
                    people: [
                        { name: 'Ana', url: 'https://example.test/miembros/ana', avatarUrl: null },
                        {
                            name: 'Pablo',
                            url: 'https://example.test/miembros/pablo',
                            avatarUrl: null,
                        },
                    ],
                }),
            },
        });

        const completers = wrapper.get('[data-test="challenge-completers"]');
        expect(completers.text()).toContain('Algunas de las personas que lo han conseguido');
        expect(completers.findAll('a').map((link) => link.text())).toEqual(['AAna', 'PPablo']);
        // How many got it is never told next to how many added
        expect(wrapper.text()).not.toContain('tienen la recompensa');
        expect(wrapper.text()).toContain('Conseguido');
    });
});

describe('ChallengeBlock', () => {
    it('is light outside the footer and links to the challenges', () => {
        const wrapper = mount(ChallengeBlock, {
            props: { challenge: challenge(), url: '/logros' },
            global: { provide: { [SITE_BLOCK_PLACEMENT as symbol]: computed(() => 'popup') } },
        });

        expect(wrapper.text()).toContain('Hasta el 31 de diciembre');
        expect(wrapper.get('a').attributes('href')).toBe('/logros');
        expect(wrapper.get('a').classes()).not.toContain('text-white');
    });

    it('says it is over once its last day has passed', () => {
        const wrapper = mount(ChallengeBlock, {
            props: { challenge: challenge({ state: 'ended', ended: true }), url: '/logros' },
        });

        expect(wrapper.text()).toContain('Terminado el 31 de diciembre');
    });

    it('is dark in the footer and says when an upcoming one starts', () => {
        const wrapper = mount(ChallengeBlock, {
            props: { challenge: challenge({ state: 'upcoming' }), url: '/logros' },
        });

        expect(wrapper.text()).toContain('Empieza el 1 de diciembre');
        expect(wrapper.get('a').classes()).toContain('text-white');
    });
});

describe('Achievements/Index with challenges', () => {
    it('shows the challenges before the catalogue', () => {
        const wrapper = mount(Index, {
            props: { achievements: [], challenges: [challenge()] },
            global: { stubs: { HelpLink: true } },
        });

        expect(wrapper.get('[data-test="challenges"]').text()).toContain('Retos de la asociación');
        expect(wrapper.findAll('[data-test="challenge"]')).toHaveLength(1);
    });

    it('has no challenges section without any', () => {
        const wrapper = mount(Index, {
            props: { achievements: [] },
            global: { stubs: { HelpLink: true } },
        });

        expect(wrapper.find('[data-test="challenges"]').exists()).toBe(false);
    });
});
