<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AchievementBadge from '../AchievementBadge.vue';
import type { RecognitionTotal } from '../../types/achievements';

/**
 * «Reconocimientos» on a public profile (#11): totals per type, never who gave them or for which
 * game. For anybody but the owner they are counted up to the month before (the server cut them).
 */
interface Props {
    totals: RecognitionTotal[];
    isOwner?: boolean;
    pageUrl?: string | null;
}

withDefaults(defineProps<Props>(), { isOwner: false, pageUrl: null });

const { t } = useI18n();
</script>

<template>
    <div>
        <p v-if="totals.length === 0" class="text-sm text-base-secondary" data-test="owner-empty">
            {{ t('achievements.recognitions.section.ownerEmpty') }}
        </p>
        <ul v-else class="space-y-3">
            <li
                v-for="total in totals"
                :key="total.type.id"
                class="flex items-center gap-3"
                data-test="total"
            >
                <AchievementBadge :icon="total.type.icon" :colour="total.type.colour" />
                <span class="min-w-0 flex-1 font-medium text-base-primary">{{
                    total.type.name
                }}</span>
                <span class="text-lg font-bold text-base-primary tabular-nums">{{
                    total.count
                }}</span>
            </li>
        </ul>
        <template v-if="isOwner">
            <p class="mt-3 text-xs text-base-secondary" data-test="owner-note">
                {{ t('achievements.recognitions.section.ownerNote') }}
            </p>
            <Link
                v-if="pageUrl"
                :href="pageUrl"
                class="mt-2 inline-flex min-h-[44px] items-center text-sm font-medium text-[var(--color-primary-link)] underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                data-test="page-link"
            >
                {{ t('achievements.recognitions.section.seeAll') }}
            </Link>
        </template>
    </div>
</template>
