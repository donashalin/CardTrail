<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import LineStatusBadge from '@/components/LineStatusBadge.vue';
import ReceiptThumb from '@/components/ReceiptThumb.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import type { LineStatus, ReceiptItem } from '@/types/cardtrail';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Candidate {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    status: LineStatus;
    receipts: number;
    period: string;
}

const props = defineProps<{ receipt: ReceiptItem; amount: string; candidates: Candidate[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Receipt inbox', href: '/receipts' },
    { title: 'Match', href: '#' },
];

const amount = ref(props.amount);
const attaching = ref<number | null>(null);

const sort = () => router.get(route('receipts.match', props.receipt.id), amount.value ? { amount: amount.value } : {}, { preserveState: true });

const attach = (line: Candidate) => {
    router.put(route('receipts.match.store', props.receipt.id), { transaction_id: line.id }, {
        onStart: () => (attaching.value = line.id),
        onFinish: () => (attaching.value = null),
    });
};
</script>

<template>
    <Head title="Match receipt" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4 md:flex-row md:items-start">
            <div class="md:w-56 md:shrink-0">
                <ReceiptThumb :receipt="receipt" />
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-4">
                <FlashMessage />
                <div>
                    <h1 class="text-xl font-semibold">Which line is this receipt for?</h1>
                    <p class="text-sm text-muted-foreground">Lines closest to the date you added the receipt come first.</p>
                </div>

                <form class="flex gap-2" @submit.prevent="sort">
                    <Input v-model="amount" inputmode="decimal" placeholder="Amount on the receipt, e.g. 12.50" aria-label="Amount on the receipt" />
                    <Button type="submit" variant="outline">Sort</Button>
                </form>

                <ul class="divide-y rounded-lg border">
                    <li v-for="line in candidates" :key="line.id" class="flex items-center gap-3 p-4 text-sm">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ line.description }}</div>
                            <div class="text-muted-foreground">
                                {{ line.period }} · Row {{ line.row_number }} · {{ formatDate(line.date) }}
                                <span v-if="line.receipts"> · {{ line.receipts }} attached</span>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="font-medium tabular-nums">{{ formatPence(line.amount) }}</div>
                            <LineStatusBadge :status="line.status" />
                        </div>
                        <Button size="sm" :disabled="attaching !== null" @click="attach(line)">Attach</Button>
                    </li>
                    <li v-if="!candidates.length" class="p-4 text-sm text-muted-foreground">There are no open lines to attach this to yet.</li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
