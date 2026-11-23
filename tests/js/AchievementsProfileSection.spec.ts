import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import AchievementsProfileSection from '../../resources/js/components/profileSections/AchievementsProfileSection.vue';
import type {
    EarnedAchievement,
    LockedAchievement,
    ShowcaseOwner,
} from '../../resources/js/types/achievements';

function earned(overrides: Partial<EarnedAchievement> = {}): EarnedAchievement {
    return {
        id: 'a1',
        name: 'Primera partida',
        description: 'Jugó su primera partida.',
        icon: 'dices',
        colour: 'amber',
        imageUrl: null,
        masked: false,
        grantedAt: '2026-10-01T20:00:00+02:00',
        grantedMonth: '2026-10',
        rarity: 4,
        featured: false,
        repeats: false,
        seasons: [],
        ...overrides,
    };
}

function locked(overrides: Partial<LockedAchievement> = {}): LockedAchievement {
    return {
        id: 'b1',
        name: 'Diez partidas',
        description: 'Jugó diez partidas.',
        icon: 'trophy',
        colour: 'teal',
        imageUrl: null,
        masked: false,
        repeats: false,
        progress: { value: 7, threshold: 10, inMonths: false },
        manual: false,
        season: null,
        ...overrides,
    };
}

const owner: ShowcaseOwner = {
    hidden: false,
    chosen: [],
    maxFeatured: 3,
    updateUrl: '/logros/miembros/ana',
};

