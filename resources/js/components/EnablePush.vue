<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { BellRing } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

const DISMISSED = 'cardtrail.push-prompt-dismissed';

const page = usePage<SharedData>();
const visible = ref(false);
const working = ref(false);
const problem = ref<string | null>(null);

const standalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;

const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent);

const remembered = () => {
    try {
        return localStorage.getItem(DISMISSED) === '1';
    } catch {
        return false;
    }
};

onMounted(() => {
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && !!page.props.push.vapid_public_key;

    // iPhones only allow web push from an app added to the Home Screen.
    visible.value = supported && Notification.permission === 'default' && (standalone() || !isIos()) && !remembered();
});

const dismiss = () => {
    visible.value = false;

    try {
        localStorage.setItem(DISMISSED, '1');
    } catch {
        // Private browsing: the prompt may come back next time, which is fine.
    }
};

const keyBytes = (base64: string) => {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0));
};

const enable = async () => {
    working.value = true;
    problem.value = null;

    try {
        if ((await Notification.requestPermission()) !== 'granted') {
            dismiss();

            return;
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyBytes(page.props.push.vapid_public_key as string),
        });
        const json = subscription.toJSON();
        const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings ?? [];

        await axios.post(route('push-subscriptions.store'), {
            endpoint: json.endpoint,
            keys: json.keys,
            content_encoding: encodings.includes('aes128gcm') || encodings.length === 0 ? 'aes128gcm' : 'aesgcm',
        });

        visible.value = false;
    } catch {
        problem.value = 'Couldn’t turn on notifications on this device. You’ll still get emails.';
    } finally {
        working.value = false;
    }
};
</script>

<template>
    <section v-if="visible" class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center">
        <BellRing class="size-5 shrink-0" />
        <div class="flex-1 text-sm">
            <p>Get a notification on this device when a month is ready, a deadline is near, or accounts have a question.</p>
            <p v-if="problem" class="mt-1 text-red-600 dark:text-red-400">{{ problem }}</p>
        </div>
        <div class="flex gap-2">
            <Button :disabled="working" @click="enable">Turn on</Button>
            <Button variant="ghost" @click="dismiss">Not now</Button>
        </div>
    </section>
</template>
