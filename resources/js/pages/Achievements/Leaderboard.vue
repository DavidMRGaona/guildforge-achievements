<script setup lang="ts">
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Trophy } from '@lucide/vue';
import HelpLink from '@/components/knowledge/HelpLink.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import type {
    LeaderboardEntry,
    LeaderboardPagination,
    LeaderboardViewerStatus,
} from '../../types/achievements';

/**
 * `/logros/clasificacion`: who has the most achievement points, all time or in one season. The
 * server already left out every profile this viewer may not see, and ranks count only those, so
 * nothing here can tell about anybody else. Only totals: never which game or event points came from.
 */
interface Props {
    entries: LeaderboardEntry[];
    pagination: LeaderboardPagination;
    /** status is null when it could not be told: nothing is said then */
    viewer: { entry: LeaderboardEntry | null; status: LeaderboardViewerStatus | null };
    available?: boolean;
    /** The season shown, or null for all time */
    season?: number | null;
    /** Seasons on offer, newest first; empty while seasons are off */
    seasons?: number[];
    currentSeason: number;
    showsLevels?: boolean;
    catalogueUrl: string;
    leaderboardUrl: string;
}

const props = withDefaults(defineProps<Props>(), {
    available: true,
    season: null,
    seasons: () => [],
    showsLevels: false,
});

const { t, locale } = useI18n();
const page = usePage();

const ownProfilePath = computed<string | null>(() => {
    const auth = page.props.auth as { user?: { publicProfilePath?: string | null } } | undefined;

    return auth?.user?.publicProfilePath ?? null;
});

const viewerOffPage = computed(
    () => props.viewer.entry !== null && !props.entries.some((entry) => entry.isViewer)
);

function points(value: number): string {
    return t(
        'achievements.leaderboard.points',
        { points: value.toLocaleString(locale.value) },
        value
    );
}

function urlFor(season: number | null, pageNumber = 1): string {
    const query = new URLSearchParams();

    if (season !== null) {
        query.set('temporada', String(season));
    }

    if (pageNumber > 1) {
        query.set('page', String(pageNumber));
    }

    const search = query.toString();

    return search === '' ? props.leaderboardUrl : `${props.leaderboardUrl}?${search}`;
}

function chooseSeason(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    router.visit(urlFor(value === '' ? null : Number(value)), { preserveScroll: true });
}

function initials(name: string): string {
    return name
        .split(/\s+/u)
        .filter((part) => part !== '')
        .slice(0, 2)
        .map((part) => part.charAt(0).toLocaleUpperCase(locale.value))
        .join('');
}
</script>

