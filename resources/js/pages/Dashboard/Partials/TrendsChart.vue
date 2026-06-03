<script setup lang="ts">
import { computed } from 'vue';
import { BarChart2 } from 'lucide-vue-next';

const props = defineProps<{
    data: Array<{ date: string; count: number; label: string }>;
}>();

const maxCount = computed(() => Math.max(...props.data.map(d => d.count), 1));

const barWidth = computed(() => {
    const len = props.data.length;
    if (len <= 7) return 32;
    if (len <= 15) return 16;
    return 8;
});

const chartHeight = 140;
const barGap = 6;

const totalWidth = computed(() =>
    props.data.length * (barWidth.value + barGap) + 40
);
</script>

<template>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center bg-gray-50/30">
            <BarChart2 class="w-4 h-4 mr-2 text-gray-400" />
            <h3 class="text-sm font-semibold text-gray-900">Tendencias de Pólizas</h3>
        </div>
        <div v-if="data && data.length > 0" class="p-6 overflow-x-auto flex justify-center">
            <svg :width="totalWidth" :height="chartHeight + 30" class="mx-auto overflow-visible">
                <!-- Línea base sutil -->
                <line x1="20" :y1="chartHeight" :x2="totalWidth - 20" :y2="chartHeight" stroke="#e5e7eb" stroke-width="1" stroke-dasharray="4 4" />

                <!-- Barras -->
                <g v-for="(item, idx) in data" :key="idx">
                    <rect
                        :x="20 + idx * (barWidth + barGap)"
                        :y="chartHeight - (item.count / maxCount) * (chartHeight - 10)"
                        :width="barWidth"
                        :height="(item.count / maxCount) * (chartHeight - 10)"
                        rx="3"
                        class="transition-all duration-300"
                        :class="item.count === maxCount ? 'fill-gray-800' : 'fill-gray-200 hover:fill-gray-300'"
                    />
                    <!-- Label -->
                    <text
                        v-if="data.length <= 15"
                        :x="20 + idx * (barWidth + barGap) + barWidth / 2"
                        :y="chartHeight + 20"
                        text-anchor="middle"
                        class="text-[11px] fill-gray-400 font-medium"
                    >{{ item.label }}</text>
                </g>
            </svg>
        </div>
        <div v-else class="px-5 py-12 text-center text-gray-400 text-sm">Sin datos de tendencias en este periodo.</div>
    </div>
</template>