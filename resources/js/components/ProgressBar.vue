<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ value: number; max: number }>();

const percent = computed(() => (props.max > 0 ? Math.round((props.value / props.max) * 100) : 0));
</script>

<template>
    <div class="h-2 w-full overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="value" aria-valuemin="0" :aria-valuemax="max">
        <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${percent}%` }" />
    </div>
</template>
