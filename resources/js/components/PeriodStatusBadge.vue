<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: 'draft' | 'published' | 'closed' }>();

const styles = {
    draft: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    published: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
    closed: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
};

const labels = { draft: 'Draft', published: 'Published', closed: 'Closed' };

const classes = computed(() => styles[props.status]);
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="classes">{{ labels[status] }}</span>
</template>
