<script setup lang="ts">
import type { LineStatus } from '@/types/cardtrail';

defineProps<{ status: LineStatus }>();

const styles: Record<LineStatus, string> = {
    todo: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
    done: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200',
    queried: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    approved: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
};

const labels: Record<LineStatus, string> = { todo: 'To do', done: 'Done', queried: 'Queried', approved: 'Approved' };
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="styles[status]">{{ labels[status] }}</span>
</template>
