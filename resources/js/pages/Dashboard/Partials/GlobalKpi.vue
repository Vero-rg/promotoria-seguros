<script setup lang="ts">
import { TrendingUp, TrendingDown, Minus } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    label: string;
    value: string | number;
    prevValue?: string | number;
    icon: any;
    growth?: number;
    subtitle?: string;
    iconBgClass?: string;
    iconColorClass?: string;
    detail?: Array<{ label: string; current: string | number; prev?: string | number }>;
}>();

const growthAbs = computed(() => props.growth ? Math.abs(props.growth) : 0);
const isPositive = computed(() => (props.growth ?? 0) > 0);
const isNeutral = computed(() => (props.growth ?? 0) === 0);
</script>

<template>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 transition-shadow hover:shadow-md flex flex-col gap-3">
        <!-- Cabecera: Icono con bg de color + Label -->
        <div class="flex items-center space-x-3">
            <div :class="iconBgClass || 'bg-gray-50'" class="p-2.5 rounded-xl flex items-center justify-center">
                <component :is="icon" :class="iconColorClass || 'text-gray-500'" class="w-5 h-5" stroke-width="1.8" />
            </div>
            <p class="text-sm font-medium text-gray-500">{{ label }}</p>
        </div>

        <!-- Valor Principal -->
        <div>
            <p class="text-[26px] font-bold text-gray-900 tracking-tight">{{ value }}</p>

            <!-- Indicador de crecimiento o valor anterior -->
            <div v-if="growth !== undefined" class="flex items-center space-x-1.5 mt-1.5">
                <div class="flex items-center justify-center p-0.5 rounded" :class="isPositive ? 'bg-emerald-50 text-emerald-600' : (!isNeutral ? 'bg-red-50 text-red-600' : 'bg-gray-100 text-gray-500')">
                    <TrendingUp v-if="isPositive" class="w-3 h-3" stroke-width="2.5" />
                    <TrendingDown v-else-if="!isNeutral" class="w-3 h-3" stroke-width="2.5" />
                    <Minus v-else class="w-3 h-3" stroke-width="2.5" />
                </div>
                <span class="text-xs font-semibold" :class="isPositive ? 'text-emerald-600' : (!isNeutral ? 'text-red-600' : 'text-gray-500')">
                    {{ growthAbs }}%
                </span>
                <span class="text-xs text-gray-400">vs periodo anterior</span>
            </div>
            <div v-else-if="prevValue !== undefined" class="mt-1.5">
                <span class="text-xs text-gray-400">Anterior: {{ prevValue }}</span>
            </div>
            <div v-else-if="subtitle" class="mt-1.5">
                <span class="text-xs text-gray-400">{{ subtitle }}</span>
            </div>
        </div>

        <!-- Detalle / Desglose -->
        <div v-if="detail && detail.length > 0" class="mt-1 pt-3 border-t border-gray-100">
            <div class="grid gap-2">
                <div v-for="(d, idx) in detail" :key="idx" class="flex justify-between items-center">
                    <span class="text-xs text-gray-400 font-medium">{{ d.label }}</span>
                    <div class="flex items-center gap-2">
                        <span v-if="d.prev !== undefined" class="text-xs text-gray-300 line-through decoration-gray-300">
                            {{ d.prev }}
                        </span>
                        <span class="text-sm font-semibold text-gray-800">{{ d.current }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>