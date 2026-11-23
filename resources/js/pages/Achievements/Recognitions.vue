<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import HelpLink from '@/components/knowledge/HelpLink.vue';
import AchievementBadge from '../../components/AchievementBadge.vue';
import RemoveRecognitionDialog from '../../components/RemoveRecognitionDialog.vue';
import { formatChallengeDay } from '../../utils/challengeDays';
import { formatRecognitionMonth } from '../../utils/recognitionDates';
import type {
    OwnRecognition,
    RecognisablePerson,
    RecognitionSubject,
    RecognitionType,
} from '../../types/achievements';

/**
 * `/logros/reconocimientos` (#11): the viewer's own page. The people offered are only those the
 * server allowed (same game, profile the viewer may see, public name, not hiding their
 * achievements); what was received shows who gave it but never which game; what was given is
 * the viewer's alone.
 */
interface Props {
    types: RecognitionType[];
    subjects: RecognitionSubject[];
    received: OwnRecognition[];
    given: OwnRecognition[];
    windowDays: number;
    storeUrl: string;
}

const props = defineProps<Props>();

const { t, locale } = useI18n();

const saving = ref<string | null>(null);
const error = ref<string | null>(null);
/** The received recognition the dialog asks about, and the button that opened it (focus goes back there) */
const removing = ref<OwnRecognition | null>(null);
let opener: HTMLElement | null = null;
/** Where that recognition was in the list: once it is gone, focus goes to the row now in its place */
let removingIndex = 0;
const receivedSection = ref<HTMLElement | null>(null);
const receivedHeading = ref<HTMLElement | null>(null);

function key(subject: RecognitionSubject, person: RecognisablePerson): string {
    return `${subject.sourceType}|${subject.subjectId}|${person.handle}`;
}

/**
 * «Quitar «Gran narración» de Ana». Without a public name, the month tells two rows apart, in a
 * phrase of its own (never a capitalised name in the middle of the sentence).
 */
function removeLabel(item: OwnRecognition): string {
    return item.personName === null
        ? t('achievements.recognitions.removeLabelNameless', {
              type: item.type.name,
              month: formatRecognitionMonth(item.month, locale.value),
          })
        : t('achievements.recognitions.removeLabel', {
              type: item.type.name,
              name: item.personName,
          });
}

function typeName(id: string | null): string {
    return props.types.find((type) => type.id === id)?.name ?? '';
}

function refusal(response: { status?: number; data?: unknown } | undefined): string {
    const data = response?.data;

    if (
        response?.status === 403 &&
        typeof data === 'object' &&
        data !== null &&
        (data as Record<string, unknown>).error === 'impersonation_read_only'
    ) {
        return t('achievements.recognitions.readOnly');
    }

    return response?.status === 429
        ? t('achievements.recognitions.tooMany')
        : t('achievements.recognitions.saveFailed');
}

function give(
    subject: RecognitionSubject,
    person: RecognisablePerson,
    type: RecognitionType
): void {
    if (saving.value !== null) {
        return;
    }

    saving.value = key(subject, person);
    error.value = null;

    router.post(
        props.storeUrl,
        {
            sourceType: subject.sourceType,
            subjectId: subject.subjectId,
            receiver: person.handle,
            type: type.id,
        },
        {
            preserveScroll: true,
            onHttpException: (response: { status?: number; data?: unknown }) => {
                error.value = refusal(response);

                return false;
            },
            onNetworkError: () => {
                error.value = t('achievements.recognitions.saveFailed');

                return false;
            },
            onFinish: () => {
                saving.value = null;
            },
        }
    );
}

function askToRemove(recognition: OwnRecognition, event: Event): void {
    if (saving.value !== null) {
        return;
    }

    opener = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    removingIndex = Math.max(
        0,
        props.received.findIndex((item) => item.id === recognition.id)
    );
    removing.value = recognition;
}

/**
 * Closing without removing puts focus back on the button that opened it. Once removed, that row is
 * gone: focus goes to «Quitar» of the row now in its place, else the one before, else the heading.
 */
