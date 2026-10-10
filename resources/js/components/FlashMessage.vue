<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<{ flash: { success: string | null; error: string | null } }>();
const flash = computed(() => page.props.flash);
</script>

<template>
    <div v-if="flash.success" class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
        {{ flash.success }}
    </div>
    <div v-if="flash.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
        {{ flash.error }}
    </div>
</template>
