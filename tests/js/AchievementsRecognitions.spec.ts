import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Recognitions from '../../resources/js/pages/Achievements/Recognitions.vue';
import RemoveRecognitionDialog from '../../resources/js/components/RemoveRecognitionDialog.vue';
import RecognitionsProfileSection from '../../resources/js/components/profileSections/RecognitionsProfileSection.vue';
import type {
    OwnRecognition,
    RecognitionSubject,
    RecognitionType,
} from '../../resources/js/types/achievements';

const storytelling: RecognitionType = {
    id: '7c2d9e14-8a3b-4f6c-b1d2-0e5a7f9c3b01',
    name: 'Gran narración',
    icon: 'scroll-text',
    colour: 'violet',
};
const companion: RecognitionType = {
    id: '3e8a1b5c-6d2f-4a9e-8c7b-1f4d2e6a9b02',
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
        id: '5b0f6e2a-3c41-4d8e-9a17-2f6c1b3d4eaa',
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

    it('takes one away only from the dialog, once its box is ticked', async () => {
        const wrapper = mount(Recognitions, {
            props: { ...base, received: [own()] },
            global: { stubs: { ...stubs, teleport: true } },
        });

        expect(wrapper.find('[data-test="remove-confirm"]').exists()).toBe(false);
        await wrapper.find('[data-test="remove"]').trigger('click');

        const confirm = wrapper.find('[data-test="remove-confirm"]');
        expect(wrapper.find('[data-test="remove-dialog"]').text()).toContain('Gran narración');
        expect(confirm.attributes('disabled')).toBeDefined();
        await confirm.trigger('click');
        expect(router.delete).not.toHaveBeenCalled();

        await wrapper.find('[data-test="remove-understood"]').setValue(true);
        expect(wrapper.find('[data-test="remove-confirm"]').attributes('disabled')).toBeUndefined();
        await wrapper.find('[data-test="remove-confirm"]').trigger('click');

        expect(router.delete).toHaveBeenCalledWith(
            '/logros/reconocimientos/5b0f6e2a-3c41-4d8e-9a17-2f6c1b3d4eaa',
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('leaves it as it is when the dialog is cancelled', async () => {
        const wrapper = mount(Recognitions, {
            props: { ...base, received: [own()] },
            global: { stubs: { ...stubs, teleport: true } },
        });

        await wrapper.find('[data-test="remove"]').trigger('click');
        await wrapper.find('[data-test="remove-understood"]').setValue(true);
        await wrapper.find('[data-test="remove-cancel"]').trigger('click');

        expect(router.delete).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="remove-confirm"]').exists()).toBe(false);
    });

    it('puts focus on the next row once one is removed, and on the heading when none is left', async () => {
        const second = own({ id: '9a4c2e7b-1d3f-4b6a-8e5c-7f2a9d1b3c44', personName: 'Luis' });
        vi.mocked(router.delete).mockImplementation((_url, options) => {
            options?.onSuccess?.({} as never);
            options?.onFinish?.({} as never);
        });
        const wrapper = mount(Recognitions, {
            props: { ...base, received: [own(), second] },
            global: { stubs: { ...stubs, teleport: true } },
            attachTo: document.body,
        });

        await wrapper.findAll('[data-test="remove"]')[0]?.trigger('click');
        await wrapper.find('[data-test="remove-understood"]').setValue(true);
        // The server answered: the page now lists only the other one
        await wrapper.setProps({ received: [second] });
        await wrapper.find('[data-test="remove-confirm"]').trigger('click');
        await flushPromises();

        const remaining = wrapper.find('[data-test="remove"]');
        expect(document.activeElement).toBe(remaining.element);

        await remaining.trigger('click');
        await wrapper.find('[data-test="remove-understood"]').setValue(true);
        await wrapper.setProps({ received: [] });
        await wrapper.find('[data-test="remove-confirm"]').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(wrapper.find('#recognitions-received').element);
        wrapper.unmount();
    });

    it('puts focus on the row before when the last one is removed', async () => {
        const first = own({ id: '1f3a5c7e-9b2d-4e6f-8a1c-3e5f7a9b1d11', personName: 'Luis' });
        vi.mocked(router.delete).mockImplementation((_url, options) => {
            options?.onSuccess?.({} as never);
            options?.onFinish?.({} as never);
        });
        const wrapper = mount(Recognitions, {
            props: { ...base, received: [first, own()] },
            global: { stubs: { ...stubs, teleport: true } },
            attachTo: document.body,
        });

        await wrapper.findAll('[data-test="remove"]')[1]?.trigger('click');
        await wrapper.find('[data-test="remove-understood"]').setValue(true);
        await wrapper.setProps({ received: [first] });
        await wrapper.find('[data-test="remove-confirm"]').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(wrapper.find('[data-test="remove"]').element);
        wrapper.unmount();
    });

    it('tells nameless rows apart by month, in lower case', () => {
        const wrapper = mount(Recognitions, {
            props: { ...base, received: [own({ personName: null, personUrl: null })] },
            global: { stubs },
        });

        expect(wrapper.find('[data-test="remove"]').attributes('aria-label')).toBe(
            'Quitar «Gran narración» de diciembre de 2026, de una persona sin nombre público'
        );
    });

    it('names the type and the person on each remove button', () => {
        const wrapper = mount(Recognitions, {
            props: { ...base, received: [own()] },
            global: { stubs },
        });

        expect(wrapper.find('[data-test="remove"]').attributes('aria-label')).toBe(
            'Quitar «Gran narración» de Ana'
        );
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

describe('RemoveRecognitionDialog', () => {
    const global = { stubs: { teleport: true } };

    it('keeps itself open on Escape while the removal is on its way', async () => {
        const wrapper = mount(RemoveRecognitionDialog, {
            props: { open: true, typeName: 'Gran narración', busy: true },
            global,
        });
        const cancel = new Event('cancel', { cancelable: true });

        wrapper.find('dialog').element.dispatchEvent(cancel);
        await wrapper.find('dialog').trigger('click');

        expect(cancel.defaultPrevented).toBe(true);
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('never tells the page it closed while the removal is on its way', () => {
        const wrapper = mount(RemoveRecognitionDialog, {
            props: { open: true, typeName: 'Gran narración', busy: true },
            global,
        });

        wrapper.find('dialog').element.dispatchEvent(new Event('close'));

        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('closes on Escape otherwise, and tells when the browser closed it', async () => {
        const wrapper = mount(RemoveRecognitionDialog, {
            props: { open: true, typeName: 'Gran narración' },
            global,
        });

        wrapper.find('dialog').element.dispatchEvent(new Event('cancel', { cancelable: true }));
        wrapper.find('dialog').element.dispatchEvent(new Event('close'));

        expect(wrapper.emitted('cancel')).toHaveLength(2);
    });

    it('describes itself with its message', () => {
        const wrapper = mount(RemoveRecognitionDialog, {
            props: { open: true, typeName: 'Gran narración' },
            global,
        });

        expect(wrapper.find('dialog').attributes('aria-describedby')).toBe(
            'remove-recognition-message'
        );
        expect(wrapper.find('#remove-recognition-message').text()).toContain('Gran narración');
    });
});
