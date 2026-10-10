<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

type Field = 'date' | 'description' | 'amount' | 'card' | 'cardholder' | 'purpose';

interface SheetRow {
    row_number: number;
    cells: string[];
}

const props = defineProps<{
    upload: { id: number; original_filename: string; period: { id: number; label: string } };
    header_row: number;
    first_rows: SheetRow[];
    header_labels: string[];
    samples: SheetRow[];
    mapping: Record<Field, number | null>;
    duplicate_file: { original_filename: string; created_at: string } | null;
}>();

const fields: { key: Field; label: string; help: string; required: boolean }[] = [
    { key: 'date', label: 'Transaction date', help: 'When the purchase was made.', required: true },
    { key: 'description', label: 'Description', help: 'The merchant or transaction details.', required: true },
    { key: 'amount', label: 'Amount', help: 'Charges positive; credits negative or marked CR.', required: true },
    { key: 'card', label: 'Card number or last 4', help: 'Matches rows to cardholders. Only the last 4 digits are kept.', required: false },
    { key: 'cardholder', label: 'Cardholder name', help: 'Used for matching when there is no card number.', required: false },
    { key: 'purpose', label: '“What was it for” column', help: 'Where purposes go in the exported spreadsheet. Leave empty to add a new column.', required: false },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.upload.period.label, href: `/admin/periods/${props.upload.period.id}` },
    { title: 'Match columns', href: '#' },
];

const form = useForm({
    header_row: props.header_row,
    mapping: { ...props.mapping },
    remember: true,
    save_as: 'Card statement',
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

const mappedField = (column: number) => fields.find((field) => form.mapping[field.key] === column);

const setField = (key: Field, event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    form.mapping[key] = value === '' ? null : Number(value);
};

const changeHeaderRow = (event: Event) => {
    router.get(route('admin.imports.mapping.edit', props.upload.id), { header_row: (event.target as HTMLSelectElement).value });
};

const submit = () => {
    form.transform((data) => ({
        header_row: data.header_row,
        mapping: data.mapping,
        save_as: data.remember ? data.save_as : null,
    })).put(route('admin.imports.mapping.update', props.upload.id));
};

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <Head title="Match columns" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="flex flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">Match the columns in {{ upload.original_filename }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">Tell CardTrail which column holds what. You only need to do this once per layout.</p>
            </div>

            <div v-if="duplicate_file" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                This file looks identical to {{ duplicate_file.original_filename }}, imported on {{ formatDate(duplicate_file.created_at) }}. Repeated rows will be flagged on the next step.
            </div>

            <div class="grid max-w-md gap-2">
                <Label for="header_row">Which row has the column headings?</Label>
                <select id="header_row" :class="selectClass" :value="header_row" @change="changeHeaderRow">
                    <option v-for="row in first_rows" :key="row.row_number" :value="row.row_number">
                        Row {{ row.row_number }}: {{ row.cells.filter(Boolean).slice(0, 4).join(', ') }}
                    </option>
                </select>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div v-for="field in fields" :key="field.key" class="grid gap-1">
                    <Label :for="`field-${field.key}`">
                        {{ field.label }}<span v-if="field.required" class="text-red-600"> *</span>
                    </Label>
                    <select :id="`field-${field.key}`" :class="selectClass" :value="form.mapping[field.key] ?? ''" @change="setField(field.key, $event)">
                        <option value="">{{ field.required ? 'Choose a column…' : '— Not in this file —' }}</option>
                        <option v-for="(label, index) in header_labels" :key="index" :value="index">{{ label }}</option>
                    </select>
                    <p class="text-xs text-muted-foreground">{{ field.help }}</p>
                    <InputError :message="errors[`mapping.${field.key}`]" />
                </div>
            </div>
            <InputError :message="errors.mapping" />

            <section class="flex flex-col gap-2">
                <h2 class="text-sm font-medium">First rows of the file</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b bg-muted/50">
                            <tr>
                                <th class="px-3 py-2 font-medium">Row</th>
                                <th v-for="(label, index) in header_labels" :key="index" class="whitespace-nowrap px-3 py-2 font-medium">
                                    {{ label }}
                                    <div v-if="mappedField(index)" class="font-normal text-primary">→ {{ mappedField(index)?.label }}</div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in samples" :key="row.row_number" class="border-b last:border-0">
                                <td class="px-3 py-2 text-muted-foreground">{{ row.row_number }}</td>
                                <td v-for="(label, index) in header_labels" :key="index" class="whitespace-nowrap px-3 py-2">{{ row.cells[index] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid max-w-md gap-2">
                <Label for="remember" class="flex items-center gap-3">
                    <Checkbox id="remember" v-model:checked="form.remember" />
                    <span>Remember this layout for future uploads</span>
                </Label>
                <Input v-if="form.remember" v-model="form.save_as" placeholder="Layout name, e.g. Amex export" />
                <InputError :message="errors.save_as" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">Continue to preview</Button>
                <Button variant="ghost" as-child>
                    <Link :href="route('admin.periods.show', upload.period.id)">Cancel</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
