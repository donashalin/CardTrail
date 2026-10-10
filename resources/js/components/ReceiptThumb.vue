<script setup lang="ts">
import { formatBytes } from '@/lib/receipts';
import type { ReceiptItem } from '@/types/cardtrail';
import { FileText } from 'lucide-vue-next';

defineProps<{ receipt: ReceiptItem }>();
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-lg border">
        <a :href="receipt.url" target="_blank" rel="noopener" class="block aspect-[3/4] bg-muted" :title="receipt.name">
            <img v-if="receipt.is_image" :src="receipt.url" :alt="receipt.name" loading="lazy" class="h-full w-full object-cover" />
            <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-2 text-center">
                <FileText class="size-8 text-muted-foreground" />
                <span class="line-clamp-3 break-all text-xs">{{ receipt.name }}</span>
                <span class="text-xs text-muted-foreground">{{ formatBytes(receipt.size) }}</span>
            </div>
        </a>
        <div v-if="$slots.actions" class="flex divide-x border-t text-xs">
            <slot name="actions" />
        </div>
    </div>
</template>
