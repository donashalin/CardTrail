<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const page = usePage<SharedData>();
const user = page.props.auth.user;
const firstName = user.name.split(' ')[0];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Hello, {{ firstName }}</h1>

            <section v-if="user.is_cardholder" class="rounded-xl border p-4">
                <h2 class="font-medium">Your card reconciliations</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    When the accounts team publishes a month, your transactions will appear here ready to complete.
                </p>
            </section>

            <section v-if="user.is_admin" class="rounded-xl border p-4">
                <h2 class="font-medium">Admin</h2>
                <p class="mt-1 text-sm text-muted-foreground">Add cardholders and their card identifiers before the first statement upload.</p>
                <Button class="mt-3" as-child>
                    <Link :href="route('admin.users.index')">Manage users</Link>
                </Button>
            </section>
        </div>
    </AppLayout>
</template>
