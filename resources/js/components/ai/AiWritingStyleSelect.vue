<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AppPageProps } from '@/types';

withDefaults(
    defineProps<{
        disabled?: boolean;
        testId: string;
    }>(),
    { disabled: false },
);
const selection = defineModel<string>({ required: true });
const page = usePage<AppPageProps>();
const options = computed(
    () => page.props.aiWritingStyles?.options ?? ['default'],
);
const visible = computed(() => options.value.length > 1);
</script>

<template>
    <div v-if="visible" class="space-y-1.5">
        <label :for="testId" class="text-xs font-medium text-muted-foreground">
            {{ $t('posts.composer.ai_style') }}
        </label>
        <Select v-model="selection" :disabled="disabled">
            <SelectTrigger
                :id="testId"
                :data-testid="testId"
                class="w-full min-w-0"
            >
                <SelectValue>
                    {{ $t(`posts.composer.ai_style_${selection}`) }}
                </SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="id in options"
                    :key="id"
                    :value="id"
                    :data-testid="`${testId}-${id}`"
                >
                    {{ $t(`posts.composer.ai_style_${id}`) }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
