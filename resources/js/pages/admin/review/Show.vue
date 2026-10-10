<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import LineStatusBadge from '@/components/LineStatusBadge.vue';
import ReconciliationStatusBadge from '@/components/ReconciliationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { formatBytes } from '@/lib/receipts';
import { type BreadcrumbItem } from '@/types';
import type { LineStatus, ReceiptItem, ReconciliationSummary } from '@/types/cardtrail';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, ExternalLink, FileText } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface ReviewLine {
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

interface CommentItem {
    id: number;
    body: string;
    author: string;
    mine: boolean;
    created_at: string;
}

interface Selected {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    card_ref: string | null;
    extra: Record<string, string>;
    purpose: string | null;
    no_receipt: boolean;
    no_receipt_reason: string | null;
    status: LineStatus;
    receipts: ReceiptItem[];
    comments: CommentItem[];
    can_approve: boolean;
    can_query: boolean;
    can_comment: boolean;
}

type Filter = 'all' | 'done' | 'queried' | 'approved' | 'todo';

const props = defineProps<{
    reconciliation: ReconciliationSummary & { owner: string; owner_active: boolean; approvable: number; can_approve_all: boolean };
    lines: ReviewLine[];
    selected: Selected | null;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.reconciliation.period.label, href: `/admin/periods/${props.reconciliation.period.id}` },
    { title: props.reconciliation.owner, href: '#' },
]);

const filter = ref<Filter>('all');
const filters: { key: Filter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'done', label: 'To review' },
    { key: 'queried', label: 'Queried' },
    { key: 'approved', label: 'Approved' },
    { key: 'todo', label: 'To do' },
];
const visible = computed(() => (filter.value === 'all' ? props.lines : props.lines.filter((line) => line.status === filter.value)));
const count = (key: Filter) => (key === 'all' ? props.lines.length : props.lines.filter((line) => line.status === key).length);

const receiptIndex = ref(0);
const receipt = computed(() => props.selected?.receipts[receiptIndex.value] ?? null);
const extraColumns = computed(() => Object.entries(props.selected?.extra ?? {}).filter(([, value]) => value !== ''));

const detail = ref<HTMLElement>();
watch(
    () => props.selected?.id,
    async () => {
        receiptIndex.value = 0;
        showQuery.value = false;
        await nextTick();
        if (window.innerWidth < 768) {
            detail.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    },
);

const select = (line: ReviewLine) => router.get(route('admin.reconciliations.review', { reconciliation: props.reconciliation.id, line: line.id }), {}, { preserveScroll: true, preserveState: true });

const busy = ref(false);
const options = { preserveScroll: true, preserveState: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) };

const approve = () => props.selected && router.post(route('admin.lines.approve', props.selected.id), {}, options);

const approveAll = () => {
    if (confirm(`Approve all ${props.reconciliation.approvable} remaining completed lines for ${props.reconciliation.owner}?`)) {
        router.post(route('admin.reconciliations.approve', props.reconciliation.id), {}, options);
    }
};

const showQuery = ref(false);
const queryForm = useForm({ body: '' });
const sendQuery = () => {
    if (!props.selected) return;
    queryForm.post(route('admin.lines.query', props.selected.id), { preserveScroll: true, preserveState: true, onSuccess: () => { queryForm.reset(); showQuery.value = false; } });
};

