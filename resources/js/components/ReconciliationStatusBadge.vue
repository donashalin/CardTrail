<script setup lang="ts">
import type { ReconciliationStatus } from '@/types/cardtrail';

defineProps<{ status: ReconciliationStatus }>();

const styles: Record<ReconciliationStatus, string> = {
    in_progress: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
    submitted: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
    approved: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200',
};

const labels: Record<ReconciliationStatus, string> = { in_progress: 'In progress', submitted: 'Submitted', approved: 'Approved' };
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="styles[status]">{{ labels[status] }}</span>
</template>
