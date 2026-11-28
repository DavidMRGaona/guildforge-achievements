<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Users } from '@lucide/vue';
import AchievementBadge from './AchievementBadge.vue';
import ChallengeProgressBar from './ChallengeProgressBar.vue';
import { formatChallengeDay } from '../utils/challengeDays';
import type { Challenge } from '../types/achievements';

/**
 * A collective challenge on `/logros`: the goal between everybody, how far it is, its days and its
 * reward. Only totals; the people who completed it are some of those whose profile the viewer may
 * see, by public name. How many got the reward is never told next to how many added: together they
 * would tell a reward was taken away.
 */
interface Props {
    challenge: Challenge;
}

const props = defineProps<Props>();

const { t, n, locale } = useI18n();

const days = computed(() => {
    // The year when the challenge is not of the current one
    const withYear = props.challenge.showsYear;

    return t('achievements.challenges.days', {
        start: formatChallengeDay(props.challenge.startsOn, locale.value, withYear),
        end: formatChallengeDay(props.challenge.endsOn, locale.value, withYear),
    });
});

const stateClass = computed(() => {
    switch (props.challenge.state) {
        case 'completed':
            return 'bg-success-bg text-base-primary';
        case 'running':
            return 'bg-(--color-primary-100) text-(--color-primary-800) dark:bg-(--color-primary-900) dark:text-(--color-primary-100)';
        default:
            return 'bg-muted text-base-secondary';
    }
});
</script>

<template>
    <article
        class="flex min-w-0 flex-col gap-4 rounded-lg bg-surface p-4 shadow dark:shadow-depth sm:p-5"
        :aria-labelledby="`challenge-${challenge.id}`"
        data-test="challenge"
    >
        <header class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h3
                    :id="`challenge-${challenge.id}`"
                    class="break-words text-lg font-semibold text-base-primary"
                >
                    {{ challenge.name }}
                </h3>
                <p class="mt-0.5 text-sm text-base-muted">
                    {{ days }}
                    <span v-if="challenge.membersOnly">
                        · {{ t('achievements.challenges.membersOnly') }}</span
                    >
                </p>
            </div>
            <span
                class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                :class="stateClass"
            >
                {{ t(`achievements.challenges.states.${challenge.state}`) }}
            </span>
        </header>

        <p v-if="challenge.description" class="break-words text-sm text-base-secondary">
            {{ challenge.description }}
        </p>

        <ChallengeProgressBar
            v-if="challenge.counted"
            :progress="challenge.progress"
            :target="challenge.target"
            :unit="challenge.unit"
        />
        <p v-else class="text-sm text-base-muted" data-test="challenge-not-counted">
            {{
                t('achievements.challenges.notCounted', {
                    target: n(challenge.target),
                    unit: challenge.unit,
                })
            }}
        </p>

        <p
            v-if="challenge.counted"
            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-base-secondary"
        >
            <span class="inline-flex items-center gap-1.5">
                <Users class="h-4 w-4" aria-hidden="true" />
                {{
                    t(
                        'achievements.challenges.contributors',
                        { count: n(challenge.contributors) },
                        challenge.contributors
                    )
                }}
            </span>
            <span v-if="challenge.contribution !== null" data-test="challenge-contribution">
                {{
                    challenge.contribution > 0
                        ? t(
                              'achievements.challenges.yourContribution',
                              { count: n(challenge.contribution) },
                              challenge.contribution
                          )
                        : t('achievements.challenges.noContribution')
                }}
            </span>
        </p>

        <div class="flex min-w-0 items-center gap-3 rounded-md bg-muted p-3">
            <AchievementBadge
                :icon="challenge.reward.icon"
                :colour="challenge.reward.colour"
                :image-url="challenge.reward.imageUrl"
            />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-base-muted">
                    {{ t('achievements.challenges.reward') }}
                </p>
                <p class="break-words text-sm font-semibold text-base-primary">
                    {{ challenge.reward.name }}
                </p>
            </div>
        </div>

        <div
            v-if="challenge.state === 'completed' && challenge.people.length > 0"
            data-test="challenge-completers"
        >
            <p class="text-sm text-base-secondary">
                {{ t('achievements.challenges.peopleTitle') }}
            </p>
            <ul class="mt-2 flex flex-wrap gap-2" role="list">
                <li v-for="person in challenge.people" :key="person.url">
                    <a
                        :href="person.url"
                        class="inline-flex max-w-full items-center gap-2 rounded-full bg-muted py-1 pl-1 pr-3 text-sm text-base-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                    >
                        <img
                            v-if="person.avatarUrl"
                            :src="person.avatarUrl"
                            alt=""
                            class="h-6 w-6 shrink-0 rounded-full object-cover"
                            loading="lazy"
                            decoding="async"
                        />
                        <span
                            v-else
                            class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-(--color-primary-100) text-xs font-semibold text-(--color-primary-800) dark:bg-(--color-primary-900) dark:text-(--color-primary-100)"
                            aria-hidden="true"
                        >
                            {{ person.name.charAt(0).toUpperCase() }}
                        </span>
                        <span class="truncate">{{ person.name }}</span>
                    </a>
                </li>
            </ul>
        </div>
    </article>
</template>
