<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Check } from '@lucide/vue';
import HelpLink from '@/components/knowledge/HelpLink.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AchievementBadge from '../../components/AchievementBadge.vue';
import { formatShare } from '../../utils/badgeLook';
import type { CatalogueAchievement } from '../../types/achievements';

/**
 * `/logros`: every achievement that can be got, with the share of active people holding it. A secret
 * one the viewer does not hold shows as «Logro secreto», with no rarity. Nothing says who holds what.
 */
interface Props {
    achievements: CatalogueAchievement[];
    showsRarity?: boolean;
    signedIn?: boolean;
}

const props = withDefaults(defineProps<Props>(), { showsRarity: false, signedIn: false });

const { t, locale } = useI18n();

const heldCount = computed(() => props.achievements.filter((item) => item.held).length);
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
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" role="list" data-test="catalogue">
                <li
                    v-for="item in achievements"
                    :key="item.id"
                    class="flex min-w-0 gap-4 rounded-lg bg-surface p-4 shadow dark:shadow-depth"
                    :class="item.held ? 'ring-2 ring-primary-500/60' : ''"
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
                            <span
                                v-if="item.held"
                                class="inline-flex items-center gap-1 rounded-full bg-primary-100 px-2 py-0.5 font-medium text-primary-800 dark:bg-primary-900 dark:text-primary-100"
                            >
                                <Check class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ t('achievements.catalogue.youHoldIt') }}
                            </span>
                        </p>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>
