<script setup lang="ts">
import { computed } from 'vue';
import { badgeColour, badgeIcon } from '../utils/badgeLook';

/**
 * The round look of an achievement: its image when it has one, else its icon on its colour. Purely
 * visual: the name next to it says what it is. `locked` dims what is not held yet.
 */
interface Props {
    icon: string;
    colour: string;
    imageUrl?: string | null;
    locked?: boolean;
    size?: 'md' | 'lg';
}

const props = withDefaults(defineProps<Props>(), { imageUrl: null, locked: false, size: 'md' });

const icon = computed(() => badgeIcon(props.icon));
const box = computed(() => (props.size === 'lg' ? 'h-14 w-14' : 'h-12 w-12'));
</script>

<template>
    <span
        class="relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full"
        :class="[
            box,
            imageUrl ? 'bg-muted' : badgeColour(colour),
            locked ? 'opacity-50 grayscale' : '',
        ]"
        aria-hidden="true"
    >
        <img
            v-if="imageUrl"
            :src="imageUrl"
            alt=""
            class="h-full w-full object-cover"
            loading="lazy"
            decoding="async"
        />
        <component
            :is="icon"
            v-else
            :class="size === 'lg' ? 'h-7 w-7' : 'h-6 w-6'"
            :stroke-width="2"
        />
    </span>
</template>
