<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import LineStatusBadge from '@/components/LineStatusBadge.vue';
import ProgressBar from '@/components/ProgressBar.vue';
import ReconciliationStatusBadge from '@/components/ReconciliationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import type { LineStatus, ReconciliationSummary } from '@/types/cardtrail';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Line {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    status: LineStatus;
    purpose: string | null;
    receipts: number;
    no_receipt: boolean;
}

type Tab = 'todo' | 'done' | 'queried';

const props = defineProps<{
    reconciliation: ReconciliationSummary & { owner: string; mine: boolean; can_submit: boolean };
    lines: Line[];
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard' },
    { title: props.reconciliation.period.label, href: '#' },
]);

const groups = computed<Record<Tab, Line[]>>(() => ({
    todo: props.lines.filter((line) => line.status === 'todo'),
    queried: props.lines.filter((line) => line.status === 'queried'),
    done: props.lines.filter((line) => line.status === 'done' || line.status === 'approved'),
}));

const tab = ref<Tab>(groups.value.queried.length ? 'queried' : groups.value.todo.length ? 'todo' : 'done');
const tabs: { key: Tab; label: string }[] = [
    { key: 'todo', label: 'To do' },
    { key: 'done', label: 'Done' },
    { key: 'queried', label: 'Queried' },
];

const remaining = computed(() => groups.value.todo.length + groups.value.queried.length);
const canWork = computed(
    () => props.reconciliation.mine && props.reconciliation.period.status === 'published' && props.reconciliation.status === 'in_progress',
);

const submitting = ref(false);
const submit = () => {
    if (!confirm('Submit this month to accounts? You won’t be able to change lines afterwards unless accounts query them.')) {
        return;
    }

    router.post(route('reconciliations.submit', props.reconciliation.id), {}, {
        preserveScroll: true,
        onStart: () => (submitting.value = true),
        onFinish: () => (submitting.value = false),
    });
};

const hint = (line: Line) => {
    if (!line.purpose) {
        return 'Needs a purpose';
    }

    if (line.amount < 0) {
        return 'Credit';
    }

    if (line.receipts) {
        return line.receipts === 1 ? '1 receipt' : `${line.receipts} receipts`;
    }

    return line.no_receipt ? 'No receipt' : 'Needs a receipt';
};
</script>

<template>
    <Head :title="reconciliation.period.label" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex items-start justify-between gap-2">
                <div>
                    <h1 class="text-xl font-semibold">{{ reconciliation.period.label }}</h1>
                    <p class="text-sm text-muted-foreground">
                        <span v-if="!reconciliation.mine">{{ reconciliation.owner }} · </span>
                        Due {{ formatDate(reconciliation.period.due_date) }}
                        <span v-if="reconciliation.overdue" class="font-medium text-red-600 dark:text-red-400"> · Overdue</span>
                    </p>
                </div>
                <ReconciliationStatusBadge :status="reconciliation.status" />
            </div>

            <div>
                <ProgressBar :value="reconciliation.done" :max="reconciliation.lines" />
                <p class="mt-1 text-sm text-muted-foreground">{{ reconciliation.done }} of {{ reconciliation.lines }} lines done</p>
            </div>

            <p v-if="reconciliation.period.status === 'closed'" class="rounded-md border bg-muted px-4 py-3 text-sm">
                This month is closed. You can look, but nothing can be changed.
            </p>
            <p v-else-if="reconciliation.status === 'submitted'" class="rounded-md border bg-muted px-4 py-3 text-sm">
                Submitted {{ formatDate(reconciliation.submitted_at) }}. Accounts will review it and let you know if they have questions.
            </p>
            <p v-else-if="reconciliation.status === 'approved'" class="rounded-md border bg-muted px-4 py-3 text-sm">Approved by accounts. Nothing more to do.</p>

            <div class="flex gap-1 rounded-lg bg-muted p-1" role="tablist">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === item.key"
                    class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium"
                    :class="tab === item.key ? 'bg-background shadow-sm' : 'text-muted-foreground'"
                    @click="tab = item.key"
                >
                    {{ item.label }} ({{ groups[item.key].length }})
                </button>
            </div>

            <ul class="divide-y rounded-lg border">
                <li v-for="line in groups[tab]" :key="line.id">
                    <Link :href="route('transactions.show', line.id)" class="flex items-start gap-3 p-4 text-sm hover:bg-accent">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ line.description }}</div>
                            <div class="text-muted-foreground">Row {{ line.row_number }} · {{ formatDate(line.date) }} · {{ hint(line) }}</div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="font-medium tabular-nums">{{ formatPence(line.amount) }}</div>
                            <LineStatusBadge :status="line.status" />
                        </div>
                    </Link>
                </li>
                <li v-if="!groups[tab].length" class="p-4 text-sm text-muted-foreground">Nothing here.</li>
            </ul>

            <div v-if="canWork" class="sticky bottom-0 -mx-4 border-t bg-background/95 p-4 backdrop-blur">
                <Button class="w-full" :disabled="!reconciliation.can_submit || submitting" @click="submit">
                    {{ reconciliation.can_submit ? 'Submit to accounts' : `${remaining} ${remaining === 1 ? 'line' : 'lines'} left to finish` }}
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
