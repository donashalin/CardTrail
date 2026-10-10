<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface Mapping {
    id: number;
    name: string;
    header_row: number;
    created_at: string;
}

const props = defineProps<{ reminder_offsets: string; mappings: Mapping[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin settings', href: '/admin/settings' }];

const form = useForm({ reminder_offsets: props.reminder_offsets });
const save = () => form.put(route('admin.settings.update'), { preserveScroll: true });

const names = reactive<Record<number, string>>(Object.fromEntries(props.mappings.map((mapping) => [mapping.id, mapping.name])));

const rename = (mapping: Mapping) => {
    router.put(route('admin.column-mappings.update', mapping.id), { name: names[mapping.id] }, { preserveScroll: true });
};

const forget = (mapping: Mapping) => {
    if (confirm(`Forget “${mapping.name}”? The next file with those headers will ask for its columns again.`)) {
        router.delete(route('admin.column-mappings.destroy', mapping.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Admin settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-2xl flex-col gap-6 p-4">
            <FlashMessage />
            <h1 class="text-xl font-semibold">Admin settings</h1>

            <form class="flex flex-col gap-2 rounded-xl border p-4" @submit.prevent="save">
                <Label for="reminder_offsets">Default reminder days for new periods</Label>
                <Input id="reminder_offsets" v-model="form.reminder_offsets" placeholder="3, 0" />
                <p class="text-xs text-muted-foreground">Days before the due date, separated by commas. 0 means on the day itself.</p>
                <p v-if="form.errors.reminder_offsets" class="text-sm text-red-600 dark:text-red-400">{{ form.errors.reminder_offsets }}</p>
                <Button type="submit" class="self-start" :disabled="form.processing">Save</Button>
            </form>

            <section class="flex flex-col gap-2">
                <h2 class="font-medium">Saved column matches</h2>
                <p class="text-sm text-muted-foreground">Used automatically when an upload has the same column headings.</p>
                <ul class="divide-y rounded-lg border">
                    <li v-for="mapping in mappings" :key="mapping.id" class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
                        <Input v-model="names[mapping.id]" class="sm:flex-1" :aria-label="`Name for ${mapping.name}`" />
                        <span class="text-xs text-muted-foreground">Headers on row {{ mapping.header_row }} · saved {{ formatDate(mapping.created_at) }}</span>
                        <div class="flex gap-2">
                            <Button size="sm" variant="outline" :disabled="!names[mapping.id]?.trim() || names[mapping.id] === mapping.name" @click="rename(mapping)">Rename</Button>
                            <Button size="sm" variant="ghost" @click="forget(mapping)">Forget</Button>
                        </div>
                    </li>
                    <li v-if="!mappings.length" class="p-4 text-sm text-muted-foreground">No saved column matches yet. They’re saved when you confirm an upload.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
