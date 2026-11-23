<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import HelpLink from '@/components/knowledge/HelpLink.vue';
import type { AccountAchievements } from '../../types/achievements';

/**
 * «Logros» in the account settings (`/perfil?tab=achievements`): whether others hear of the owner's
 * rare achievements (only while announcements are on) and whether they appear in the leaderboard
 * (always, so anybody can leave it before it is switched on). Each switch saves at once.
 */
type Choice = 'announceRare' | 'inLeaderboard';

const props = defineProps<{
    /** Null when the tab is hidden */
    achievementsAccount: AccountAchievements | null;
    /** The tab's visibility, read by the core: declared so it stays off the markup */
    achievementsAccountVisible?: boolean | null;
}>();

const { t, locale } = useI18n();

const account = computed<AccountAchievements | null>(() => props.achievementsAccount);
const values = ref<Record<Choice, boolean>>({
    announceRare: account.value?.announceRare ?? false,
    inLeaderboard: account.value?.inLeaderboard ?? true,
});
const saving = ref(false);
const status = ref('');
const error = ref('');

// A save goes back to the tab, which sends the choices again
watch(account, (next) => {
    values.value = {
        announceRare: next?.announceRare ?? false,
        inLeaderboard: next?.inLeaderboard ?? true,
    };
});

const threshold = computed(() =>
    new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(
        account.value?.threshold ?? 10
    )
);

/** What the server answered instead of the tab: shown, and the switch goes back */
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

function toggle(choice: Choice): void {
    const current = account.value;

    if (current === null || saving.value) {
        return;
    }

    const value = !values.value[choice];
    values.value = { ...values.value, [choice]: value };
    saving.value = true;
    status.value = '';
    error.value = '';
    let saved = false;

    router.put(
        current.updateUrl,
        { [choice]: value },
        {
            preserveScroll: true,
            onSuccess: () => {
                saved = true;
                status.value = t(`achievements.account.${choice}.${value ? 'on' : 'off'}`);
            },
            // A refusal the server explains (announcements switched off meanwhile) is shown as it is
            onError: (errors: Record<string, string>) => {
                error.value = errors[choice] ?? t('achievements.profile.saveFailed');
            },
            // A refusal (read-only impersonation, too many requests) is told here, not in Inertia's dialog
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

                // Whatever went wrong, the switch shows what is saved
                if (!saved) {
                    values.value = {
                        announceRare: current.announceRare,
                        inLeaderboard: current.inLeaderboard,
                    };
                    error.value ||= t('achievements.profile.saveFailed');
                }
            },
        }
    );
}

// Announcing only while announcements are on; the leaderboard always, to leave it beforehand
const choices = computed<Choice[]>(() => {
    const current = account.value;

    if (current === null) {
        return [];
    }

    return [...(current.offersAnnouncements ? (['announceRare'] as const) : []), 'inLeaderboard'];
});

function help(choice: Choice): string {
    if (choice === 'announceRare') {
        return t('achievements.account.announceRare.help', { threshold: threshold.value });
    }

    return account.value?.leaderboardOn === true
        ? t('achievements.account.inLeaderboard.help')
        : t('achievements.account.inLeaderboard.helpOff');
}
</script>

<template>
    <section v-if="account" class="space-y-6" data-test="achievements-account">
        <div class="flex items-center gap-2">
            <h2 class="text-xl font-semibold text-base-primary">
                {{ t('achievements.account.title') }}
            </h2>
            <HelpLink guide="achievements.site.achievements" />
        </div>

        <ul class="space-y-3" role="list">
            <li
                v-for="choice in choices"
                :key="choice"
                class="rounded-lg border border-[var(--color-border)] bg-surface p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p
                            :id="`achievements-${choice}-label`"
                            class="font-medium text-base-primary"
                        >
                            {{ t(`achievements.account.${choice}.label`) }}
                        </p>
                        <p
                            :id="`achievements-${choice}-help`"
                            class="mt-0.5 text-sm text-base-secondary"
                        >
                            {{ help(choice) }}
                        </p>
                        <p
                            v-if="choice === 'announceRare' && account.hidden"
                            class="mt-2 text-sm font-medium text-base-primary"
                            data-test="hidden-note"
                        >
                            {{ t('achievements.account.announceRare.hidden') }}
                        </p>
                    </div>
                    <!-- 44 px tall hit area around the 24 px track -->
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="values[choice]"
                        :aria-labelledby="`achievements-${choice}-label`"
                        :aria-describedby="`achievements-${choice}-help`"
                        :aria-disabled="saving"
                        class="-my-2 flex min-h-[44px] min-w-[44px] shrink-0 items-center justify-center rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                        :data-test="`switch-${choice}`"
                        @click="toggle(choice)"
                    >
                        <span
                            class="relative block h-6 w-11 rounded-full border transition-colors duration-200"
                            :class="
                                values[choice]
                                    ? 'border-primary-600 bg-primary-600 dark:border-primary-500 dark:bg-primary-500'
                                    : 'border-neutral-300 bg-neutral-200 dark:border-neutral-600 dark:bg-neutral-700'
                            "
                            aria-hidden="true"
                        >
                            <span
                                class="absolute left-px top-px h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-200 ease-in-out"
                                :class="values[choice] ? 'translate-x-5' : 'translate-x-0'"
                            />
                        </span>
                    </button>
                </div>
            </li>
        </ul>

        <p class="text-sm text-base-secondary">
            {{ t('achievements.account.notices') }}
            <Link
                :href="account.noticesUrl"
                class="rounded-sm font-medium text-[var(--color-primary-link)] underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                data-test="notices-link"
            >
                {{ t('achievements.account.noticesLink') }}
            </Link>
        </p>

        <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>
        <p v-if="error" class="font-medium text-error" role="alert">{{ error }}</p>
    </section>
</template>
