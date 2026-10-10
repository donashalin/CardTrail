<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
    email?: string | null;
    status?: string;
    error?: string;
}>();

const form = useForm({
    email: props.email ?? '',
});

const submit = () => {
    form.post(route('login.email'));
};
</script>

<template>
    <AuthBase title="Sign in to CardTrail" description="Enter your work email address to continue">
        <Head title="Sign in" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <div v-if="error" class="mb-4 text-center text-sm font-medium text-red-600">
            {{ error }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    v-model="form.email"
                    placeholder="you@example.com"
                />
                <InputError :message="form.errors.email" />
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                Continue
            </Button>
        </form>
    </AuthBase>
</template>
