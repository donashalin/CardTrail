<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import ReceiptPicker from '@/components/ReceiptPicker.vue';
import ReceiptThumb from '@/components/ReceiptThumb.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { ReceiptItem } from '@/types/cardtrail';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Mail } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{ receipts: ReceiptItem[]; address: string | null }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Receipt inbox', href: '/receipts' },
];

const upload = useForm<{ files: File[]; source: 'camera' | 'upload' }>({ files: [], source: 'upload' });
const uploadError = computed(() => Object.values(upload.errors)[0] ?? null);

const uploadFiles = (files: File[], source: 'camera' | 'upload') => {
    upload.files = files;
    upload.source = source;
    upload.post(route('receipts.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => upload.reset() });
};

const remove = (receipt: ReceiptItem) => {
    if (confirm(`Delete ${receipt.name}? This can’t be undone.`)) {
        router.delete(route('receipts.destroy', receipt.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Receipt inbox" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div>
                <h1 class="text-xl font-semibold">Receipt inbox</h1>
                <p class="text-sm text-muted-foreground">Upload receipts as you get them, then match each one to a line when the month is published.</p>
            </div>

            <div v-if="address" class="flex items-start gap-3 rounded-xl border p-4 text-sm">
                <Mail class="mt-0.5 size-5 shrink-0" />
                <p>
                    You can also email receipts to <strong class="break-all">{{ address }}</strong> from your work email address. Attachments land here.
                </p>
            </div>

            <ReceiptPicker :processing="upload.processing" @selected="uploadFiles" />
            <p v-if="upload.progress" class="text-sm text-muted-foreground">Uploading… {{ upload.progress.percentage }}%</p>
            <p v-if="uploadError" class="text-sm text-red-600 dark:text-red-400">{{ uploadError }}</p>

            <div v-if="receipts.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                <ReceiptThumb v-for="receipt in receipts" :key="receipt.id" :receipt="receipt">
                    <template #actions>
                        <Link :href="route('receipts.match', receipt.id)" class="flex-1 px-2 py-1.5 text-center font-medium hover:bg-accent">Match</Link>
                        <button type="button" class="flex-1 px-2 py-1.5 text-red-600 hover:bg-accent dark:text-red-400" @click="remove(receipt)">Delete</button>
                    </template>
                </ReceiptThumb>
            </div>
            <p v-else class="rounded-xl border p-4 text-sm text-muted-foreground">Your inbox is empty.</p>
        </div>
    </AppLayout>
</template>
