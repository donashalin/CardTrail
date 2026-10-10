<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';

interface CardIdentifier {
    id: number;
    last4: string | null;
    cardholder_name: string | null;
}

interface AdminUserRow {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_cardholder: boolean;
    use_microsoft: boolean;
    active: boolean;
    card_identifiers: CardIdentifier[];
}

interface Paginated<T> {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    users: Paginated<AdminUserRow>;
    filters: { q: string; inactive: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Manage users', href: '/admin/users' }];

const q = ref(props.filters.q);
const inactive = ref(props.filters.inactive);

const reload = () =>
    router.get(
        route('admin.users.index'),
        { q: q.value || undefined, inactive: inactive.value ? 1 : undefined },
        { preserveState: true, replace: true },
    );

watch(q, useDebounceFn(reload, 300));
watch(inactive, reload);

const cardLabel = (card: CardIdentifier) => [card.last4 ? `•••• ${card.last4}` : null, card.cardholder_name].filter(Boolean).join(' · ');

const roles = (user: AdminUserRow) => [user.is_admin ? 'Admin' : null, user.is_cardholder ? 'Cardholder' : null].filter(Boolean).join(', ') || 'No role';
</script>

<template>
    <Head title="Manage users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                    <Input v-model="q" type="search" placeholder="Search name or email" class="sm:max-w-xs" />
                    <Label for="inactive" class="flex items-center gap-2">
                        <Checkbox id="inactive" v-model:checked="inactive" />
                        <span>Show inactive</span>
                    </Label>
                </div>
                <Button as-child>
                    <Link :href="route('admin.users.create')">Add user</Link>
                </Button>
            </div>

            <!-- Phone: stacked cards -->
            <ul class="divide-y rounded-lg border md:hidden">
                <li v-for="user in users.data" :key="user.id">
                    <Link :href="route('admin.users.edit', user.id)" class="block p-4" :class="{ 'opacity-60': !user.active }">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ user.name }}</span>
                            <span v-if="!user.active" class="text-xs text-muted-foreground">Inactive</span>
                        </div>
                        <div class="text-sm text-muted-foreground">{{ user.email }}</div>
                        <div class="mt-1 text-xs text-muted-foreground">
                            {{ roles(user) }} · {{ user.use_microsoft ? 'Microsoft 365' : 'Password' }}
                        </div>
                        <div v-if="user.card_identifiers.length" class="mt-1 text-xs">
                            {{ user.card_identifiers.map(cardLabel).join(', ') }}
                        </div>
                    </Link>
                </li>
                <li v-if="!users.data.length" class="p-4 text-sm text-muted-foreground">No users found.</li>
            </ul>

            <!-- Desktop: table -->
            <table class="hidden w-full text-left text-sm md:table">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2 pr-4 font-medium">Name</th>
                        <th class="py-2 pr-4 font-medium">Email</th>
                        <th class="py-2 pr-4 font-medium">Roles</th>
                        <th class="py-2 pr-4 font-medium">Cards</th>
                        <th class="py-2 pr-4 font-medium">Sign-in</th>
                        <th class="py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.id" class="border-b" :class="{ 'opacity-60': !user.active }">
                        <td class="py-2 pr-4">
                            <Link :href="route('admin.users.edit', user.id)" class="font-medium underline-offset-4 hover:underline">{{ user.name }}</Link>
                        </td>
                        <td class="py-2 pr-4">{{ user.email }}</td>
                        <td class="py-2 pr-4">{{ roles(user) }}</td>
                        <td class="py-2 pr-4">{{ user.card_identifiers.map(cardLabel).join(', ') || '—' }}</td>
                        <td class="py-2 pr-4">{{ user.use_microsoft ? 'Microsoft 365' : 'Password' }}</td>
                        <td class="py-2">{{ user.active ? 'Active' : 'Inactive' }}</td>
                    </tr>
                    <tr v-if="!users.data.length">
                        <td colspan="6" class="py-4 text-muted-foreground">No users found.</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="users.total > 0" class="flex items-center justify-between text-sm text-muted-foreground">
                <span>{{ users.from }}–{{ users.to }} of {{ users.total }}</span>
                <div class="flex gap-2">
                    <Button v-if="users.prev_page_url" variant="outline" size="sm" as-child>
                        <Link :href="users.prev_page_url" preserve-state>Previous</Link>
                    </Button>
                    <Button v-if="users.next_page_url" variant="outline" size="sm" as-child>
                        <Link :href="users.next_page_url" preserve-state>Next</Link>
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
