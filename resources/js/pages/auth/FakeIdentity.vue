<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ email?: string | null }>();

const form = useForm({
    email: props.email ?? '',
    external_id: '',
});

const submit = () => {
    form.post(route('identity.fake.store'));
};
</script>

<template>
    <AuthBase title="Fake Microsoft 365 sign-in" description="Local testing only. This stands in for the company sign-in page.">
        <Head title="Fake sign-in" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <Label for="email">Directory email</Label>
                <Input id="email" type="email" required autofocus v-model="form.email" />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="external_id">Directory ID (optional)</Label>
                <Input id="external_id" v-model="form.external_id" placeholder="Leave blank to derive one from the email" />
                <InputError :message="form.errors.external_id" />
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">Sign in</Button>
        </form>
    </AuthBase>
</template>