const replyForm = useForm({ body: '' });
const sendReply = () => {
    if (!props.selected) return;
    replyForm.post(route('transactions.comments.store', props.selected.id), { preserveScroll: true, preserveState: true, onSuccess: () => replyForm.reset() });
};

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <Head :title="`Review · ${reconciliation.owner}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold">{{ reconciliation.owner }}</h1>
                        <ReconciliationStatusBadge :status="reconciliation.status" />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ reconciliation.period.label }} · {{ reconciliation.done }} of {{ reconciliation.lines }} done
                        <span v-if="reconciliation.queried"> · {{ reconciliation.queried }} queried</span>
                        <span v-if="!reconciliation.owner_active" class="text-red-600 dark:text-red-400"> · Inactive user</span>
                    </p>
                </div>
                <Button v-if="reconciliation.can_approve_all" :disabled="busy" @click="approveAll">Approve all remaining ({{ reconciliation.approvable }})</Button>
            </header>

            <p v-if="reconciliation.status === 'in_progress'" class="rounded-md border bg-muted px-4 py-3 text-sm">
                {{ reconciliation.owner }} hasn’t submitted yet{{ reconciliation.queried ? ' (they have questions to answer)' : '' }}. You can look, and approve once they submit.
            </p>

            <div class="grid gap-4 md:grid-cols-[minmax(0,22rem)_1fr]">
                <aside class="flex flex-col gap-2">
                    <div class="flex flex-wrap gap-1">
                        <button
                            v-for="item in filters"
                            :key="item.key"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs"
                            :class="filter === item.key ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                            @click="filter = item.key"
                        >
                            {{ item.label }} ({{ count(item.key) }})
                        </button>
                    </div>
                    <ul class="divide-y rounded-lg border md:max-h-[75vh] md:overflow-y-auto">
                        <li v-for="line in visible" :key="line.id">
                            <button
                                type="button"
                                class="flex w-full items-start gap-2 p-3 text-left text-sm hover:bg-accent"
                                :class="{ 'bg-accent': selected?.id === line.id }"
                                @click="select(line)"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium">{{ line.description }}</div>
                                    <div class="truncate text-muted-foreground">Row {{ line.row_number }} · {{ line.purpose || 'No purpose yet' }}</div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="tabular-nums">{{ formatPence(line.amount) }}</div>
                                    <LineStatusBadge :status="line.status" />
                                </div>
                            </button>
                        </li>
                        <li v-if="!visible.length" class="p-3 text-sm text-muted-foreground">No lines here.</li>
                    </ul>
                </aside>

                <section v-if="selected" ref="detail" class="flex min-w-0 flex-col gap-4">
                    <div class="rounded-xl border p-4">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="text-lg font-semibold break-words">{{ selected.description }}</h2>
                            <LineStatusBadge :status="selected.status" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatPence(selected.amount) }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ formatDate(selected.date) }} · Row {{ selected.row_number }}<span v-if="selected.card_ref"> · {{ selected.card_ref }}</span>
                        </p>
                        <dl v-if="extraColumns.length" class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                            <template v-for="[label, value] in extraColumns" :key="label">
                                <dt class="text-muted-foreground">{{ label }}</dt>
                                <dd class="break-words">{{ value }}</dd>
                            </template>
                        </dl>
                        <div class="mt-4 text-sm">
                            <div class="font-medium">Purpose</div>
                            <p class="whitespace-pre-line break-words">{{ selected.purpose || '—' }}</p>
                            <p v-if="selected.no_receipt" class="mt-2"><span class="font-medium">No receipt:</span> {{ selected.no_receipt_reason || '—' }}</p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div v-if="selected.receipts.length > 1" class="flex flex-wrap gap-1">
                            <button
                                v-for="(item, index) in selected.receipts"
                                :key="item.id"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs"
                                :class="receiptIndex === index ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                                @click="receiptIndex = index"
                            >
                                Receipt {{ index + 1 }}
                            </button>
                        </div>
                        <template v-if="receipt">
                            <iframe v-if="receipt.is_pdf" :src="receipt.url" :title="receipt.name" class="h-[70vh] w-full rounded-lg border" />
                            <img v-else-if="receipt.is_image" :src="receipt.url" :alt="receipt.name" class="max-h-[70vh] w-full rounded-lg border object-contain" />
                            <div v-else class="flex items-center gap-3 rounded-lg border p-4 text-sm">
                                <FileText class="size-6 shrink-0 text-muted-foreground" />
                                <span class="min-w-0 flex-1 break-all">{{ receipt.name }} · {{ formatBytes(receipt.size) }}</span>
                                <a :href="receipt.url" class="flex items-center gap-1 font-medium underline underline-offset-4"><Download class="size-4" /> Download</a>
                            </div>
                            <a :href="receipt.url" target="_blank" rel="noopener" class="flex items-center gap-1 self-start text-sm text-muted-foreground hover:text-foreground">
                                <ExternalLink class="size-4" /> Open {{ receipt.name }} in a new tab
                            </a>
                        </template>
                        <p v-else class="rounded-lg border p-4 text-sm text-muted-foreground">No receipt attached.</p>
                    </div>

                    <div v-if="selected.can_approve || selected.can_query" class="flex flex-wrap gap-2">
                        <Button v-if="selected.can_approve" :disabled="busy" @click="approve">Approve</Button>
                        <Button v-if="selected.can_query && !showQuery" variant="outline" @click="showQuery = true">Query</Button>
                    </div>

                    <form v-if="showQuery" class="flex flex-col gap-2 rounded-xl border p-4" @submit.prevent="sendQuery">
                        <label for="query" class="text-sm font-medium">What do you need from {{ reconciliation.owner }}?</label>
                        <textarea id="query" v-model="queryForm.body" rows="3" maxlength="2000" :class="textareaClass" />
                        <p v-if="queryForm.errors.body" class="text-sm text-red-600 dark:text-red-400">{{ queryForm.errors.body }}</p>
                        <div class="flex gap-2">
                            <Button type="submit" :disabled="queryForm.processing">Send query</Button>
                            <Button type="button" variant="ghost" @click="showQuery = false">Cancel</Button>
                        </div>
                    </form>

                    <div v-if="selected.comments.length" class="flex flex-col gap-3 rounded-xl border p-4">
                        <h3 class="font-medium">Conversation</h3>
                        <ul class="flex flex-col gap-2">
                            <li
                                v-for="item in selected.comments"
                                :key="item.id"
                                class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                                :class="item.mine ? 'self-end bg-primary text-primary-foreground' : 'self-start bg-muted'"
                            >
                                <div class="whitespace-pre-line break-words">{{ item.body }}</div>
                                <div class="mt-1 text-xs opacity-75">{{ item.author }} · {{ formatDate(item.created_at) }}</div>
                            </li>
                        </ul>
                        <form v-if="selected.can_comment" class="flex flex-col gap-2" @submit.prevent="sendReply">
                            <textarea v-model="replyForm.body" rows="2" maxlength="2000" :class="textareaClass" placeholder="Reply without changing the line’s status" />
                            <Button type="submit" variant="outline" class="self-end" :disabled="!replyForm.body.trim() || replyForm.processing">Send reply</Button>
                        </form>
                    </div>
                </section>
                <p v-else class="text-sm text-muted-foreground">No lines.</p>
            </div>

            <Link :href="route('admin.periods.show', reconciliation.period.id)" class="text-sm text-muted-foreground hover:text-foreground">← Back to {{ reconciliation.period.label }}</Link>
        </div>
    </AppLayout>
</template>
