<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Star } from '@lucide/vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import AchievementBadge from '../AchievementBadge.vue';
import { formatShare } from '../../utils/badgeLook';
import type { EarnedAchievement, LockedAchievement, ShowcaseOwner } from '../../types/achievements';

/**
 * «Logros» (`achievements.badges`) on a public profile: what the owner got, newest first, with its
 * date and rarity. The owner also sees what is left with their progress, stars up to three to
 * feature them under their name, and may hide them from everybody else. The card and its title are
 * the core's; the server already left out what this viewer may not see.
 */
interface Props {
    earned: EarnedAchievement[];
    locked?: LockedAchievement[];
    showsRarity?: boolean;
    catalogueUrl: string;
    owner?: ShowcaseOwner | null;
}

const props = withDefaults(defineProps<Props>(), {
    locked: () => [],
    showsRarity: false,
    owner: null,
});

const { t, locale } = useI18n();

const chosen = ref<string[]>([...(props.owner?.chosen ?? [])]);
const hidden = ref<boolean>(props.owner?.hidden ?? false);
const saving = ref(false);
const status = ref('');
const error = ref('');

// A save goes back to the profile, which sends the section again
watch(
    () => props.owner,
    (owner) => {
        chosen.value = [...(owner?.chosen ?? [])];
        hidden.value = owner?.hidden ?? false;
    }
);

const maxFeatured = computed(() => props.owner?.maxFeatured ?? 3);
const full = computed(() => chosen.value.length >= maxFeatured.value);

function formatDay(iso: string): string {
    return new Date(iso).toLocaleDateString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function share(rarity: number): string {
    return formatShare(rarity, locale.value);
}

function isChosen(id: string): boolean {
    return chosen.value.includes(id);
}

function save(next: { hidden: boolean; featured: string[] }, done: string): void {
    const owner = props.owner;

    if (owner === null || saving.value) {
        return;
    }

    saving.value = true;
    status.value = '';
    error.value = '';

    router.put(owner.updateUrl, next, {
        preserveScroll: true,
        onSuccess: () => {
            status.value = done;
        },
        onError: (errors: Record<string, string>) => {
            chosen.value = [...owner.chosen];
            hidden.value = owner.hidden;
            error.value = errors.featured ?? errors.hidden ?? t('achievements.profile.saveFailed');
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}

function toggleFeatured(item: EarnedAchievement): void {
    const featured = isChosen(item.id)
        ? chosen.value.filter((id) => id !== item.id)
        : [...chosen.value, item.id];

    if (featured.length > maxFeatured.value) {
        return;
    }

    chosen.value = featured;
    save(
        { hidden: hidden.value, featured },
        isChosen(item.id)
            ? t('achievements.profile.featuredAdded', { name: item.name })
            : t('achievements.profile.featuredRemoved', { name: item.name })
    );
}

function toggleHidden(): void {
    hidden.value = !hidden.value;
    save(
        { hidden: hidden.value, featured: chosen.value },
        hidden.value ? t('achievements.profile.nowHidden') : t('achievements.profile.nowShown')
    );
}

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
            <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>
            <p v-if="error" class="mt-2 font-medium text-error" role="alert">{{ error }}</p>
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
                    v-for="item in earned"
                    :key="item.id"
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
                                v-if="owner && !item.masked"
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
                            <span v-if="showsRarity && item.rarity !== null">
                                {{ t('achievements.rarity.share', { share: share(item.rarity) }) }}
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
                    v-for="item in locked"
                    :key="item.id"
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
                        <div v-if="item.progress" class="mt-2">
                            <div
                                class="h-2 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                :aria-valuenow="item.progress.value"
                                aria-valuemin="0"
                                :aria-valuemax="item.progress.threshold"
                                :aria-label="item.name"
                                :aria-valuetext="
                                    t('achievements.profile.progress', {
                                        value: item.progress.value,
                                        threshold: item.progress.threshold,
                                    })
                                "
                            >
                                <div
                                    class="h-full rounded-full bg-primary-600 dark:bg-primary-400"
                                    :style="{ width: `${progressPercent(item)}%` }"
                                />
                            </div>
                            <p class="mt-1 text-xs text-base-muted" aria-hidden="true">
                                {{
                                    t('achievements.profile.progress', {
                                        value: item.progress.value,
                                        threshold: item.progress.threshold,
                                    })
                                }}
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
