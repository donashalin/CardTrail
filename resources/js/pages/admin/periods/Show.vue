<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import PeriodStatusBadge from '@/components/PeriodStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';

interface DashboardPeriod {
    id: number;
    label: string;
    month: string;
    due_date: string;
    status: 'draft' | 'published' | 'closed';
    published_at: string | null;
}

interface DashboardReconciliation {
    id: number;
    user: { id: number; name: string; active: boolean };
    status: 'in_progress' | 'submitted' | 'approved';
    lines: number;
    done: number;
    queried: number;
    overdue: boolean;
}

interface DashboardImport {
    id: number;
    original_filename: string;
    status: 'draft' | 'confirmed';
    row_count: number;
    created_at: string;
}

const props = defineProps<{
    period: DashboardPeriod;
    reconciliations: DashboardReconciliation[];
    imports: DashboardImport[];
    unassigned_count: number;
    totals: { lines: number; amount: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.period.label, href: `/admin/periods/${props.period.id}` },
];

const removeImport = (upload: DashboardImport) => {
    if (window.confirm(`Remove ${upload.original_filename}? Any lines it created will be deleted.`)) {
        router.delete(route('admin.imports.destroy', upload.id), { preserveScroll: true });
    }
};

const statusLabel = (row: DashboardReconciliation) => {
    if (row.status === 'submitted') return 'Submitted';
    if (row.status === 'approved') return 'Approved';
    return row.done === 0 && row.queried === 0 ? 'Not started' : 'In progress';
};
</script>

<template>
    <Head :title="period.label" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <FlashMessage />

            <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold">{{ period.label }}</h1>
                        <PeriodStatusBadge :status="period.status" />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Due {{ formatDate(period.due_date) }} · {{ totals.lines }} lines · {{ formatPence(totals.amount) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2" data-actions>
                    <Button v-if="period.status !== 'closed'" as-child>
                        <Link :href="route('admin.imports.create', period.id)">Upload statement</Link>
                    </Button>
                    <Button v-if="totals.lines > 0" variant="outline" as-child>
                        <Link :href="route('admin.periods.lines', period.id)">Lines</Link>
                    </Button>
                    <Button v-if="period.status !== 'closed'" variant="outline" as-child>
                        <Link :href="route('admin.periods.edit', period.id)">Edit</Link>
                    </Button>
                </div>
            </header>

            <section v-if="unassigned_count > 0" data-unassigned class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                {{ unassigned_count }} {{ unassigned_count === 1 ? 'line is' : 'lines are' }} not matched to anyone yet.
                <Link :href="route('admin.periods.lines', period.id)" class="ml-1 font-medium underline underline-offset-4">Assign them</Link>
            </section>

            <section class="flex flex-col gap-2">
                <h2 class="font-medium">Cardholders</h2>

                <ul class="divide-y rounded-lg border">
                    <li v-for="row in reconciliations" :key="row.id" class="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="font-medium">
                                {{ row.user.name }}
                                <span v-if="!row.user.active" class="ml-1 text-xs font-normal text-red-600">Inactive: reassign their lines</span>
                            </div>
                            <div class="text-sm text-muted-foreground">
                                {{ row.done }} of {{ row.lines }} done<span v-if="row.queried"> · {{ row.queried }} queried</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span v-if="row.overdue" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-200">Overdue</span>
                            <span>{{ statusLabel(row) }}</span>
                        </div>
                    </li>
                    <li v-if="!reconciliations.length" class="p-4 text-sm text-muted-foreground">
                        No lines assigned yet. Upload the statement to get started.
                    </li>
                </ul>
            </section>

            <section data-imports class="flex flex-col gap-2">
                <h2 class="font-medium">Uploaded files</h2>

                <ul class="divide-y rounded-lg border">
                    <li v-for="upload in imports" :key="upload.id" class="flex items-center justify-between gap-3 p-4 text-sm">
                        <div>
                            <div class="font-medium">{{ upload.original_filename }}</div>
                            <div class="text-muted-foreground">
                                {{ upload.status === 'confirmed' ? `${upload.row_count} lines imported` : 'Not finished' }} · {{ formatDate(upload.created_at) }}
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <Button v-if="upload.status === 'draft'" size="sm" variant="outline" as-child>
                                <Link :href="route('admin.imports.preview', upload.id)">Continue</Link>
                            </Button>
                            <Button v-if="upload.status === 'draft' || period.status === 'draft'" size="sm" variant="ghost" @click="removeImport(upload)">
                                Remove
                            </Button>
                        </div>
                    </li>
                    <li v-if="!imports.length" class="p-4 text-sm text-muted-foreground">No files uploaded yet.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
