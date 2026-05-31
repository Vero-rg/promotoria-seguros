<script setup lang="ts">
import { computed } from 'vue';

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

const chartHeight = 160;
const barGap = 4;

const totalWidth = computed(() =>
    props.data.length * (barWidth.value + barGap) + 40
);
</script>

<template>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Tendencias de Pólizas</h3>
        </div>
        <div v-if="data && data.length > 0" class="p-5 overflow-x-auto">
            <svg :width="totalWidth" :height="chartHeight + 40" class="mx-auto">
                <!-- Línea base sutil -->
                <line x1="20" :y1="chartHeight" :x2="totalWidth - 20" :y2="chartHeight" stroke="#f3f4f6" stroke-width="1" />

                <!-- Barras -->
                <g v-for="(item, idx) in data" :key="idx">
                    <rect
                        :x="20 + idx * (barWidth + barGap)"
                        :y="chartHeight - (item.count / maxCount) * (chartHeight - 20)"
                        :width="barWidth"
                        :height="(item.count / maxCount) * (chartHeight - 20)"
                        rx="3"
                        class="transition-all duration-300"
                        :class="item.count === maxCount ? 'fill-[#4a7c59]' : 'fill-[#6a9e7a]'"
                    />
                    <!-- Label -->
                    <text
                        v-if="data.length <= 15"
                        :x="20 + idx * (barWidth + barGap) + barWidth / 2"
                        :y="chartHeight + 18"
                        text-anchor="middle"
                        class="text-[10px] fill-gray-400"
                    >{{ item.label }}</text>
                </g>
            </svg>
        </div>
        <div v-else class="px-5 py-10 text-center text-gray-400 text-sm">Sin datos de tendencias en este periodo.</div>
    </div>
</template>