describe('AchievementsProfileSection', () => {
    beforeEach(() => {
        vi.mocked(router.put).mockReset();
    });

    it('shows what was earned with its date and rarity, and no owner controls to others', () => {
        const wrapper = mount(AchievementsProfileSection, {
            props: { earned: [earned()], showsRarity: true, catalogueUrl: '/logros' },
        });

        expect(wrapper.text()).toContain('Primera partida');
        expect(wrapper.text()).toContain('Lo tiene el 4 % de la gente activa');
        expect(wrapper.find('time').attributes('datetime')).toBe('2026-10-01T20:00:00+02:00');
        expect(wrapper.find('[data-test="feature"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="visibility-switch"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/logros"]').text()).toBe('Ver todos los logros');
    });

    it('counts the secrets the viewer may not see, without listing them', () => {
        const withOthers = mount(AchievementsProfileSection, {
            props: { earned: [earned()], secretCount: 2, catalogueUrl: '/logros' },
        });
        const onlySecrets = mount(AchievementsProfileSection, {
            props: { earned: [], secretCount: 1, catalogueUrl: '/logros' },
        });

        expect(withOthers.find('[data-test="secret-count"]').text()).toBe('Y 2 logros secretos.');
        expect(withOthers.findAll('[data-test="earned"] li')).toHaveLength(1);
        expect(onlySecrets.find('[data-test="secret-count"]').text()).toBe('1 logro secreto.');
    });

    it('shows anybody but the owner the month alone', () => {
        const wrapper = mount(AchievementsProfileSection, {
            props: { earned: [earned({ grantedAt: null })], catalogueUrl: '/logros' },
        });

        const time = wrapper.find('time');
        expect(time.attributes('datetime')).toBe('2026-10');
        expect(time.text()).toBe('octubre de 2026');
    });

    it('hides the rarity when it is off', () => {
        const wrapper = mount(AchievementsProfileSection, {
            props: { earned: [earned()], showsRarity: false, catalogueUrl: '/logros' },
        });

        expect(wrapper.text()).not.toContain('gente activa');
    });

    it('shows the owner the progress of what is left', () => {
        const wrapper = mount(AchievementsProfileSection, {
            props: {
                earned: [],
                locked: [
                    locked(),
                    locked({ id: 'b2', name: 'Gran ayuda', progress: null, manual: true }),
                ],
                catalogueUrl: '/logros',
                owner,
            },
        });

        const bar = wrapper.find('[role="progressbar"]');
        expect(bar.attributes('aria-valuenow')).toBe('7');
        expect(bar.attributes('aria-valuemax')).toBe('10');
        expect(bar.attributes('aria-valuetext')).toBe('7 de 10');
        expect(wrapper.text()).toContain('Te faltan (2)');
        expect(wrapper.text()).toContain('Se concede a mano.');
    });

    it('lists the seasons an achievement was got in, oldest first', () => {
        const wrapper = mount(AchievementsProfileSection, {
            props: {
                earned: [earned({ repeats: true, seasons: ['2027', '2026', '2025'] })],
                catalogueUrl: '/logros',
            },
        });

        expect(wrapper.find('[data-test="seasons"]').text()).toBe(
            'Conseguido en 2025, 2026 y 2027'
        );
    });

    it('shows the owner this season and a streak in months', () => {
        const wrapper = mount(AchievementsProfileSection, {
            props: {
                earned: [],
                locked: [
                    locked({
                        repeats: true,
                        season: '2027',
                        progress: { value: 2, threshold: 6, inMonths: true },
                    }),
                ],
                catalogueUrl: '/logros',
                owner,
            },
        });

        expect(wrapper.find('[data-test="season"]').text()).toBe('Temporada 2027');
        expect(wrapper.find('[role="progressbar"]').attributes('aria-valuetext')).toBe(
            '2 de 6 meses seguidos'
        );
    });

    it('stars an achievement and saves it with the visibility', async () => {
        const wrapper = mount(AchievementsProfileSection, {
            props: { earned: [earned()], catalogueUrl: '/logros', owner },
        });

        await wrapper.find('[data-test="feature"]').trigger('click');

        expect(router.put).toHaveBeenCalledWith(
            '/logros/miembros/ana',
            { hidden: false, featured: ['a1'] },
            expect.objectContaining({ preserveScroll: true })
        );
        expect(wrapper.find('[data-test="feature"]').attributes('aria-pressed')).toBe('true');
    });

    it('never offers a fourth star', () => {
        const wrapper = mount(AchievementsProfileSection, {
            props: {
                earned: ['a', 'b', 'c', 'd'].map((id) => earned({ id, name: id })),
                catalogueUrl: '/logros',
                owner: { ...owner, chosen: ['a', 'b', 'c'] },
            },
        });

        const stars = wrapper.findAll('[data-test="feature"]');
        expect(stars[3]?.attributes('disabled')).toBeDefined();
        expect(stars[0]?.attributes('disabled')).toBeUndefined();
    });

    it('hides the achievements from others with the switch', async () => {
        const wrapper = mount(AchievementsProfileSection, {
            props: {
                earned: [earned()],
                catalogueUrl: '/logros',
                owner: { ...owner, chosen: ['a1'] },
            },
        });

        const toggle = wrapper.find('[data-test="visibility-switch"]');
        expect(toggle.attributes('aria-checked')).toBe('true');

        await toggle.trigger('click');

        expect(router.put).toHaveBeenCalledWith(
            '/logros/miembros/ana',
            { hidden: true, featured: ['a1'] },
            expect.anything()
        );
        expect(toggle.attributes('aria-checked')).toBe('false');
    });

    it('puts the choice back and says why when the server refuses it', async () => {
        vi.mocked(router.put).mockImplementation((_url, _data, options) => {
            options?.onError?.({ featured: 'Solo puedes destacar logros que tengas.' });
            options?.onFinish?.({} as never);
        });
        const wrapper = mount(AchievementsProfileSection, {
            props: { earned: [earned()], catalogueUrl: '/logros', owner },
        });

        await wrapper.find('[data-test="feature"]').trigger('click');

        expect(wrapper.find('[role="alert"]').text()).toBe(
            'Solo puedes destacar logros que tengas.'
        );
        expect(wrapper.find('[data-test="feature"]').attributes('aria-pressed')).toBe('false');
    });

    it('puts the choice back and explains a read-only impersonation', async () => {
        vi.mocked(router.put).mockImplementation((_url, _data, options) => {
            options?.onHttpException?.({
                status: 403,
                data: { error: 'impersonation_read_only' },
            } as never);
            options?.onFinish?.({} as never);
        });
        const wrapper = mount(AchievementsProfileSection, {
            props: { earned: [earned()], catalogueUrl: '/logros', owner },
        });

        await wrapper.find('[data-test="visibility-switch"]').trigger('click');

        expect(wrapper.find('[role="alert"]').text()).toBe(
            'Mientras se mira la web como otra persona no se guarda nada.'
        );
        expect(wrapper.find('[data-test="visibility-switch"]').attributes('aria-checked')).toBe(
            'true'
        );
    });

    it('puts the choice back when the server answers something else', async () => {
        vi.mocked(router.put).mockImplementation((_url, _data, options) => {
            options?.onHttpException?.({ status: 429, data: {} } as never);
            options?.onFinish?.({} as never);
        });
        const wrapper = mount(AchievementsProfileSection, {
            props: { earned: [earned()], catalogueUrl: '/logros', owner },
        });

        await wrapper.find('[data-test="feature"]').trigger('click');

        expect(wrapper.find('[role="alert"]').text()).toContain('Demasiados cambios seguidos');
        expect(wrapper.find('[data-test="feature"]').attributes('aria-pressed')).toBe('false');
    });

    it('ignores the switch while a change is being saved', async () => {
        vi.mocked(router.put).mockImplementation(() => undefined);
        const wrapper = mount(AchievementsProfileSection, {
            props: { earned: [earned()], catalogueUrl: '/logros', owner },
        });
        const toggle = wrapper.find('[data-test="visibility-switch"]');

        await toggle.trigger('click');
        await toggle.trigger('click');

        expect(router.put).toHaveBeenCalledTimes(1);
        expect(toggle.attributes('aria-checked')).toBe('false');
    });
});
