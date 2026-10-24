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

const props = withDefaults(
    defineProps<{
        capability?: 'text' | 'structured' | 'vision';
        disabled?: boolean;
        testId: string;
    }>(),
    { capability: 'text', disabled: false },
);
const selection = defineModel<string | null>({ required: true });
const page = usePage<AppPageProps>();
const options = computed(() =>
    (page.props.aiModels?.options ?? []).filter((option) =>
        option.capabilities.includes(props.capability),
    ),
);
const selected = computed(() =>
    options.value.find((option) => option.id === selection.value),
);
const visible = computed(
    () => !(options.value.length === 1 && options.value[0].id === 'legacy'),
);
</script>

<template>
    <div v-if="visible" class="space-y-1.5">
        <label :for="testId" class="text-xs font-medium text-muted-foreground">
            {{ $t('posts.composer.ai_model') }}
        </label>
        <Select v-model="selection" :disabled="disabled">
            <SelectTrigger
                :id="testId"
                :data-testid="testId"
                class="w-full min-w-0"
            >
                <SelectValue>
                    {{
                        selected?.label ??
                        $t('posts.composer.ai_model_unavailable_option')
                    }}
                </SelectValue>
                <span
                    v-if="selected"
                    class="ms-auto text-xs text-muted-foreground"
                >
                    {{
                        $t(
                            `posts.composer.ai_model_destination_${selected.destination}`,
                        )
                    }}
                </span>
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="option in options"
                    :key="option.id"
                    :value="option.id"
                    :data-testid="`${testId}-${option.id}`"
                >
                    <span>{{ option.label }}</span>
                    <span class="ms-2 text-xs text-muted-foreground">
                        {{
                            $t(
                                `posts.composer.ai_model_destination_${option.destination}`,
                            )
                        }}
                    </span>
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
