<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';

/**
 * Taking a received recognition away is for good: a modal dialog with a box to tick, as every
 * irreversible action of the site. «Quitar» stays disabled until the box is ticked; closing in any
 * way (Escape, the backdrop, «Cancelar») leaves the recognition as it is.
 */
interface Props {
    open: boolean;
    /** The type, to say what goes */
    typeName: string;
    busy?: boolean;
}

const props = withDefaults(defineProps<Props>(), { busy: false });

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();

const { t } = useI18n();
const dialogRef = ref<HTMLDialogElement | null>(null);
const understood = ref(false);
const canConfirm = computed(() => understood.value && !props.busy);

watch(
    () => props.open,
    async (open) => {
        understood.value = false;
        await nextTick();
        const dialog = dialogRef.value;

        if (dialog === null || typeof dialog.showModal !== 'function') {
            return;
        }

        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    },
    { immediate: true }
);

function confirm(): void {
    if (canConfirm.value) {
        emit('confirm');
    }
}

function cancel(): void {
    emit('cancel');
}

function onBackdrop(event: MouseEvent): void {
    if (event.target === dialogRef.value) {
        cancel();
    }
}
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialogRef"
            class="fixed inset-0 m-auto max-w-md rounded-lg border-0 bg-transparent p-0 backdrop:bg-neutral-900/60"
            aria-labelledby="remove-recognition-title"
            data-test="remove-dialog"
            @click="onBackdrop"
            @cancel.prevent="cancel"
        >
            <div
                v-if="open"
                class="w-full max-w-md rounded-lg bg-surface p-6 shadow-xl dark:shadow-depth"
            >
                <h3 id="remove-recognition-title" class="text-lg font-semibold text-base-primary">
                    {{ t('achievements.recognitions.removeTitle') }}
                </h3>
                <p class="mt-2 text-base-secondary">
                    {{ t('achievements.recognitions.removeMessage', { type: typeName }) }}
                </p>
                <label class="mt-4 flex min-h-[44px] items-start gap-3 text-sm text-base-primary">
                    <input
                        v-model="understood"
                        type="checkbox"
                        class="mt-0.5 h-5 w-5 rounded border-default text-primary-600 focus-visible:ring-2 focus-visible:ring-primary-500"
                        data-test="remove-understood"
                    />
                    <span>{{ t('achievements.recognitions.removeUnderstood') }}</span>
                </label>
                <div class="mt-6 flex justify-end gap-3">
                    <BaseButton
                        variant="secondary"
                        size="sm"
                        data-test="remove-cancel"
                        @click="cancel"
                    >
                        {{ t('achievements.recognitions.cancel') }}
                    </BaseButton>
                    <BaseButton
                        variant="danger"
                        size="sm"
                        :disabled="!canConfirm"
                        data-test="remove-confirm"
                        @click="confirm"
                    >
                        {{ t('achievements.recognitions.remove') }}
                    </BaseButton>
                </div>
            </div>
        </dialog>
    </Teleport>
</template>
