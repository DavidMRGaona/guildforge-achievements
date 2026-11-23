import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Recognitions from '../../resources/js/pages/Achievements/Recognitions.vue';
import RecognitionsProfileSection from '../../resources/js/components/profileSections/RecognitionsProfileSection.vue';
import type {
    OwnRecognition,
    RecognitionSubject,
    RecognitionType,
} from '../../resources/js/types/achievements';

const storytelling: RecognitionType = {
    id: '0195f3a2-0000-7000-8000-000000000001',
    name: 'Gran narración',
    icon: 'scroll-text',
    colour: 'violet',
};
const companion: RecognitionType = {
    id: '0195f3a2-0000-7000-8000-000000000002',
    name: 'Buen compañero',
    icon: 'handshake',
    colour: 'sky',
};

function subject(overrides: Partial<RecognitionSubject> = {}): RecognitionSubject {
    return {
        sourceType: 'game-tables.session',
        subjectId: 'mesa-1',
        title: 'La cripta olvidada',
        url: '/mesas/la-cripta',
        closesOn: '2026-12-22',
        people: [
            {
                handle: 'bob',
                publicName: 'Bob',
                profileUrl: '/miembros/bob',
                avatarUrl: null,
                givenTypeId: null,
            },
        ],
        ...overrides,
    };
}

function own(overrides: Partial<OwnRecognition> = {}): OwnRecognition {
    return {
        id: '0195f3a2-0000-7000-8000-0000000000aa',
        type: storytelling,
        personName: 'Ana',
        personUrl: '/miembros/ana',
        month: '2026-12',
        ...overrides,
    };
}

const base = {
    types: [storytelling, companion],
    subjects: [] as RecognitionSubject[],
    received: [] as OwnRecognition[],
    given: [] as OwnRecognition[],
    windowDays: 14,
    storeUrl: '/logros/reconocimientos',
};

const stubs = { HelpLink: true };

describe('Achievements/Recognitions', () => {
    beforeEach(() => {
        vi.mocked(router.post).mockReset();
        vi.mocked(router.delete).mockReset();
    });

    it('offers one button per type and sends the person by their address', async () => {
        const wrapper = mount(Recognitions, {
            props: { ...base, subjects: [subject()] },
            global: { stubs },
        });

        expect(wrapper.find('[data-test="subject"]').text()).toContain('La cripta olvidada');
        expect(wrapper.find('[data-test="subject"]').text()).toContain('Hasta el 22 de diciembre');
        const buttons = wrapper.findAll('[data-test="type"]');
        expect(buttons.map((button) => button.text())).toEqual([
            'Gran narración',
            'Buen compañero',
        ]);

        await buttons[1]?.trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/logros/reconocimientos',
            {
                sourceType: 'game-tables.session',
                subjectId: 'mesa-1',
                receiver: 'bob',
                type: companion.id,
            },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('says what was already given instead of the buttons', () => {
        const wrapper = mount(Recognitions, {
            props: {
                ...base,
                subjects: [
                    subject({
                        people: [
                            {
                                handle: 'bob',
                                publicName: 'Bob',
                                profileUrl: '/miembros/bob',
                                avatarUrl: null,
                                givenTypeId: storytelling.id,
                            },
                        ],
                    }),
                ],
            },
            global: { stubs },
        });

        expect(wrapper.find('[data-test="given"]').text()).toBe('Le diste «Gran narración»');
        expect(wrapper.findAll('[data-test="type"]')).toHaveLength(0);
    });

    it('lists what was received with who gave it and the month, never the game', () => {
        const wrapper = mount(Recognitions, {
            props: { ...base, received: [own()], given: [own({ id: 'x', personName: null })] },
            global: { stubs },
        });

        const received = wrapper.find('[data-test="received-item"]').text();
        expect(received).toContain('Gran narración');
        expect(received).toContain('De Ana');
        expect(received).toContain('diciembre de 2026');
        expect(received).not.toContain('cripta');
        expect(wrapper.find('[data-test="given-item"]').text()).toContain(
            'Para Una persona sin nombre público'
        );
    });

    it('takes one away only after confirming', async () => {
        const confirm = vi.fn().mockReturnValueOnce(false).mockReturnValueOnce(true);
        Object.defineProperty(window, 'confirm', {
            value: confirm,
            configurable: true,
            writable: true,
        });
        const wrapper = mount(Recognitions, {
            props: { ...base, received: [own()] },
            global: { stubs },
        });

        await wrapper.find('[data-test="remove"]').trigger('click');
        expect(router.delete).not.toHaveBeenCalled();

        await wrapper.find('[data-test="remove"]').trigger('click');
        expect(router.delete).toHaveBeenCalledWith(
            '/logros/reconocimientos/0195f3a2-0000-7000-8000-0000000000aa',
            expect.objectContaining({ preserveScroll: true })
        );
        expect(confirm).toHaveBeenCalledTimes(2);
    });

    it('says when there is nothing to recognise and how many days there are', () => {
        const wrapper = mount(Recognitions, { props: base, global: { stubs } });

        expect(wrapper.find('[data-test="give-empty"]').text()).toContain('14 días');
    });

    it('tells a read-only impersonation apart from a failure', async () => {
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onHttpException?.({
                status: 403,
                data: { error: 'impersonation_read_only' },
            } as never);
        });
        const wrapper = mount(Recognitions, {
            props: { ...base, subjects: [subject()] },
            global: { stubs },
        });

        await wrapper.find('[data-test="type"]').trigger('click');

        expect(wrapper.find('[data-test="error"]').text()).toContain('no se guarda nada');
    });
});

describe('RecognitionsProfileSection', () => {
    it('shows totals per type and nothing about who gave them', () => {
        const wrapper = mount(RecognitionsProfileSection, {
            props: { totals: [{ type: storytelling, count: 3 }] },
        });

        expect(wrapper.find('[data-test="total"]').text()).toContain('Gran narración');
        expect(wrapper.find('[data-test="total"]').text()).toContain('3');
        expect(wrapper.find('[data-test="owner-note"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="page-link"]').exists()).toBe(false);
    });

    it('tells the owner that others see last month and links their page', () => {
        const wrapper = mount(RecognitionsProfileSection, {
            props: { totals: [], isOwner: true, pageUrl: '/logros/reconocimientos' },
        });

        expect(wrapper.find('[data-test="owner-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="owner-note"]').text()).toContain('hasta el mes anterior');
        expect(wrapper.find('[data-test="page-link"]').attributes('href')).toBe(
            '/logros/reconocimientos'
        );
    });
});
