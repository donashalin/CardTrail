<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import LineStatusBadge from '@/components/LineStatusBadge.vue';
import ReceiptPicker from '@/components/ReceiptPicker.vue';
import ReceiptThumb from '@/components/ReceiptThumb.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import type { LineStatus, PeriodStatus, ReceiptItem, ReconciliationStatus } from '@/types/cardtrail';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

interface Line {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    card_ref: string | null;
    extra: Record<string, string>;
    purpose: string;
    no_receipt: boolean;
    no_receipt_reason: string;
    status: LineStatus;
}

interface CommentItem {
    id: number;
    body: string;
    author: string;
    mine: boolean;
    created_at: string;
}

const props = defineProps<{
    line: Line;
    receipts: ReceiptItem[];
    comments: CommentItem[];
    reconciliation: { id: number; owner: string; status: ReconciliationStatus } | null;
    period: { label: string; status: PeriodStatus };
    editable: boolean;
    can_comment: boolean;
    suggestions: string[];
    nav: { position: number | null; total: number; previous: number | null; next: number | null };
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard' },
    ...(props.reconciliation ? [{ title: props.period.label, href: route('reconciliations.show', props.reconciliation.id) }] : []),
    { title: `Row ${props.line.row_number}`, href: '#' },
]);

const isCredit = computed(() => props.line.amount < 0);
const extraColumns = computed(() => Object.entries(props.line.extra ?? {}).filter(([, value]) => value !== ''));

const fields = reactive({
    purpose: props.line.purpose,
    no_receipt: props.line.no_receipt,
    no_receipt_reason: props.line.no_receipt_reason,
});
const status = ref<LineStatus>(props.line.status);
const saveState = ref<'idle' | 'pending' | 'saving' | 'saved' | 'error'>('idle');
const saveErrors = ref<Record<string, string>>({});
let timer: ReturnType<typeof setTimeout> | undefined;
let inFlight: Promise<void> | null = null;

// Inertia reuses this page when moving between lines, so start fresh when the line changes.
watch(
    () => props.line.id,
    () => {
        clearTimeout(timer);
        timer = undefined;
        Object.assign(fields, { purpose: props.line.purpose, no_receipt: props.line.no_receipt, no_receipt_reason: props.line.no_receipt_reason });
        saveState.value = 'idle';
        saveErrors.value = {};
    },
);
watch(
    () => props.line.status,
    (value) => (status.value = value),
);

const failureMessage = (code: number | undefined) => {
    if (code === 403) return 'This line can no longer be changed. Reload the page to see why.';
    if (code === 419) return 'Your session has expired. Reload the page and sign in again.';
    return 'Couldn’t save – check your connection. It will try again when you next type.';
};

/**
 * Autosave runs as a plain background request, not an Inertia visit, so it never navigates
 * and never cancels an upload or a page change that is under way.
 */
const save = (): Promise<void> => {
    const lineId = props.line.id;
    saveState.value = 'saving';

    const request = axios
        .put(route('transactions.update', lineId), { ...fields }, { headers: { Accept: 'application/json' } })
        .then(({ data }) => {
            if (props.line.id !== lineId) return;
            status.value = data.status;
            saveErrors.value = {};
            if (!timer) saveState.value = 'saved';
        })
        .catch((error) => {
            if (props.line.id !== lineId) return;
            saveState.value = 'error';
            const errors = error.response?.status === 422 ? error.response.data.errors : null;
            saveErrors.value = errors
                ? Object.fromEntries(Object.entries(errors as Record<string, string[]>).map(([key, messages]) => [key, messages[0]]))
                : { form: failureMessage(error.response?.status) };
        });

    inFlight = request.finally(() => {
        if (inFlight === request) inFlight = null;
    });

    return request;
};

const queueSave = () => {
    if (!props.editable) {
        return;
    }

    clearTimeout(timer);
    saveState.value = 'pending';
    timer = setTimeout(() => {
        timer = undefined;
        save();
    }, 800);
};

/** Send any typing that hasn't been saved yet. Resolves once nothing is waiting. */
const flush = (): Promise<void> => {
    if (timer) {
        clearTimeout(timer);
        timer = undefined;
        return save();
    }

    return inFlight ?? Promise.resolve();
};

const flushThen = (then: () => void) => {
    flush().finally(then);
};

const saveAndNext = () => {
    flush().finally(() => router.put(route('transactions.update', props.line.id), { ...fields, next: true }, { preserveScroll: true }));
};