async function closeDialog(removed = false): Promise<void> {
    removing.value = null;
    await nextTick();

    if (removed) {
        const buttons = Array.from(
            receivedSection.value?.querySelectorAll<HTMLElement>('[data-test="remove"]') ?? []
        );
        const target =
            buttons[Math.min(removingIndex, buttons.length - 1)] ?? receivedHeading.value;
        target?.focus();
    } else {
        opener?.focus();
    }

    opener = null;
}

function remove(): void {
    const recognition = removing.value;

    if (recognition === null || saving.value !== null) {
        return;
    }

    saving.value = recognition.id;
    error.value = null;
    let removed = false;

    router.delete(`${props.storeUrl}/${encodeURIComponent(recognition.id)}`, {
        preserveScroll: true,
        onSuccess: () => {
            removed = true;
        },
        onHttpException: (response: { status?: number; data?: unknown }) => {
            error.value = refusal(response);

            return false;
        },
        onNetworkError: () => {
            error.value = t('achievements.recognitions.saveFailed');

            return false;
        },
        onFinish: () => {
            saving.value = null;
            void closeDialog(removed);
        },
    });
}
</script>

<template>
    <div class="bg-surface shadow dark:shadow-depth">
        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold tracking-tight text-base-primary">
                {{ t('achievements.recognitions.title') }}
                <HelpLink guide="achievements.site.recognitions" class="ml-1 align-middle" />
            </h1>
            <p class="mt-2 max-w-3xl text-lg text-base-secondary">
                {{ t('achievements.recognitions.subtitle') }}
            </p>
            <p class="mt-2 max-w-3xl text-sm text-base-secondary" data-test="privacy">
                {{ t('achievements.recognitions.privacy') }}
            </p>
        </div>
    </div>

    <div class="mx-auto max-w-4xl space-y-10 px-4 py-8 sm:px-6 lg:px-8">
        <p
            v-if="error"
            role="alert"
            class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
            data-test="error"
        >
            {{ error }}
        </p>

        <section aria-labelledby="recognitions-give" data-test="give">
            <h2 id="recognitions-give" class="text-xl font-bold text-base-primary">
                {{ t('achievements.recognitions.giveTitle') }}
            </h2>
            <p v-if="subjects.length === 0" class="mt-3 text-base-secondary" data-test="give-empty">
                {{ t('achievements.recognitions.giveEmpty', { days: windowDays }) }}
            </p>
            <ul v-else class="mt-4 space-y-6">
                <li
                    v-for="subject in subjects"
                    :key="`${subject.sourceType}|${subject.subjectId}`"
                    class="rounded-lg border border-default bg-surface p-4"
                    data-test="subject"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-lg font-semibold text-base-primary">
                            <Link
                                v-if="subject.url"
                                :href="subject.url"
                                class="text-[var(--color-primary-link)] underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                            >
                                {{ subject.title }}
                            </Link>
                            <template v-else>{{ subject.title }}</template>
                        </h3>
                        <span class="text-sm text-base-secondary">
                            {{
                                t('achievements.recognitions.closesOn', {
                                    date: formatChallengeDay(subject.closesOn, locale),
                                })
                            }}
                        </span>
                    </div>
                    <ul class="mt-3 divide-y divide-[var(--color-border-default)]">
                        <li
                            v-for="person in subject.people"
                            :key="person.handle"
                            class="py-3"
                            data-test="person"
                        >
                            <div class="flex items-center gap-3">
                                <img
                                    v-if="person.avatarUrl"
                                    :src="person.avatarUrl"
                                    alt=""
                                    class="h-8 w-8 rounded-full object-cover"
                                    loading="lazy"
                                />
                                <Link
                                    :href="person.profileUrl"
                                    class="font-medium text-base-primary underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                >
                                    {{ person.publicName }}
                                </Link>
                            </div>
                            <p
                                v-if="person.givenTypeId"
                                class="mt-2 text-sm text-base-secondary"
                                data-test="given"
                            >
                                {{
                                    t('achievements.recognitions.given', {
                                        type: typeName(person.givenTypeId),
                                    })
                                }}
                            </p>
                            <div
                                v-else
                                class="mt-2 flex flex-wrap gap-2"
                                role="group"
                                :aria-label="
                                    t('achievements.recognitions.choose', {
                                        name: person.publicName,
                                    })
                                "
                            >
                                <button
                                    v-for="type in types"
                                    :key="type.id"
                                    type="button"
                                    class="inline-flex min-h-[44px] items-center gap-2 rounded-full border border-default px-3 text-sm font-medium text-base-primary hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50"
                                    :disabled="saving !== null"
                                    :aria-busy="saving === key(subject, person)"
                                    data-test="type"
                                    @click="give(subject, person, type)"
                                >
                                    {{ type.name }}
                                </button>
                            </div>
                        </li>
                    </ul>
                </li>
            </ul>
        </section>

        <section ref="receivedSection" aria-labelledby="recognitions-received" data-test="received">
            <h2
                id="recognitions-received"
                ref="receivedHeading"
                tabindex="-1"
                class="text-xl font-bold text-base-primary focus:outline-none"
            >
                {{ t('achievements.recognitions.receivedTitle') }}
            </h2>
            <p v-if="received.length === 0" class="mt-3 text-base-secondary">
                {{ t('achievements.recognitions.receivedEmpty') }}
            </p>
            <ul v-else class="mt-4 divide-y divide-[var(--color-border-default)]">
                <li
                    v-for="item in received"
                    :key="item.id"
                    class="flex flex-wrap items-center gap-3 py-3"
                    data-test="received-item"
                >
                    <AchievementBadge :icon="item.type.icon" :colour="item.type.colour" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-base-primary">{{ item.type.name }}</p>
                        <p class="text-sm text-base-secondary">
                            <Link
                                v-if="item.personUrl && item.personName"
                                :href="item.personUrl"
                                class="underline-offset-2 hover:underline"
                            >
                                {{ t('achievements.recognitions.from', { name: item.personName }) }}
                            </Link>
                            <template v-else>
                                {{
                                    t('achievements.recognitions.from', {
                                        name:
                                            item.personName ??
                                            t('achievements.recognitions.someone'),
                                    })
                                }}
                            </template>
                            · {{ formatRecognitionMonth(item.month, locale) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm font-medium text-base-secondary hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50"
                        :disabled="saving !== null"
                        :aria-label="removeLabel(item)"
                        data-test="remove"
                        @click="askToRemove(item, $event)"
                    >
                        {{ t('achievements.recognitions.remove') }}
                    </button>
                </li>
            </ul>
        </section>

        <section aria-labelledby="recognitions-given" data-test="given-list">
            <h2 id="recognitions-given" class="text-xl font-bold text-base-primary">
                {{ t('achievements.recognitions.givenTitle') }}
            </h2>
            <p v-if="given.length === 0" class="mt-3 text-base-secondary">
                {{ t('achievements.recognitions.givenEmpty') }}
            </p>
            <ul v-else class="mt-4 divide-y divide-[var(--color-border-default)]">
                <li
                    v-for="item in given"
                    :key="item.id"
                    class="flex items-center gap-3 py-3"
                    data-test="given-item"
                >
                    <AchievementBadge :icon="item.type.icon" :colour="item.type.colour" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-base-primary">{{ item.type.name }}</p>
                        <p class="text-sm text-base-secondary">
                            {{
                                t('achievements.recognitions.to', {
                                    name: item.personName ?? t('achievements.recognitions.someone'),
                                })
                            }}
                            · {{ formatRecognitionMonth(item.month, locale) }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <RemoveRecognitionDialog
        :open="removing !== null"
        :type-name="removing?.type.name ?? ''"
        :busy="saving !== null"
        @confirm="remove"
        @cancel="closeDialog"
    />
</template>
