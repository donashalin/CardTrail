<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface EditablePeriod {
    id: number;
    label: string;
    month: string;
    due_date: string;
    reminder_offsets: string;
    status: 'draft' | 'published' | 'closed';
}

const props = defineProps<{
    period: EditablePeriod | null;
    defaults: { month: string; due_date: string; reminder_offsets: string } | null;
}>();

const monthLocked = computed(() => props.period !== null && props.period.status !== 'draft');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.period ? props.period.label : 'New period', href: '#' },
];

const form = useForm({
    label: props.period?.label ?? '',
    month: props.period?.month ?? props.defaults?.month ?? '',
    due_date: props.period?.due_date ?? props.defaults?.due_date ?? '',
    reminder_offsets: props.period?.reminder_offsets ?? props.defaults?.reminder_offsets ?? '',
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
const reminderError = computed(() =>
    Object.entries(errors.value).find(([key]) => key.startsWith('reminder_offsets'))?.[1],
);

const submit = () => {
    if (props.period) {
        form.put(route('admin.periods.update', props.period.id));
    } else {
        form.post(route('admin.periods.store'));
    }
};
</script>

<template>
    <Head :title="period ? `Edit ${period.label}` : 'New period'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="flex max-w-xl flex-col gap-6 p-4">
            <div class="grid gap-2">
                <Label for="month">Statement month</Label>
                <Input id="month" type="month" v-model="form.month" required :disabled="monthLocked" />
                <p v-if="monthLocked" class="text-xs text-muted-foreground">The month can't change once the period is published.</p>
                <InputError :message="form.errors.month" />
            </div>

            <div class="grid gap-2">
                <Label for="label">Name (optional)</Label>
                <Input id="label" v-model="form.label" placeholder="Leave blank to use the month, e.g. September 2026" />
                <InputError :message="form.errors.label" />
            </div>

            <div class="grid gap-2">
                <Label for="due_date">Due date</Label>
                <Input id="due_date" type="date" v-model="form.due_date" required />
                <InputError :message="form.errors.due_date" />
            </div>

            <div class="grid gap-2">
                <Label for="reminder_offsets">Reminder days</Label>
                <Input id="reminder_offsets" v-model="form.reminder_offsets" placeholder="3, 0" />
                <p class="text-xs text-muted-foreground">
                    Days before the due date to remind anyone who hasn't finished. 0 means on the day. Leave blank for no reminders.
                </p>
                <InputError :message="reminderError" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">{{ period ? 'Save changes' : 'Create period' }}</Button>
                <Button variant="ghost" as-child>
                    <Link :href="period ? route('admin.periods.show', period.id) : route('admin.periods.index')">Cancel</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
