<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Star } from '@lucide/vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import AchievementBadge from '../AchievementBadge.vue';
import { formatShare } from '../../utils/badgeLook';
import type {
    AchievementProgress,
    EarnedAchievement,
    LockedAchievement,
    PointsSummary,
    ShowcaseOwner,
} from '../../types/achievements';

/**
 * «Logros» (`achievements.badges`) on a public profile: what the owner got, with its rarity. The
 * owner gets it newest first with the day; anybody else by month and name with the month alone
 * (the server trims it). The owner also sees what is left with their progress, stars up to three
 * to feature them under their name, and may hide them from everybody else. The card and its title
 * are the core's; the server already left out what this viewer may not see.
 */
interface Props {
    earned: EarnedAchievement[];
    /** Secret ones the viewer may not see: only counted, never listed */
    secretCount?: number;
    locked?: LockedAchievement[];
    showsRarity?: boolean;
    catalogueUrl: string;
    owner?: ShowcaseOwner | null;
    /** Totals and level; absent while points are off */
    points?: PointsSummary | null;
}

const props = withDefaults(defineProps<Props>(), {
    locked: () => [],
    secretCount: 0,
    showsRarity: false,
    owner: null,
    points: null,
});

const { t, locale } = useI18n();

const chosen = ref<string[]>([...(props.owner?.chosen ?? [])]);
const hidden = ref<boolean>(props.owner?.hidden ?? false);
const inLeaderboard = ref<boolean>(props.owner?.leaderboard ?? true);
const saving = ref(false);
const status = ref('');
const error = ref('');

// A save goes back to the profile, which sends the section again
watch(
    () => props.owner,
    (owner) => {
        chosen.value = [...(owner?.chosen ?? [])];
        hidden.value = owner?.hidden ?? false;
        inLeaderboard.value = owner?.leaderboard ?? true;
    }
);

const maxFeatured = computed(() => props.owner?.maxFeatured ?? 3);
const full = computed(() => chosen.value.length >= maxFeatured.value);

