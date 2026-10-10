<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

interface Line {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    card_ref: string | null;
    status: 'todo' | 'done' | 'queried' | 'approved';
    owner: { id: number; name: string } | null;
}

const props = defineProps<{
    period: { id: number; label: string; status: 'draft' | 'published' | 'closed' };
    owner: string;
    lines: Line[];
    unassigned_count: number;
    owners: { id: number; name: string; lines: number }[];
    cardholders: { id: number; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.period.label, href: `/admin/periods/${props.period.id}` },
    { title: 'Lines', href: '#' },
];

const selected = reactive(new Set<number>());
const target = ref<string>('');
const movable = computed(() => props.lines.filter((line) => line.status !== 'approved'));
const allSelected = computed(() => movable.value.length > 0 && movable.value.every((line) => selected.has(line.id)));

const toggleAll = (on: boolean) => movable.value.forEach((line) => (on ? selected.add(line.id) : selected.delete(line.id)));
const toggle = (id: number, on: boolean) => (on ? selected.add(id) : selected.delete(id));

const changeOwner = (event: Event) => {
    router.get(route('admin.periods.lines', props.period.id), { owner: (event.target as HTMLSelectElement).value });
};

const form = useForm({});

const move = () => {
    form.transform(() => ({
        transaction_ids: [...selected],
        user_id: target.value === 'unassigned' ? null : Number(target.value),
    })).put(route('admin.periods.lines.assign', props.period.id), {
        preserveScroll: true,
        onSuccess: () => selected.clear(),
    });
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <Head :title="`Lines · ${period.label}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="text-xl font-semibold">Lines in {{ period.label }}</h1>
                <select :class="selectClass" :value="owner" @change="changeOwner" aria-label="Show lines for">
                    <option value="unassigned">Unassigned ({{ unassigned_count }})</option>
                    <option value="all">Everyone</option>
                    <option v-for="person in owners" :key="person.id" :value="String(person.id)">{{ person.name }} ({{ person.lines }})</option>
                </select>
            </div>

            <div v-if="period.status !== 'closed'" class="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center">
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox :checked="allSelected" @update:checked="toggleAll" />
                    Select all
                </label>
                <span class="text-sm text-muted-foreground sm:ml-auto">{{ selected.size }} selected · move to</span>
                <select v-model="target" :class="selectClass" aria-label="Move selected lines to">
                    <option value="" disabled>Choose…</option>
                    <option v-for="person in cardholders" :key="person.id" :value="String(person.id)">{{ person.name }}</option>
                    <option value="unassigned">Unassigned</option>
                </select>
                <Button :disabled="!selected.size || !target || form.processing" @click="move">Move</Button>
            </div>
            <p class="text-xs text-muted-foreground">Moving a line clears any purpose the previous person entered. Approved lines can't be moved.</p>

            <ul class="divide-y rounded-lg border">
                <li v-for="line in lines" :key="line.id" class="flex items-start gap-3 p-4 text-sm">
                    <Checkbox
                        v-if="period.status !== 'closed'"
                        :checked="selected.has(line.id)"
                        :disabled="line.status === 'approved'"
                        @update:checked="toggle(line.id, $event)"
                        :aria-label="`Select row ${line.row_number}`"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ line.description }}</div>
                        <div class="text-muted-foreground">
                            Row {{ line.row_number }} · {{ formatDate(line.date) }} · {{ formatPence(line.amount) }}
                            <span v-if="line.card_ref"> · {{ line.card_ref }}</span>
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        <div>{{ line.owner?.name ?? 'Unassigned' }}</div>
                        <div v-if="line.status === 'approved'" class="text-xs text-muted-foreground">Approved</div>
                    </div>
                </li>
                <li v-if="!lines.length" class="p-4 text-sm text-muted-foreground">No lines here.</li>
            </ul>
        </div>
    </AppLayout>
</template>
