<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

type Outcome = 'accepted' | 'unknown_sender' | 'no_attachments' | 'rejected_type';

interface InboundRow {
    id: number;
    from_email: string | null;
    subject: string | null;
    user: string | null;
    outcome: Outcome;
    attachment_count: number;
    accepted_count: number;
    received_at: string;
}

defineProps<{
    address: string | null;
    messages: { data: InboundRow[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inbound email', href: '/admin/inbound' }];

const labels: Record<Outcome, string> = {
    accepted: 'Accepted',
    unknown_sender: 'Unknown sender',
    no_attachments: 'No attachments',
    rejected_type: 'Not a receipt type',
};

const styles: Record<Outcome, string> = {
    accepted: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200',
    unknown_sender: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
    no_attachments: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
    rejected_type: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
};
</script>

<template>
    <Head title="Inbound email" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Inbound email</h1>
                <p class="text-sm text-muted-foreground">
                    What happened to each email sent to
                    <strong v-if="address">{{ address }}</strong>
                    <span v-else>the receipts address (not set up yet)</span>. Nobody gets a reply when an email is turned away.
                </p>
            </div>

            <ul class="divide-y rounded-lg border">
                <li v-for="message in messages.data" :key="message.id" class="flex flex-col gap-1 p-4 text-sm sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ message.subject || '(no subject)' }}</div>
                        <div class="truncate text-muted-foreground">
                            {{ message.user ?? message.from_email ?? 'Unknown sender' }} · {{ formatDate(message.received_at) }}
                            <span v-if="message.attachment_count"> · {{ message.accepted_count }} of {{ message.attachment_count }} attachments kept</span>
                        </div>
                    </div>
                    <span class="inline-flex shrink-0 items-center self-start rounded-full px-2 py-0.5 text-xs font-medium sm:self-auto" :class="styles[message.outcome]">
                        {{ labels[message.outcome] }}
                    </span>
                </li>
                <li v-if="!messages.data.length" class="p-4 text-sm text-muted-foreground">No emails yet.</li>
            </ul>

            <div v-if="messages.last_page > 1" class="flex items-center justify-between text-sm">
                <Button variant="outline" size="sm" :disabled="!messages.prev_page_url" as-child>
                    <Link :href="messages.prev_page_url ?? '#'" preserve-scroll>Newer</Link>
                </Button>
                <span class="text-muted-foreground">Page {{ messages.current_page }} of {{ messages.last_page }}</span>
                <Button variant="outline" size="sm" :disabled="!messages.next_page_url" as-child>
                    <Link :href="messages.next_page_url ?? '#'" preserve-scroll>Older</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
