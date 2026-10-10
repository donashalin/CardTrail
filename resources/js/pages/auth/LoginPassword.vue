<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
    email: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: props.email,
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase title="Enter your password" :description="`Signing in as ${email}`">
        <Head title="Sign in" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm">Forgot password?</TextLink>
                </div>
                <Input
                    id="password"
                    type="password"
                    required
                    autofocus
                    autocomplete="current-password"
                    v-model="form.password"
                    placeholder="Password"
                />
                <InputError :message="form.errors.email || form.errors.password" />
            </div>

            <Label for="remember" class="flex items-center space-x-3">
                <Checkbox id="remember" v-model:checked="form.remember" />
                <span>Remember me</span>
            </Label>

            <Button type="submit" class="w-full" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                Sign in
            </Button>

            <div class="text-center text-sm text-muted-foreground">
                Not you?
                <TextLink :href="route('login')">Use a different email</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
