<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { challengeShare } from '../utils/challengeDays';

/**
 * The progress of a collective challenge: a bar with its total, read out as «12 de 40 partidas».
 * `dark` is for the footer, dark in both colour schemes.
 */
interface Props {
    progress: number;
    target: number;
    unit: string;
    tone?: 'default' | 'dark';
}

const props = withDefaults(defineProps<Props>(), { tone: 'default' });

const { t, n } = useI18n();

const share = computed(() => challengeShare(props.progress, props.target));
const label = computed(() =>
    t('achievements.challenges.progress', {
        progress: n(props.progress),
        target: n(props.target),
        unit: props.unit,
    })
);
</script>

<template>
    <div>
        <div
            class="h-2.5 w-full overflow-hidden rounded-full"
            :class="
                tone === 'dark'
                    ? 'bg-(--color-white)/20'
                    : 'bg-(--color-neutral-200) dark:bg-(--color-neutral-700)'
            "
            role="progressbar"
            :aria-valuenow="Math.min(progress, target)"
            aria-valuemin="0"
            :aria-valuemax="target"
            :aria-valuetext="label"
        >
            <div
                class="h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none"
                :class="
                    tone === 'dark'
                        ? 'bg-(--color-primary-400)'
                        : 'bg-(--color-primary-600) dark:bg-(--color-primary-400)'
                "
                :style="{ width: `${share}%` }"
            />
        </div>
        <p
            class="mt-1 text-sm font-medium"
            :class="tone === 'dark' ? 'text-white/90' : 'text-base-secondary'"
            data-test="challenge-progress"
        >
            {{ label }}
        </p>
    </div>
</template>
