<script setup lang="ts">
import { TrendingUp, TrendingDown, Minus } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    label: string;
    value: string | number;
    prevValue?: string | number;
    icon: any;
    growth?: number;
    color?: string;
    subtitle?: string;
    detail?: Array<{ label: string; current: string | number; prev?: string | number }>;
}>();

const growthAbs = computed(() => props.growth ? Math.abs(props.growth) : 0);
const isPositive = computed(() => (props.growth ?? 0) > 0);
const isNeutral = computed(() => (props.growth ?? 0) === 0);
</script>

<template>
    <div class="bg-white/80 backdrop-blur-xl rounded-3xl shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] border border-white/50 p-6 hover:shadow-[0_8px_30px_-4px_rgba(0,0,0,0.08)] hover:-translate-y-0.5 transition-all duration-300 group cursor-default">
        <div class="flex items-start justify-between mb-4">
            <p class="text-xs font-semibold text-gray-400/80 uppercase tracking-wider">{{ label }}</p>
            <div class="p-3 rounded-2xl transition-colors shadow-sm" :class="color || 'bg-gray-50'">
                <component :is="icon" class="w-5 h-5" :class="(color || 'bg-gray-50').replace('bg-', 'text-').replace('/10', '').replace('/20', '')" />
            </div>
        </div>

        <div class="space-y-1.5">
            <p class="text-3xl font-semibold text-gray-800 tracking-tight">{{ value }}</p>

            <!-- Indicador de crecimiento vs periodo anterior -->
            <div v-if="growth !== undefined" class="flex items-center space-x-1">
                <TrendingUp v-if="isPositive" class="w-3.5 h-3.5 text-[#4a7c59]" />
                <TrendingDown v-else-if="!isNeutral" class="w-3.5 h-3.5 text-red-500" />
                <Minus v-else class="w-3.5 h-3.5 text-gray-400" />
                <span class="text-xs font-medium" :class="isPositive ? 'text-[#4a7c59]' : (!isNeutral ? 'text-red-500' : 'text-gray-400')">
                    {{ growthAbs }}% vs periodo anterior
                </span>
            </div>

            <!-- Valor anterior -->
            <p v-if="prevValue !== undefined" class="text-xs text-gray-400">
                Anterior: {{ prevValue }}
            </p>

            <p v-if="subtitle" class="text-xs text-gray-400">{{ subtitle }}</p>
        </div>

        <!-- Detalle / Desglose expandible -->
        <div v-if="detail && detail.length > 0" class="mt-3 pt-3 border-t border-gray-50 space-y-1.5">
            <div v-for="(d, idx) in detail" :key="idx" class="flex justify-between text-xs">
                <span class="text-gray-400">{{ d.label }}</span>
                <div class="flex items-center space-x-2">
                    <span class="font-medium text-gray-700">{{ d.current }}</span>
                    <span v-if="d.prev !== undefined" class="text-gray-300">
                        ← {{ d.prev }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
