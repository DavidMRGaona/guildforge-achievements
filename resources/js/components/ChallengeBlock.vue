<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSiteBlockPlacement } from '@/composables/useSiteBlockPlacement';
import ChallengeProgressBar from './ChallengeProgressBar.vue';
import { formatChallengeDay } from '../utils/challengeDays';
import type { Challenge } from '../types/achievements';

/**
 * Site block `achievements.challenge` (#12): a collective challenge and its progress, in the footer,
 * a popup or on the link page. The footer is dark in both colour schemes; a popup and the link page
 * follow the page, so the tone comes from the placement the core says it is drawn in.
 */
interface Props {
    challenge: Challenge;
    url: string;
}

const props = defineProps<Props>();

const { t, locale } = useI18n();

const placement = useSiteBlockPlacement();
const dark = computed(() => placement.value === 'footer');

const when = computed(() => {
    const key = props.challenge.state === 'upcoming' ? 'startsOn' : 'until';
    const day =
        props.challenge.state === 'upcoming' ? props.challenge.startsOn : props.challenge.endsOn;

    return t(`achievements.challenges.block.${key}`, {
        day: formatChallengeDay(day, locale.value),
    });
});
</script>

<template>
    <div class="min-w-0" data-achievements-challenge>
        <p class="break-words font-semibold" :class="dark ? 'text-white' : 'text-base-primary'">
            {{ challenge.name }}
        </p>
        <p class="mb-2 text-sm" :class="dark ? 'text-white/75' : 'text-base-muted'">
            <template v-if="challenge.state === 'completed'">
                {{ t('achievements.challenges.states.completed') }} ·
            </template>
            {{ when }}
        </p>
        <ChallengeProgressBar
            :progress="challenge.progress"
            :target="challenge.target"
            :unit="challenge.unit"
            :tone="dark ? 'dark' : 'default'"
        />
        <a
            :href="url"
            class="mt-2 inline-block text-sm font-medium underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
            :class="
                dark
                    ? 'text-white focus-visible:outline-white'
                    : 'text-[var(--color-primary-link)] focus-visible:outline-primary-600'
            "
        >
            {{ t('achievements.challenges.block.seeAll') }}
        </a>
    </div>
</template>
