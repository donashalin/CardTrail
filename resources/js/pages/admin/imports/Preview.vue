<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

type FixField = 'date' | 'amount' | 'description';

interface PreviewRow {
    row_number: number;
    date: string | null;
    raw_date: string;
    description: string;
    amount: number | null;
    raw_amount: string;
    card_ref: string | null;
    user_id: number | null;
    user_name: string | null;
    duplicate: boolean;
    errors: Partial<Record<FixField, string>>;
    extra: Record<string, string>;
}

const props = defineProps<{
    upload: { id: number; original_filename: string; period: { id: number; label: string; status: string } };
    rows: PreviewRow[];
    summary: {
        total: number;
        errors: number;
        duplicates: number;
        unassigned: number;
        by_user: { user_id: number; name: string; count: number }[];
    };
    duplicate_file: { original_filename: string; created_at: string } | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.upload.period.label, href: `/admin/periods/${props.upload.period.id}` },
    { title: 'Check and confirm', href: '#' },
];

type Filter = 'all' | 'problems' | 'duplicates' | 'unassigned';
const filter = ref<Filter>(props.summary.errors > 0 ? 'problems' : 'all');

const skip = reactive(new Set<number>());
const include = reactive(new Set<number>());
const fixes = reactive<Record<number, Partial<Record<FixField, string>>>>({});

const hasErrors = (row: PreviewRow) => Object.keys(row.errors).length > 0;

/** A problem row is resolved when it is skipped, or every field it complained about has a correction typed in. */
const resolved = (row: PreviewRow) =>
    skip.has(row.row_number) ||
    (Object.keys(row.errors) as FixField[]).every((field) => (fixes[row.row_number]?.[field] ?? '').trim() !== '');

const unresolved = computed(() => props.rows.filter((row) => hasErrors(row) && !resolved(row)).length);

const visibleRows = computed(() =>
    props.rows.filter((row) => {
        if (filter.value === 'problems') return hasErrors(row);
        if (filter.value === 'duplicates') return row.duplicate;
        if (filter.value === 'unassigned') return !hasErrors(row) && !row.duplicate && row.user_id === null;
        return true;
    }),
);

const fixValue = (row: PreviewRow, field: FixField) => fixes[row.row_number]?.[field] ?? '';

const setFix = (row: PreviewRow, field: FixField, value: string | number) => {
    fixes[row.row_number] = { ...(fixes[row.row_number] ?? {}), [field]: String(value) };
};

const toggle = (set: Set<number>, rowNumber: number, on: boolean) => (on ? set.add(rowNumber) : set.delete(rowNumber));

const form = useForm({});
const formErrors = computed(() => form.errors as Record<string, string | undefined>);

const confirm = () => {
    form.transform(() => ({
        skip: [...skip],
        include_duplicates: [...include],
        fixes,
    })).post(route('admin.imports.confirm', props.upload.id), { preserveScroll: true });
};

const tabs: { key: Filter; label: string; count: number }[] = [
    { key: 'all', label: 'All rows', count: props.summary.total },
    { key: 'problems', label: 'Problems', count: props.summary.errors },
    { key: 'duplicates', label: 'Duplicates', count: props.summary.duplicates },
    { key: 'unassigned', label: 'Unassigned', count: props.summary.unassigned },
];
</script>

