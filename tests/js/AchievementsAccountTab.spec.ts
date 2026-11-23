import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import AchievementsAccountTab from '../../resources/js/components/profile/AchievementsAccountTab.vue';
import type { AccountAchievements } from '../../resources/js/types/achievements';

function account(overrides: Partial<AccountAchievements> = {}): AccountAchievements {
    return {
        announceRare: false,
        inLeaderboard: true,
        hidden: false,
        offersAnnouncements: true,
        leaderboardOn: true,
        threshold: 10,
        updateUrl: '/logros/preferencias',
        noticesUrl: '/perfil?tab=mail',
        ...overrides,
    };
}

function mountTab(achievementsAccount: AccountAchievements | null): VueWrapper {
    return mount(AchievementsAccountTab, {
        props: { achievementsAccount },
        global: { stubs: { HelpLink: true } },
    });
}

describe('AchievementsAccountTab', () => {
    beforeEach(() => {
        vi.mocked(router.put).mockReset();
    });

    it('shows both choices with what is saved and a link to the notices', () => {
        const wrapper = mountTab(account());

        expect(wrapper.text()).toContain('Anunciar mis logros raros');
        expect(wrapper.text()).toContain('menos del 10 % de la gente activa');
        expect(wrapper.text()).toContain('Aparecer en la clasificación');
        expect(wrapper.find('[data-test="switch-announceRare"]').attributes('aria-checked')).toBe(
            'false'
        );
        expect(wrapper.find('[data-test="switch-inLeaderboard"]').attributes('aria-checked')).toBe(
            'true'
        );
        expect(wrapper.find('[data-test="notices-link"]').attributes('href')).toBe(
            '/perfil?tab=mail'
        );
    });

    it('always offers leaving the leaderboard, announcing only while it is on', () => {
        const wrapper = mountTab(account({ offersAnnouncements: false, leaderboardOn: false }));

        expect(wrapper.find('[data-test="switch-announceRare"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="switch-inLeaderboard"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('La clasificación está apagada ahora mismo');
    });

    it('tells that the summary is monthly and says no day', () => {
        expect(mountTab(account()).text()).toContain('Una vez al mes');
    });

    it('draws nothing without the tab', () => {
        expect(mountTab(null).find('[data-test="achievements-account"]').exists()).toBe(false);
    });

    it('says nothing is announced while the achievements are hidden', () => {
        expect(
            mountTab(account({ hidden: true }))
                .find('[data-test="hidden-note"]')
                .exists()
        ).toBe(true);
        expect(mountTab(account()).find('[data-test="hidden-note"]').exists()).toBe(false);
    });

    it('saves one choice at once', async () => {
        const wrapper = mountTab(account());

        await wrapper.find('[data-test="switch-announceRare"]').trigger('click');

        expect(router.put).toHaveBeenCalledWith(
            '/logros/preferencias',
            { announceRare: true },
            expect.anything()
        );
        expect(wrapper.find('[data-test="switch-announceRare"]').attributes('aria-checked')).toBe(
            'true'
        );
    });

    it('puts the choice back and explains a read-only impersonation', async () => {
        vi.mocked(router.put).mockImplementation((_url, _data, options) => {
            options?.onHttpException?.({
                status: 403,
                data: { error: 'impersonation_read_only' },
            } as never);
            options?.onFinish?.({} as never);
        });
        const wrapper = mountTab(account());

        await wrapper.find('[data-test="switch-inLeaderboard"]').trigger('click');

        expect(wrapper.find('[role="alert"]').text()).toBe(
            'Mientras se mira la web como otra persona no se guarda nada.'
        );
        expect(wrapper.find('[data-test="switch-inLeaderboard"]').attributes('aria-checked')).toBe(
            'true'
        );
    });

    it('shows what the server refused and puts the choice back', async () => {
        vi.mocked(router.put).mockImplementation((_url, _data, options) => {
            options?.onError?.({
                announceRare: 'Los anuncios de logros están apagados ahora mismo.',
            });
            options?.onFinish?.({} as never);
        });
        const wrapper = mountTab(account());

        await wrapper.find('[data-test="switch-announceRare"]').trigger('click');

        expect(wrapper.find('[role="alert"]').text()).toBe(
            'Los anuncios de logros están apagados ahora mismo.'
        );
        expect(wrapper.find('[data-test="switch-announceRare"]').attributes('aria-checked')).toBe(
            'false'
        );
    });

    it('ignores a switch while a change is being saved', async () => {
        vi.mocked(router.put).mockImplementation(() => undefined);
        const wrapper = mountTab(account());

        await wrapper.find('[data-test="switch-announceRare"]').trigger('click');
        await wrapper.find('[data-test="switch-inLeaderboard"]').trigger('click');

        expect(router.put).toHaveBeenCalledTimes(1);
    });
});
