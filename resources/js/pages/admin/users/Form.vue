<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface CardIdentifierRow {
    id: number | null;
    last4: string | null;
    cardholder_name: string | null;
}

interface EditableUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_cardholder: boolean;
    use_microsoft: boolean;
    active: boolean;
    card_identifiers: CardIdentifierRow[];
    has_password: boolean;
}

const props = defineProps<{ user: EditableUser | null }>();

const isEdit = computed(() => props.user !== null);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manage users', href: '/admin/users' },
    { title: props.user ? props.user.name : 'Add user', href: '#' },
];

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    is_admin: props.user?.is_admin ?? false,
    is_cardholder: props.user?.is_cardholder ?? true,
    use_microsoft: props.user?.use_microsoft ?? false,
    active: props.user?.active ?? true,
    card_identifiers: (props.user?.card_identifiers ?? [{ id: null, last4: '', cardholder_name: '' }]) as CardIdentifierRow[],
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

const addIdentifier = () => form.card_identifiers.push({ id: null, last4: '', cardholder_name: '' });
const removeIdentifier = (index: number) => form.card_identifiers.splice(index, 1);

const resendInvite = () => {
    if (props.user) {
        router.post(route('admin.users.invite', props.user.id), {}, { preserveScroll: true });
    }
};

const submit = () => {
    if (props.user) {
        form.put(route('admin.users.update', props.user.id));
    } else {
        form.post(route('admin.users.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? `Edit ${user?.name}` : 'Add user'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="flex max-w-2xl flex-col gap-6 p-4">
            <FlashMessage />

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required autocomplete="off" />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Work email</Label>
                <Input id="email" type="email" v-model="form.email" required autocomplete="off" />
                <InputError :message="form.errors.email" />
            </div>

            <fieldset class="grid gap-3">
                <legend class="mb-2 text-sm font-medium">Roles</legend>
                <Label for="is_cardholder" class="flex items-center gap-3">
                    <Checkbox id="is_cardholder" v-model:checked="form.is_cardholder" />
                    <span>Cardholder: reconciles their own card transactions</span>
                </Label>
                <Label for="is_admin" class="flex items-center gap-3">
                    <Checkbox id="is_admin" v-model:checked="form.is_admin" />
                    <span>Admin: accountant access to every user and period</span>
                </Label>
                <InputError :message="form.errors.is_admin" />
            </fieldset>

            <fieldset class="grid gap-3">
                <legend class="mb-2 text-sm font-medium">Sign-in</legend>
                <Label for="use_microsoft" class="flex items-center gap-3">
                    <Checkbox id="use_microsoft" v-model:checked="form.use_microsoft" />
                    <span>Use Microsoft 365 sign-in</span>
                </Label>
                <p class="text-xs text-muted-foreground">
                    When ticked, this person signs in with their work Microsoft account and can't use a password.
                </p>
                <InputError :message="form.errors.use_microsoft" />

                <Label for="active" class="flex items-center gap-3">
                    <Checkbox id="active" v-model:checked="form.active" />
                    <span>Active</span>
                </Label>
                <p class="text-xs text-muted-foreground">Untick to remove access. Their history is kept.</p>
                <InputError :message="form.errors.active" />
            </fieldset>

            <fieldset class="grid gap-3">
                <legend class="mb-1 text-sm font-medium">Card identifiers</legend>
                <p class="text-xs text-muted-foreground">
                    Statement rows are matched to this person by the card's last 4 digits or the cardholder name as it appears on the statement.
                </p>

                <div v-for="(identifier, index) in form.card_identifiers" :key="index" class="grid gap-2 rounded-md border p-3 sm:grid-cols-[8rem_1fr_auto] sm:items-start">
                    <div class="grid gap-1">
                        <Label :for="`last4-${index}`" class="text-xs">Last 4 digits</Label>
                        <Input :id="`last4-${index}`" v-model="identifier.last4" inputmode="numeric" maxlength="4" placeholder="1234" />
                        <InputError :message="errors[`card_identifiers.${index}.last4`]" />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`cardholder-${index}`" class="text-xs">Name on statement</Label>
                        <Input :id="`cardholder-${index}`" v-model="identifier.cardholder_name" placeholder="J SMITH" />
                        <InputError :message="errors[`card_identifiers.${index}.cardholder_name`]" />
                    </div>
                    <Button type="button" variant="ghost" size="icon" class="sm:mt-5" @click="removeIdentifier(index)" aria-label="Remove card identifier">
                        <Trash2 class="size-4" />
                    </Button>
                </div>

                <Button type="button" variant="outline" class="w-fit" @click="addIdentifier">
                    <Plus class="size-4" /> Add card identifier
                </Button>
            </fieldset>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">{{ isEdit ? 'Save changes' : 'Add user' }}</Button>
                <Button variant="ghost" as-child>
                    <Link :href="route('admin.users.index')">Cancel</Link>
                </Button>
                <Button v-if="user && user.active" type="button" variant="outline" class="ml-auto" @click="resendInvite">
                    Resend invitation
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