// Leaving the line by any link: save what was typed. Leaving mid-upload: check first.
const removeBeforeListener = router.on('before', (event) => {
    if (event.detail.visit.method === 'get' && upload.processing && !confirm('A receipt is still uploading. Leave this page anyway?')) {
        return false;
    }

    if (timer) {
        flush();
    }
});

const warnBeforeUnload = (event: BeforeUnloadEvent) => {
    if (timer || inFlight || upload.processing) {
        event.preventDefault();
        event.returnValue = '';
    }
};

onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload));
onBeforeUnmount(() => {
    removeBeforeListener();
    window.removeEventListener('beforeunload', warnBeforeUnload);
    if (timer) flush();
});

const useSuggestion = (purpose: string) => {
    fields.purpose = purpose;
    queueSave();
};

const toggleNoReceipt = (value: boolean) => {
    fields.no_receipt = value;
    queueSave();
};

const upload = useForm<{ files: File[]; source: 'camera' | 'upload' }>({ files: [], source: 'upload' });
const uploadError = computed(() => Object.values(upload.errors)[0] ?? null);

const uploadFiles = (files: File[], source: 'camera' | 'upload') => {
    flushThen(() => {
        upload.files = files;
        upload.source = source;
        upload.post(route('transactions.receipts.store', props.line.id), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => upload.reset(),
        });
    });
};

const moveToInbox = (receipt: ReceiptItem) => {
    flushThen(() => router.delete(route('receipts.match.destroy', receipt.id), { preserveScroll: true, preserveState: true }));
};

const deleteReceipt = (receipt: ReceiptItem) => {
    if (confirm(`Delete ${receipt.name}? This can’t be undone.`)) {
        flushThen(() => router.delete(route('receipts.destroy', receipt.id), { preserveScroll: true, preserveState: true }));
    }
};

const comment = useForm({ body: '' });
const sendComment = () => {
    flushThen(() =>
        comment.post(route('transactions.comments.store', props.line.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => comment.reset(),
        }),
    );
};

const lockedReason = computed(() => {
    if (props.period.status === 'closed') {
        return 'This month is closed, so this line can’t be changed.';
    }

    if (status.value === 'approved') {
        return 'Accounts have approved this line.';
    }

    if (props.reconciliation?.status === 'submitted') {
        return 'You’ve submitted this month. Accounts will query a line if they need anything changed.';
    }

    return null;
});

const saveLabel = computed(
    () => ({ idle: '', pending: 'Unsaved changes…', saving: 'Saving…', saved: 'Saved', error: 'Not saved – check the form' })[saveState.value],
);

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:opacity-60';
</script>

