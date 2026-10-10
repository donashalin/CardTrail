<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { ACCEPT, checkFiles } from '@/lib/receipts';
import { Camera, Upload } from 'lucide-vue-next';
import { ref } from 'vue';

withDefaults(defineProps<{ processing?: boolean; multiple?: boolean }>(), { processing: false, multiple: true });

const emit = defineEmits<{ selected: [files: File[], source: 'camera' | 'upload'] }>();

const cameraInput = ref<HTMLInputElement>();
const fileInput = ref<HTMLInputElement>();
const problem = ref<string | null>(null);

const picked = (event: Event, source: 'camera' | 'upload') => {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';

    if (!files.length) {
        return;
    }

    problem.value = checkFiles(files);

    if (!problem.value) {
        emit('selected', files, source);
    }
};
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap gap-2">
            <Button type="button" variant="outline" :disabled="processing" @click="cameraInput?.click()">
                <Camera class="size-4" />
                Take photo
            </Button>
            <Button type="button" variant="outline" :disabled="processing" @click="fileInput?.click()">
                <Upload class="size-4" />
                Choose {{ multiple ? 'files' : 'file' }}
            </Button>
        </div>
        <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" @change="picked($event, 'camera')" />
        <input ref="fileInput" type="file" :accept="ACCEPT" :multiple="multiple" class="hidden" @change="picked($event, 'upload')" />
        <p v-if="problem" class="text-sm text-red-600 dark:text-red-400">{{ problem }}</p>
    </div>
</template>
