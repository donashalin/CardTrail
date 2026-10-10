<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import PeriodStatusBadge from '@/components/PeriodStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface PeriodRow {
    id: number;
    label: string;
    due_date: string;
    status: 'draft' | 'published' | 'closed';
    transactions_count: number;
}

defineProps<{ periods: PeriodRow[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Periods', href: '/admin/periods' }];
</script>

<template>
    <Head title="Periods" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Periods</h1>
                <Button as-child>
                    <Link :href="route('admin.periods.create')">New period</Link>
                </Button>
            </div>

            <ul class="divide-y rounded-lg border">
                <li v-for="period in periods" :key="period.id">
                    <Link :href="route('admin.periods.show', period.id)" class="flex items-center justify-between gap-4 p-4 hover:bg-muted/50">
                        <div>
                            <div class="font-medium">{{ period.label }}</div>
                            <div class="text-sm text-muted-foreground">
                                Due {{ formatDate(period.due_date) }} · {{ period.transactions_count }} lines
                            </div>
                        </div>
                        <PeriodStatusBadge :status="period.status" />
                    </Link>
                </li>
                <li v-if="!periods.length" class="p-4 text-sm text-muted-foreground">No periods yet. Create one to upload a statement.</li>
            </ul>
        </div>
    </AppLayout>
</template>
