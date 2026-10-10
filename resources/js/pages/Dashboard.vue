<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import ProgressBar from '@/components/ProgressBar.vue';
import ReconciliationStatusBadge from '@/components/ReconciliationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { ReconciliationSummary } from '@/types/cardtrail';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Inbox, MessageCircleQuestion } from 'lucide-vue-next';

defineProps<{
    current: ReconciliationSummary[];
    past: ReconciliationSummary[];
    inbox_count: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const page = usePage<SharedData>();
const user = page.props.auth.user;
const firstName = user.name.split(' ')[0];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />
            <h1 class="text-xl font-semibold">Hello, {{ firstName }}</h1>

            <template v-if="user.is_cardholder || current.length || past.length">
                <section v-for="item in current" :key="item.id" class="rounded-xl border p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h2 class="font-medium">{{ item.period.label }}</h2>
                            <p class="text-sm text-muted-foreground">
                                Due {{ formatDate(item.period.due_date) }}
                                <span v-if="item.overdue" class="font-medium text-red-600 dark:text-red-400"> · Overdue</span>
                            </p>
                        </div>
                        <ReconciliationStatusBadge :status="item.status" />
                    </div>
                    <ProgressBar class="mt-3" :value="item.done" :max="item.lines" />
                    <p class="mt-1 text-sm text-muted-foreground">{{ item.done }} of {{ item.lines }} lines done</p>
                    <p v-if="item.queried" class="mt-2 flex items-center gap-1 text-sm font-medium text-amber-700 dark:text-amber-300">
                        <MessageCircleQuestion class="size-4" />
                        {{ item.queried }} {{ item.queried === 1 ? 'question' : 'questions' }} from accounts
                    </p>
                    <Button class="mt-3 w-full sm:w-auto" as-child>
                        <Link :href="route('reconciliations.show', item.id)">{{ item.status === 'in_progress' ? 'Continue' : 'View' }}</Link>
                    </Button>
                </section>

                <section v-if="!current.length" class="rounded-xl border p-4">
                    <h2 class="font-medium">Your card reconciliations</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Nothing to do right now. When accounts publish a month, your transactions will appear here.</p>
                </section>

                <Link
                    v-if="inbox_count"
                    :href="route('receipts.index')"
                    class="flex items-center gap-3 rounded-xl border p-4 text-sm hover:bg-accent"
                >
                    <Inbox class="size-5 shrink-0" />
                    <span>
                        You have {{ inbox_count }} {{ inbox_count === 1 ? 'receipt' : 'receipts' }} in your inbox waiting to be matched to a line.
                    </span>
                </Link>

                <section v-if="past.length" class="rounded-xl border">
                    <h2 class="border-b p-4 font-medium">Past months</h2>
                    <ul class="divide-y">
                        <li v-for="item in past" :key="item.id">
                            <Link :href="route('reconciliations.show', item.id)" class="flex items-center justify-between gap-2 p-4 text-sm hover:bg-accent">
                                <span>{{ item.period.label }}</span>
                                <ReconciliationStatusBadge :status="item.status" />
                            </Link>
                        </li>
                    </ul>
                </section>
            </template>

            <section v-if="user.is_admin" class="rounded-xl border p-4">
                <h2 class="font-medium">Admin</h2>
                <p class="mt-1 text-sm text-muted-foreground">Create a period for each statement month, upload the export, and track who has finished.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button as-child>
                        <Link :href="route('admin.periods.index')">Periods</Link>
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('admin.users.index')">Manage users</Link>
                    </Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