<template>
    <Head :title="`Row ${line.row_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex items-center justify-between gap-2 text-sm text-muted-foreground">
                <Link v-if="nav.previous" :href="route('transactions.show', nav.previous)" class="flex items-center gap-1 hover:text-foreground">
                    <ChevronLeft class="size-4" /> Previous
                </Link>
                <span v-else />
                <span v-if="nav.position">{{ nav.position }} of {{ nav.total }}</span>
                <Link v-if="nav.next" :href="route('transactions.show', nav.next)" class="flex items-center gap-1 hover:text-foreground">
                    Next <ChevronRight class="size-4" />
                </Link>
                <span v-else />
            </div>

            <section class="rounded-xl border p-4">
                <div class="flex items-start justify-between gap-3">
                    <h1 class="text-lg font-semibold break-words">{{ line.description }}</h1>
                    <LineStatusBadge :status="status" />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ formatPence(line.amount) }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ formatDate(line.date) }} · Row {{ line.row_number }}<span v-if="line.card_ref"> · {{ line.card_ref }}</span>
                    <span v-if="isCredit"> · Credit</span>
                </p>
                <dl v-if="extraColumns.length" class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <template v-for="[label, value] in extraColumns" :key="label">
                        <dt class="text-muted-foreground">{{ label }}</dt>
                        <dd class="break-words">{{ value }}</dd>
                    </template>
                </dl>
            </section>

            <p v-if="!editable && lockedReason" class="rounded-md border bg-muted px-4 py-3 text-sm">{{ lockedReason }}</p>

            <section class="flex flex-col gap-2">
                <Label for="purpose">What was it for?</Label>
                <textarea
                    id="purpose"
                    v-model="fields.purpose"
                    rows="3"
                    maxlength="1000"
                    :disabled="!editable"
                    :class="textareaClass"
                    placeholder="e.g. Lunch with a supplier to discuss the depot contract"
                    @input="queueSave"
                />
                <p v-if="saveErrors.purpose" class="text-sm text-red-600 dark:text-red-400">{{ saveErrors.purpose }}</p>
                <div v-if="editable && suggestions.length" class="flex flex-wrap gap-2">
                    <button
                        v-for="suggestion in suggestions"
                        :key="suggestion"
                        type="button"
                        class="max-w-full truncate rounded-full border px-3 py-1 text-xs hover:bg-accent"
                        @click="useSuggestion(suggestion)"
                    >
                        {{ suggestion }}
                    </button>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <div>
                    <h2 class="font-medium">Receipts</h2>
                    <p v-if="isCredit" class="text-sm text-muted-foreground">Credits only need a purpose.</p>
                </div>

                <div v-if="receipts.length" class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <ReceiptThumb v-for="receipt in receipts" :key="receipt.id" :receipt="receipt">
                        <template v-if="editable" #actions>
                            <button type="button" class="flex-1 px-2 py-1.5 hover:bg-accent" @click="moveToInbox(receipt)">To inbox</button>
                            <button type="button" class="flex-1 px-2 py-1.5 text-red-600 hover:bg-accent dark:text-red-400" @click="deleteReceipt(receipt)">
                                Delete
                            </button>
                        </template>
                    </ReceiptThumb>
                </div>

                <template v-if="editable">
                    <ReceiptPicker :processing="upload.processing" @selected="uploadFiles" />
                    <p v-if="upload.progress" class="text-sm text-muted-foreground">Uploading… {{ upload.progress.percentage }}%</p>
                    <p v-if="uploadError" class="text-sm text-red-600 dark:text-red-400">{{ uploadError }} Your purpose has been kept, so just try the upload again.</p>

                    <label v-if="!isCredit" class="flex items-center gap-2 text-sm">
                        <Checkbox :checked="fields.no_receipt" @update:checked="toggleNoReceipt" />
                        I don’t have a receipt for this
                    </label>
                    <div v-if="fields.no_receipt && !isCredit" class="flex flex-col gap-2">
                        <Label for="no_receipt_reason">Why is there no receipt?</Label>
                        <textarea
                            id="no_receipt_reason"
                            v-model="fields.no_receipt_reason"
                            rows="2"
                            maxlength="500"
                            :class="textareaClass"
                            placeholder="e.g. Parking machine didn’t print one"
                            @input="queueSave"
                        />
                        <p v-if="saveErrors.no_receipt_reason" class="text-sm text-red-600 dark:text-red-400">{{ saveErrors.no_receipt_reason }}</p>
                    </div>
                </template>
                <p v-else-if="line.no_receipt" class="text-sm">No receipt: {{ line.no_receipt_reason || '—' }}</p>
                <p v-else-if="!receipts.length" class="text-sm text-muted-foreground">No receipts attached.</p>
            </section>

            <section v-if="comments.length || line.status === 'queried'" class="flex flex-col gap-3 rounded-xl border p-4">
                <h2 class="font-medium">Questions from accounts</h2>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="item in comments"
                        :key="item.id"
                        class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                        :class="item.mine ? 'self-end bg-primary text-primary-foreground' : 'self-start bg-muted'"
                    >
                        <div class="whitespace-pre-line break-words">{{ item.body }}</div>
                        <div class="mt-1 text-xs opacity-75">{{ item.author }} · {{ formatDate(item.created_at) }}</div>
                    </li>
                </ul>
                <form v-if="can_comment" class="flex flex-col gap-2" @submit.prevent="sendComment">
                    <textarea v-model="comment.body" rows="2" maxlength="2000" :class="textareaClass" placeholder="Reply to accounts" />
                    <p v-if="comment.errors.body" class="text-sm text-red-600 dark:text-red-400">{{ comment.errors.body }}</p>
                    <Button type="submit" variant="outline" class="self-end" :disabled="!comment.body.trim() || comment.processing">Send reply</Button>
                </form>
            </section>

            <div class="sticky bottom-0 -mx-4 flex items-center gap-3 border-t bg-background/95 p-4 backdrop-blur">
                <span class="flex-1 text-sm" :class="saveErrors.form ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'" aria-live="polite">
                    {{ saveErrors.form ?? saveLabel }}
                </span>
                <Button v-if="editable" :disabled="saveState === 'saving'" @click="saveAndNext">Save &amp; next</Button>
                <Button v-else-if="nav.next" variant="outline" as-child>
                    <Link :href="route('transactions.show', nav.next)">Next line</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
