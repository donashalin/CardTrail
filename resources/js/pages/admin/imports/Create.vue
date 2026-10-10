<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ period: { id: number; label: string } }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.period.label, href: `/admin/periods/${props.period.id}` },
    { title: 'Upload statement', href: '#' },
];

const form = useForm<{ file: File | null }>({ file: null });

const pick = (event: Event) => {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const submit = () => {
    form.post(route('admin.imports.store', props.period.id), { forceFormData: true });
};
</script>

<template>
    <Head title="Upload statement" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="flex max-w-xl flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">Upload the statement for {{ period.label }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Use the card company's export (CSV or Excel). The first time, you'll match up the columns; after that CardTrail remembers the layout.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="file">Statement file</Label>
                <input
                    id="file"
                    type="file"
                    accept=".csv,.xlsx,.xls"
                    required
                    class="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-foreground"
                    @change="pick"
                />
                <InputError :message="form.errors.file" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing || !form.file">Upload</Button>
                <Button variant="ghost" as-child>
                    <Link :href="route('admin.periods.show', period.id)">Cancel</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