<template>
    <div class="bg-surface shadow dark:shadow-depth">
        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold tracking-tight text-base-primary">
                {{ t('achievements.leaderboard.title') }}
                <HelpLink guide="achievements.site.points" class="ml-1 align-middle" />
            </h1>
            <p class="mt-2 max-w-3xl text-lg text-base-secondary">
                {{ t('achievements.leaderboard.subtitle') }}
            </p>

            <!-- All time or one season -->
            <div v-if="seasons.length > 0" class="mt-4 flex flex-wrap items-center gap-3">
                <nav :aria-label="t('achievements.leaderboard.periodLabel')" class="flex gap-2">
                    <Link
                        :href="urlFor(null)"
                        class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        :class="
                            season === null
                                ? 'bg-primary-600 text-white dark:bg-primary-500 dark:text-neutral-950'
                                : 'text-base-secondary hover:bg-muted'
                        "
                        :aria-current="season === null ? 'page' : undefined"
                        data-test="all-time"
                    >
                        {{ t('achievements.leaderboard.allTime') }}
                    </Link>
                    <Link
                        :href="urlFor(currentSeason)"
                        class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        :class="
                            season === currentSeason
                                ? 'bg-primary-600 text-white dark:bg-primary-500 dark:text-neutral-950'
                                : 'text-base-secondary hover:bg-muted'
                        "
                        :aria-current="season === currentSeason ? 'page' : undefined"
                        data-test="current-season"
                    >
                        {{ t('achievements.leaderboard.season', { season: currentSeason }) }}
                    </Link>
                </nav>
                <label
                    v-if="seasons.length > 1"
                    class="flex items-center gap-2 text-sm text-base-secondary"
                >
                    <span>{{ t('achievements.leaderboard.pastSeasons') }}</span>
                    <select
                        class="min-h-[44px] rounded-md border border-[var(--color-border)] bg-surface px-2 text-base-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        :value="season ?? ''"
                        data-test="season-select"
                        @change="chooseSeason"
                    >
                        <option value="">{{ t('achievements.leaderboard.allTime') }}</option>
                        <option v-for="year in seasons" :key="year" :value="year">
                            {{ t('achievements.leaderboard.season', { season: year }) }}
                        </option>
                    </select>
                </label>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <!-- Where the viewer stands, or why they are not there: only about themselves -->
        <div
            v-if="available && (viewer.entry || viewer.status)"
            class="rounded-lg border border-[var(--color-border)] bg-muted p-4 text-sm"
            data-test="viewer"
        >
            <p v-if="viewer.entry" class="font-medium text-base-primary">
                {{
                    t('achievements.leaderboard.yourRank', {
                        rank: viewer.entry.rank,
                        points: points(viewer.entry.points),
                    })
                }}
                <span v-if="showsLevels && viewer.entry.level" class="text-base-secondary">
                    ·
                    {{
                        t('achievements.leaderboard.level', {
                            number: viewer.entry.level.number,
                            name: viewer.entry.level.name,
                        })
                    }}
                </span>
            </p>
            <p v-else-if="viewer.status" class="text-base-secondary">
                {{ t(`achievements.leaderboard.status.${viewer.status}`) }}
                <Link
                    v-if="
                        ownProfilePath &&
                        (viewer.status === 'hidden' || viewer.status === 'opted_out')
                    "
                    :href="ownProfilePath"
                    class="font-medium text-[var(--color-primary-link)] underline-offset-2 hover:underline"
                >
                    {{ t('achievements.leaderboard.changeOnProfile') }}
                </Link>
            </p>
        </div>

        <p v-if="!available" class="text-base-secondary" role="status" data-test="unavailable">
            {{ t('achievements.leaderboard.unavailable') }}
        </p>

        <EmptyState
            v-else-if="entries.length === 0"
            icon="document"
            :title="t('achievements.leaderboard.empty')"
        />

        <template v-else>
            <ol
                class="divide-y divide-[var(--color-border)] rounded-lg bg-surface shadow dark:shadow-depth"
                data-test="entries"
            >
                <li
                    v-for="entry in entries"
                    :key="entry.profileUrl"
                    class="flex min-w-0 items-center gap-3 px-3 py-3 sm:px-4"
                    :class="entry.isViewer ? 'bg-primary-50 dark:bg-primary-900/40' : ''"
                    :aria-current="entry.isViewer ? 'true' : undefined"
                >
                    <span
                        class="flex w-10 shrink-0 items-center justify-center gap-1 text-base font-semibold tabular-nums text-base-primary"
                        :aria-label="t('achievements.leaderboard.rank', { rank: entry.rank })"
                    >
                        <Trophy
                            v-if="entry.rank <= 3"
                            class="h-4 w-4 text-amber-600 dark:text-amber-300"
                            aria-hidden="true"
                        />
                        <span aria-hidden="true">{{ entry.rank }}</span>
                    </span>
                    <img
                        v-if="entry.avatarUrl"
                        :src="entry.avatarUrl"
                        alt=""
                        width="40"
                        height="40"
                        class="h-10 w-10 shrink-0 rounded-full object-cover"
                        loading="lazy"
                    />
                    <span
                        v-else
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-sm font-semibold text-neutral-700 dark:bg-neutral-700 dark:text-neutral-100"
                        aria-hidden="true"
                    >
                        {{ initials(entry.publicName) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="entry.profileUrl"
                            class="break-words font-medium text-base-primary underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        >
                            {{ entry.publicName }}
                        </Link>
                        <p v-if="showsLevels && entry.level" class="text-xs text-base-muted">
                            {{
                                t('achievements.leaderboard.level', {
                                    number: entry.level.number,
                                    name: entry.level.name,
                                })
                            }}
                        </p>
                    </div>
                    <span class="shrink-0 text-sm font-semibold tabular-nums text-base-primary">
                        {{ points(entry.points) }}
                    </span>
                </li>
            </ol>

            <p v-if="viewerOffPage" class="text-sm text-base-secondary">
                {{ t('achievements.leaderboard.offPage') }}
            </p>

            <nav
                v-if="pagination.lastPage > 1"
                class="flex items-center justify-between gap-3 text-sm"
                :aria-label="t('achievements.leaderboard.pagesLabel')"
            >
                <Link
                    v-if="pagination.page > 1"
                    :href="urlFor(season, pagination.page - 1)"
                    class="inline-flex min-h-[44px] items-center font-medium text-[var(--color-primary-link)] underline-offset-2 hover:underline"
                    rel="prev"
                >
                    {{ t('achievements.leaderboard.previous') }}
                </Link>
                <span v-else />
                <span class="text-base-muted">
                    {{
                        t('achievements.leaderboard.pageOf', {
                            page: pagination.page,
                            last: pagination.lastPage,
                        })
                    }}
                </span>
                <Link
                    v-if="pagination.page < pagination.lastPage"
                    :href="urlFor(season, pagination.page + 1)"
                    class="inline-flex min-h-[44px] items-center font-medium text-[var(--color-primary-link)] underline-offset-2 hover:underline"
                    rel="next"
                >
                    {{ t('achievements.leaderboard.next') }}
                </Link>
                <span v-else />
            </nav>
        </template>

        <p class="text-sm text-base-muted">{{ t('achievements.leaderboard.explained') }}</p>

        <Link
            :href="catalogueUrl"
            class="inline-flex min-h-[44px] items-center text-sm font-medium text-[var(--color-primary-link)] underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
        >
            {{ t('achievements.leaderboard.seeCatalogue') }}
        </Link>
    </div>
</template>