<template>
    <Head title="Check and confirm" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <FlashMessage />

            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Check {{ upload.original_filename }}</h1>
                    <p class="text-sm text-muted-foreground">Nothing is saved until you confirm.</p>
                </div>
                <Button variant="outline" as-child>
                    <Link :href="route('admin.imports.mapping.edit', upload.id)">Change column matching</Link>
                </Button>
            </div>

            <div v-if="duplicate_file" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                This file looks identical to {{ duplicate_file.original_filename }}, imported on {{ formatDate(duplicate_file.created_at) }}.
            </div>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border p-3">
                    <div class="text-2xl font-semibold">{{ summary.total }}</div>
                    <div class="text-xs text-muted-foreground">rows in the file</div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-2xl font-semibold" :class="{ 'text-red-600': summary.errors }">{{ summary.errors }}</div>
                    <div class="text-xs text-muted-foreground">rows with problems</div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-2xl font-semibold">{{ summary.duplicates }}</div>
                    <div class="text-xs text-muted-foreground">possible duplicates (skipped unless ticked)</div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-2xl font-semibold">{{ summary.unassigned }}</div>
                    <div class="text-xs text-muted-foreground">not matched to anyone</div>
                </div>
            </section>

            <p v-if="summary.by_user.length" class="text-sm text-muted-foreground">
                Matched: <span v-for="(entry, i) in summary.by_user" :key="entry.user_id">{{ entry.name }} ({{ entry.count }})<span v-if="i < summary.by_user.length - 1">, </span></span>
            </p>

            <div class="flex flex-wrap gap-2">
                <Button v-for="tab in tabs" :key="tab.key" size="sm" :variant="filter === tab.key ? 'default' : 'outline'" @click="filter = tab.key">
                    {{ tab.label }} ({{ tab.count }})
                </Button>
            </div>

            <ul class="divide-y rounded-lg border">
                <li v-for="row in visibleRows" :key="row.row_number" class="flex flex-col gap-2 p-4 text-sm" :class="{ 'opacity-50': skip.has(row.row_number) }">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-medium">
                                <span class="text-muted-foreground">Row {{ row.row_number }} ·</span> {{ row.description || row.raw_date || '(blank)' }}
                            </div>
                            <div class="text-muted-foreground">
                                {{ row.date ? formatDate(row.date) : row.raw_date }} · {{ row.amount !== null ? formatPence(row.amount) : row.raw_amount }}
                                <span v-if="row.card_ref"> · {{ row.card_ref }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <span v-if="hasErrors(row)" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-200">Problem</span>
                            <span v-else-if="row.duplicate" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">Duplicate</span>
                            <span v-else-if="row.user_name">{{ row.user_name }}</span>
                            <span v-else class="text-muted-foreground">Unassigned</span>
                        </div>
                    </div>

                    <div v-if="hasErrors(row)" class="grid gap-2 sm:grid-cols-3">
                        <div v-for="(message, field) in row.errors" :key="field" class="grid gap-1">
                            <label class="text-xs text-red-700 dark:text-red-300" :for="`fix-${row.row_number}-${field}`">{{ message }}</label>
                            <Input
                                :id="`fix-${row.row_number}-${field}`"
                                :model-value="fixValue(row, field as FixField)"
                                :placeholder="field === 'date' ? 'e.g. 03/09/2026' : field === 'amount' ? 'e.g. 12.50' : 'Description'"
                                :disabled="skip.has(row.row_number)"
                                @update:model-value="setFix(row, field as FixField, $event)"
                            />
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label v-if="hasErrors(row)" class="flex items-center gap-2 text-xs">
                            <Checkbox :checked="skip.has(row.row_number)" @update:checked="toggle(skip, row.row_number, $event)" />
                            Skip this row
                        </label>
                        <label v-if="row.duplicate && !hasErrors(row)" class="flex items-center gap-2 text-xs">
                            <Checkbox :checked="include.has(row.row_number)" @update:checked="toggle(include, row.row_number, $event)" />
                            Import anyway (it's a genuine repeat)
                        </label>
                    </div>

                    <dl v-if="Object.keys(row.extra).length" class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <div v-for="(value, label) in row.extra" :key="label">
                            <dt class="inline">{{ label }}:</dt>
                            <dd class="inline"> {{ value }}</dd>
                        </div>
                    </dl>
                </li>
                <li v-if="!visibleRows.length" class="p-4 text-sm text-muted-foreground">Nothing to show here.</li>
            </ul>

            <div class="sticky bottom-0 flex flex-col gap-2 border-t bg-background py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm">
                    <InputError :message="formErrors.rows" />
                    <span v-if="!formErrors.rows && unresolved > 0" class="text-red-600">
                        {{ unresolved }} {{ unresolved === 1 ? 'row needs' : 'rows need' }} a correction or skipping.
                    </span>
                </div>
                <Button :disabled="form.processing || unresolved > 0" @click="confirm">Confirm import</Button>
            </div>
        </div>
    </AppLayout>
</template>
