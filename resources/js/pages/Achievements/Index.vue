<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Check, RefreshCw } from '@lucide/vue';
import HelpLink from '@/components/knowledge/HelpLink.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AchievementBadge from '../../components/AchievementBadge.vue';
import { formatShare } from '../../utils/badgeLook';
import type { AchievementProgress, CatalogueAchievement } from '../../types/achievements';

/**
 * `/logros`: every achievement that can be got, with the share of active people holding it. A secret
 * one the viewer does not hold shows as «Logro secreto», with no rarity. Nothing says who holds what.
 * One that repeats each season tells the viewer where they stand in the season going on: whether
 * they hold it, the earlier seasons they got it in and their progress (their own, never anybody
 * else's).
 */
interface Props {
    achievements: CatalogueAchievement[];
    showsRarity?: boolean;
    signedIn?: boolean;
    showsPoints?: boolean;
    /** Only for signed-in people while there is a leaderboard */
    leaderboardUrl?: string | null;
    /** The season going on; null while seasons and streaks are off */
    season?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    showsRarity: false,
    signedIn: false,
    showsPoints: false,
    leaderboardUrl: null,
    season: null,
});

const { t, locale } = useI18n();

/** Held now: this season's, for one that repeats */
function isHeld(item: CatalogueAchievement): boolean {
    return item.standing === null ? item.held : item.standing.heldNow;
}

const heldCount = computed(() => props.achievements.filter(isHeld).length);
const anyRepeats = computed(() => props.achievements.some((item) => item.repeats));

function seasonList(seasons: string[]): string {
    return new Intl.ListFormat(locale.value, { type: 'conjunction' }).format(
        [...seasons].reverse()
    );
}

function progressText(progress: AchievementProgress): string {
    return t(
        progress.inMonths
            ? 'achievements.catalogue.progressMonths'
            : 'achievements.catalogue.progress',
        { value: progress.value, threshold: progress.threshold }
    );
}
</script>

<template>
    <div class="bg-surface shadow dark:shadow-depth">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold tracking-tight text-base-primary">
                {{ t('achievements.catalogue.title') }}
                <HelpLink guide="achievements.site.achievements" class="ml-1 align-middle" />
            </h1>
            <p class="mt-2 max-w-3xl text-lg text-base-secondary">
                {{ t('achievements.catalogue.subtitle') }}
            </p>
            <p v-if="signedIn && achievements.length > 0" class="mt-2 text-sm text-base-muted">
                {{
                    t('achievements.catalogue.held', {
                        count: heldCount,
                        total: achievements.length,
                    })
                }}
            </p>
            <Link
                v-if="leaderboardUrl"
                :href="leaderboardUrl"
                class="mt-2 inline-flex min-h-[44px] items-center text-sm font-medium text-[var(--color-primary-link)] underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                data-test="leaderboard-link"
            >
                {{ t('achievements.catalogue.seeLeaderboard') }}
            </Link>
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <EmptyState
            v-if="achievements.length === 0"
            icon="document"
            :title="t('achievements.catalogue.empty')"
        />

        <template v-else>
            <p v-if="showsRarity" class="mb-4 text-sm text-base-secondary">
                {{ t('achievements.catalogue.rarityExplained') }}
            </p>
            <p
                v-if="season && anyRepeats"
                class="mb-4 text-sm text-base-secondary"
                data-test="season-explained"
            >
                {{ t('achievements.catalogue.seasonExplained', { season }) }}
            </p>
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" role="list" data-test="catalogue">
                <li
                    v-for="(item, index) in achievements"
                    :key="item.id ?? `masked-${index}`"
                    class="flex min-w-0 gap-4 rounded-lg bg-surface p-4 shadow dark:shadow-depth"
                    :class="isHeld(item) ? 'ring-2 ring-primary-500/60' : ''"
                >
                    <AchievementBadge
                        :icon="item.icon"
                        :colour="item.colour"
                        :image-url="item.imageUrl"
                        :locked="!item.held && item.masked"
                        size="lg"
                    />
                    <div class="min-w-0 flex-1">
                        <h2 class="break-words text-base font-semibold text-base-primary">
                            {{ item.name }}
                        </h2>
                        <p class="mt-1 break-words text-sm text-base-secondary">
                            {{ item.description }}
                        </p>
                        <p
                            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-base-muted"
                        >
                            <span v-if="showsRarity && item.rarity !== null">
                                {{
                                    t('achievements.rarity.share', {
                                        share: formatShare(item.rarity, locale),
                                    })
                                }}
                            </span>
                            <span v-else-if="showsRarity && item.heldByNobody">
                                {{ t('achievements.rarity.nobody') }}
                            </span>
                            <span v-if="showsPoints && item.points != null && item.points > 0">
                                {{
                                    t(
                                        'achievements.points.count',
                                        { points: item.points.toLocaleString(locale) },
                                        item.points
                                    )
                                }}
                            </span>
                            <span
                                v-if="item.repeats"
                                class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium text-base-secondary"
                                data-test="repeats"
                            >
                                <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ t('achievements.catalogue.repeats') }}
                            </span>
                            <span
                                v-if="isHeld(item)"
                                class="inline-flex items-center gap-1 rounded-full bg-primary-100 px-2 py-0.5 font-medium text-primary-800 dark:bg-primary-900 dark:text-primary-100"
                            >
                                <Check class="h-3.5 w-3.5" aria-hidden="true" />
                                {{
                                    item.standing
                                        ? t('achievements.catalogue.youHoldItThisSeason')
                                        : t('achievements.catalogue.youHoldIt')
                                }}
                            </span>
                        </p>
                        <p
                            v-if="
                                item.standing &&
                                (item.standing.progress || item.standing.pastSeasons.length > 0)
                            "
                            class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-base-muted"
                            data-test="standing"
                        >
                            <span v-if="item.standing.progress">
                                {{ progressText(item.standing.progress) }}
                            </span>
                            <span v-if="item.standing.pastSeasons.length > 0">
                                {{
                                    t('achievements.catalogue.pastSeasons', {
                                        seasons: seasonList(item.standing.pastSeasons),
                                    })
                                }}
                            </span>
                        </p>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>