// VENUE_TIMEZONE is the display zone: the core reads it from the server's `display-timezone` meta
function formatDay(iso: string): string {
    return new Date(iso).toLocaleDateString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** «noviembre de 2026»: the month as sent (`YYYY-MM`), never moved to another zone */
function formatMonth(month: string): string {
    const [year, monthNumber] = month.split('-').map(Number);

    if (!year || !monthNumber) {
        return month;
    }

    return new Date(Date.UTC(year, monthNumber - 1, 15)).toLocaleDateString(locale.value, {
        timeZone: 'UTC',
        month: 'long',
        year: 'numeric',
    });
}

function share(rarity: number): string {
    return formatShare(rarity, locale.value);
}

/** «2025, 2026 y 2027»: the seasons, never what was played in them */
function seasonList(seasons: string[]): string {
    return new Intl.ListFormat(locale.value, { type: 'conjunction' }).format(
        [...seasons].reverse()
    );
}

function progressText(progress: AchievementProgress): string {
    return t(
        progress.inMonths ? 'achievements.profile.progressMonths' : 'achievements.profile.progress',
        {
            value: progress.value,
            threshold: progress.threshold,
        }
    );
}

function isChosen(id: string | null): boolean {
    return id !== null && chosen.value.includes(id);
}

/** What the server answered instead of the profile: shown, and the switch or star goes back */
function refusal(response: { status?: number; data?: unknown } | undefined): string {
    const data = response?.data;

    if (
        response?.status === 403 &&
        typeof data === 'object' &&
        data !== null &&
        (data as Record<string, unknown>).error === 'impersonation_read_only'
    ) {
        return t('achievements.profile.readOnly');
    }

    return response?.status === 429
        ? t('achievements.profile.tooMany')
        : t('achievements.profile.saveFailed');
}

function save(next: { hidden: boolean; featured: string[] }, done: string): void {
    const owner = props.owner;

    if (owner === null || saving.value) {
        return;
    }

    // The leaderboard choice travels only while there is a leaderboard
    const payload =
        owner.leaderboard === undefined ? next : { ...next, leaderboard: inLeaderboard.value };

    saving.value = true;
    status.value = '';
    error.value = '';
    let saved = false;

    router.put(owner.updateUrl, payload, {
        preserveScroll: true,
        onSuccess: () => {
            saved = true;
            status.value = done;
        },
        onError: (errors: Record<string, string>) => {
            error.value = errors.featured ?? errors.hidden ?? t('achievements.profile.saveFailed');
        },
        // A refusal (read-only impersonation, too many requests, a lost profile) is told here, not
        // in Inertia's error dialog
        onHttpException: (response: { status?: number; data?: unknown }) => {
            error.value = refusal(response);

            return false;
        },
        onNetworkError: () => {
            error.value = t('achievements.profile.saveFailed');

            return false;
        },
        onFinish: () => {
            saving.value = false;

            // Whatever went wrong, the switch and the stars show what is saved
            if (!saved) {
                chosen.value = [...owner.chosen];
                hidden.value = owner.hidden;
                inLeaderboard.value = owner.leaderboard ?? true;
                error.value ||= t('achievements.profile.saveFailed');
            }
        },
    });
}

function toggleFeatured(item: EarnedAchievement): void {
    const id = item.id;

    if (id === null || saving.value) {
        return;
    }

    const featured = isChosen(id)
        ? chosen.value.filter((chosenId) => chosenId !== id)
        : [...chosen.value, id];

    if (featured.length > maxFeatured.value) {
        return;
    }

    chosen.value = featured;
    save(
        { hidden: hidden.value, featured },
        isChosen(id)
            ? t('achievements.profile.featuredAdded', { name: item.name })
            : t('achievements.profile.featuredRemoved', { name: item.name })
    );
}

function toggleHidden(): void {
    if (saving.value) {
        return;
    }

    hidden.value = !hidden.value;
    save(
        { hidden: hidden.value, featured: chosen.value },
        hidden.value ? t('achievements.profile.nowHidden') : t('achievements.profile.nowShown')
    );
}

function toggleLeaderboard(): void {
    if (saving.value) {
        return;
    }

    inLeaderboard.value = !inLeaderboard.value;
    save(
        { hidden: hidden.value, featured: chosen.value },
        inLeaderboard.value
            ? t('achievements.profile.leaderboardIn')
            : t('achievements.profile.leaderboardOut')
    );
}

function formatPoints(value: number): string {
    return t('achievements.points.count', { points: value.toLocaleString(locale.value) }, value);
}

/** How far the owner is from the next level, from 0 to 100 */
const levelPercent = computed(() => {
    const summary = props.points;

    if (summary?.level == null || summary.nextLevel == null) {
        return 100;
    }

    const span = summary.nextLevel.threshold - summary.level.threshold;

    return span <= 0
        ? 100
        : Math.min(100, Math.round((100 * (summary.total - summary.level.threshold)) / span));
});

function progressPercent(item: LockedAchievement): number {
    if (item.progress === null || item.progress.threshold === 0) {
        return 0;
    }

    return Math.round((100 * item.progress.value) / item.progress.threshold);
}
</script>

<template>
    <div class="space-y-6">
        <!-- The owner's controls: who sees them and which ones are featured -->
        <div
            v-if="owner"
            class="rounded-lg border border-[var(--color-border)] bg-muted p-3 text-sm sm:p-4"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p id="achievements-visibility-label" class="font-medium text-base-primary">
                        {{ t('achievements.profile.showToOthers') }}
                    </p>
                    <p id="achievements-visibility-help" class="mt-0.5 text-base-secondary">
                        {{
                            hidden
                                ? t('achievements.profile.hiddenHelp')
                                : t('achievements.profile.shownHelp')
                        }}
                    </p>
                </div>
                <!-- 44 px tall hit area around the 24 px track -->
                <button
                    type="button"
                    role="switch"
                    :aria-checked="!hidden"
                    aria-labelledby="achievements-visibility-label"
                    aria-describedby="achievements-visibility-help"
                    :aria-disabled="saving"
                    class="-my-2 flex min-h-[44px] min-w-[44px] shrink-0 items-center justify-center rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 focus-visible:ring-offset-muted"
                    data-test="visibility-switch"
                    @click="toggleHidden"
                >
                    <span
                        class="relative block h-6 w-11 rounded-full border transition-colors duration-200"
                        :class="
                            !hidden
                                ? 'border-primary-600 bg-primary-600 dark:border-primary-500 dark:bg-primary-500'
                                : 'border-neutral-300 bg-neutral-200 dark:border-neutral-600 dark:bg-neutral-700'
                        "
                        aria-hidden="true"
                    >
                        <span
                            class="absolute left-px top-px h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-200 ease-in-out"
                            :class="!hidden ? 'translate-x-5' : 'translate-x-0'"
                        />
                    </span>
                </button>
            </div>
            <p v-if="earned.length > 0" class="mt-3 text-base-secondary">
                {{
                    chosen.length > 0
                        ? t('achievements.profile.featuredChosen', {
                              count: chosen.length,
                              max: maxFeatured,
                          })
                        : t('achievements.profile.featuredAuto', { max: maxFeatured })
                }}
            </p>
            <div
                v-if="owner.leaderboard !== undefined"
                class="mt-3 flex items-start justify-between gap-3 border-t border-[var(--color-border)] pt-3"
            >
                <div class="min-w-0">
                    <p id="achievements-leaderboard-label" class="font-medium text-base-primary">
                        {{ t('achievements.profile.leaderboard') }}
                    </p>
                    <p id="achievements-leaderboard-help" class="mt-0.5 text-base-secondary">
                        {{
                            hidden
                                ? t('achievements.profile.leaderboardHiddenHelp')
                                : t('achievements.profile.leaderboardHelp')
                        }}
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="inLeaderboard"
                    aria-labelledby="achievements-leaderboard-label"
                    aria-describedby="achievements-leaderboard-help"
                    :aria-disabled="saving"
                    class="-my-2 flex min-h-[44px] min-w-[44px] shrink-0 items-center justify-center rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 focus-visible:ring-offset-muted"
                    data-test="leaderboard-switch"
                    @click="toggleLeaderboard"
                >
                    <span
                        class="relative block h-6 w-11 rounded-full border transition-colors duration-200"
                        :class="
                            inLeaderboard
                                ? 'border-primary-600 bg-primary-600 dark:border-primary-500 dark:bg-primary-500'
                                : 'border-neutral-300 bg-neutral-200 dark:border-neutral-600 dark:bg-neutral-700'
                        "
                        aria-hidden="true"
                    >
                        <span
                            class="absolute left-px top-px h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-200 ease-in-out"
                            :class="inLeaderboard ? 'translate-x-5' : 'translate-x-0'"
                        />
                    </span>
                </button>
            </div>
            <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>
            <p v-if="error" class="mt-2 font-medium text-error" role="alert">{{ error }}</p>
        </div>

        <!-- Points and level: totals only, never where they were earned -->
        <div
            v-if="points"
            class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-lg border border-[var(--color-border)] p-3 sm:p-4"
            data-test="points"
        >
            <div>
                <p class="text-2xl font-bold tabular-nums text-base-primary">
                    {{ formatPoints(points.total) }}
                </p>
                <p v-if="points.season !== null" class="text-sm text-base-secondary">
                    {{
                        t('achievements.points.season', {
                            points: formatPoints(points.season),
                            season: points.seasonYear,
                        })
                    }}
                </p>
            </div>
            <div v-if="points.level" class="min-w-0 flex-1">
                <p class="font-medium text-base-primary">
                    {{
                        t('achievements.points.level', {
                            number: points.level.number,
                            name: points.level.name,
                        })
                    }}
                </p>
                <template v-if="points.nextLevel">
                    <div
                        class="mt-2 h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="levelPercent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="
                            t('achievements.points.toNext', { name: points.nextLevel.name })
                        "
                    >
                        <div
                            class="h-full rounded-full bg-primary-600 dark:bg-primary-400"
                            :style="{ width: `${levelPercent}%` }"
                        />
                    </div>
                    <p class="mt-1 text-xs text-base-muted">
                        {{
                            t('achievements.points.missing', {
                                points: formatPoints(points.nextLevel.threshold - points.total),
                                name: points.nextLevel.name,
                            })
                        }}
                    </p>
                </template>
            </div>
            <Link
                v-if="points.leaderboardUrl"
                :href="points.leaderboardUrl"
                class="inline-flex min-h-[44px] items-center text-sm font-medium text-[var(--color-primary-link)] underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            >
                {{ t('achievements.points.seeLeaderboard') }}
            </Link>
        </div>

        <!-- What the owner got -->
        <section v-if="earned.length > 0" aria-labelledby="achievements-earned-heading">
            <h3
                id="achievements-earned-heading"
                class="mb-3 text-sm font-semibold uppercase tracking-wide text-base-muted"
            >
                {{ t('achievements.profile.earned', { count: earned.length }, earned.length) }}
            </h3>
            <ul class="grid gap-3 sm:grid-cols-2" role="list" data-test="earned">
                <li
                    v-for="(item, index) in earned"
                    :key="item.id ?? `masked-${index}`"
                    class="flex min-w-0 gap-3 rounded-lg border border-[var(--color-border)] p-3"
                >
                    <AchievementBadge
                        :icon="item.icon"
                        :colour="item.colour"
                        :image-url="item.imageUrl"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <p class="min-w-0 break-words font-medium text-base-primary">
                                {{ item.name }}
                            </p>
                            <button
                                v-if="owner && item.id !== null"
                                type="button"
                                class="-m-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-base-muted hover:text-primary-600 dark:hover:text-primary-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-50"
                                :aria-pressed="isChosen(item.id)"
                                :aria-label="t('achievements.profile.feature', { name: item.name })"
                                :title="
                                    !isChosen(item.id) && full
                                        ? t('achievements.profile.featuredFull', {
                                              max: maxFeatured,
                                          })
                                        : t('achievements.profile.feature', { name: item.name })
                                "
                                :disabled="saving || (!isChosen(item.id) && full)"
                                data-test="feature"
                                @click="toggleFeatured(item)"
                            >
                                <Star
                                    class="h-5 w-5"
                                    :class="
                                        isChosen(item.id)
                                            ? 'fill-current text-primary-600 dark:text-primary-400'
                                            : ''
                                    "
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                        <p class="mt-0.5 break-words text-sm text-base-secondary">
                            {{ item.description }}
                        </p>
                        <p
                            class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-base-muted"
                        >
                            <time v-if="item.grantedAt" :datetime="item.grantedAt">
                                {{ formatDay(item.grantedAt) }}
                            </time>
                            <time v-else-if="item.grantedMonth" :datetime="item.grantedMonth">
                                {{ formatMonth(item.grantedMonth) }}
                            </time>
                            <span v-if="item.seasons.length > 0" data-test="seasons">
                                {{
                                    t('achievements.profile.seasonsGot', {
                                        seasons: seasonList(item.seasons),
                                    })
                                }}
                            </span>
                            <span v-if="showsRarity && item.rarity !== null">
                                {{ t('achievements.rarity.share', { share: share(item.rarity) }) }}
                            </span>
                            <span v-if="item.points !== undefined && item.points > 0">
                                {{ formatPoints(item.points) }}
                            </span>
                            <span
                                v-if="item.featured"
                                class="rounded-full bg-primary-100 px-2 py-0.5 font-medium text-primary-800 dark:bg-primary-900 dark:text-primary-100"
                            >
                                {{ t('achievements.profile.featured') }}
                            </span>
                        </p>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Secrets this viewer may not see: a number alone, with no date, rarity or place -->
        <p v-if="secretCount > 0" class="text-sm text-base-secondary" data-test="secret-count">
            {{
                earned.length > 0
                    ? t('achievements.profile.secretMore', { count: secretCount }, secretCount)
                    : t('achievements.profile.secretOnly', { count: secretCount }, secretCount)
            }}
        </p>

        <!-- What is left, for the owner -->
        <section v-if="locked.length > 0" aria-labelledby="achievements-locked-heading">
            <h3
                id="achievements-locked-heading"
                class="mb-3 text-sm font-semibold uppercase tracking-wide text-base-muted"
            >
                {{ t('achievements.profile.locked', { count: locked.length }, locked.length) }}
            </h3>
            <ul class="grid gap-3 sm:grid-cols-2" role="list" data-test="locked">
                <li
                    v-for="(item, index) in locked"
                    :key="item.id ?? `masked-${index}`"
                    class="flex min-w-0 gap-3 rounded-lg border border-dashed border-[var(--color-border-strong)] p-3"
                >
                    <AchievementBadge
                        :icon="item.icon"
                        :colour="item.colour"
                        :image-url="item.imageUrl"
                        locked
                    />
                    <div class="min-w-0 flex-1">
                        <p class="break-words font-medium text-base-primary">{{ item.name }}</p>
                        <p class="mt-0.5 break-words text-sm text-base-secondary">
                            {{ item.description }}
                        </p>
                        <p
                            v-if="item.season"
                            class="mt-1 text-xs font-medium text-base-muted"
                            data-test="season"
                        >
                            {{ t('achievements.profile.thisSeason', { season: item.season }) }}
                        </p>
                        <div v-if="item.progress" class="mt-2">
                            <div
                                class="h-2 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                :aria-valuenow="item.progress.value"
                                aria-valuemin="0"
                                :aria-valuemax="item.progress.threshold"
                                :aria-label="item.name"
                                :aria-valuetext="progressText(item.progress)"
                            >
                                <div
                                    class="h-full rounded-full bg-primary-600 dark:bg-primary-400"
                                    :style="{ width: `${progressPercent(item)}%` }"
                                />
                            </div>
                            <p class="mt-1 text-xs text-base-muted" aria-hidden="true">
                                {{ progressText(item.progress) }}
                            </p>
                        </div>
                        <p v-else-if="item.manual" class="mt-1 text-xs text-base-muted">
                            {{ t('achievements.profile.manual') }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>

        <Link
            :href="catalogueUrl"
            class="inline-flex min-h-[44px] items-center text-sm font-medium text-[var(--color-primary-link)] underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
        >
            {{ t('achievements.profile.seeAll') }}
        </Link>
    </div>
</template>
