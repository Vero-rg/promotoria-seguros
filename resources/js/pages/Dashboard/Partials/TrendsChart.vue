<script setup lang="ts">
import { BarChart2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type TrendPoint = { date: string; label: string; count: number; premium: number };

const props = defineProps<{
    data: TrendPoint[];
    grouping?: 'day' | 'month';
}>();

const metric = ref<'count' | 'premium'>('count');

const padding = { top: 16, right: 24, bottom: 34, left: 60 };
const plotHeight = 170;
const plotBottom = padding.top + plotHeight;

const formatNumber = (value: number) => new Intl.NumberFormat('es-MX').format(value);

const formatCurrency = (value: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 }).format(value);

const formatCompactCurrency = (value: number) => {
    if (value >= 1_000_000) return `$${(value / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
    if (value >= 1_000) return `$${Math.round(value / 1_000)}k`;
    return `$${Math.round(value)}`;
};

const groupingLabel = computed(() => (props.grouping === 'month' ? 'Ventas por mes' : 'Ventas por día'));

const values = computed(() => props.data.map((item) => (metric.value === 'count' ? item.count : item.premium)));

const totalCount = computed(() => props.data.reduce((sum, item) => sum + item.count, 0));
const totalPremium = computed(() => props.data.reduce((sum, item) => sum + item.premium, 0));

const bestPoint = computed(() => {
    if (props.data.length === 0) return null;

    return props.data.reduce((best, item) => {
        const value = metric.value === 'count' ? item.count : item.premium;
        const bestValue = metric.value === 'count' ? best.count : best.premium;
        return value > bestValue ? item : best;
    });
});

const bestPointLabel = computed(() => {
    if (!bestPoint.value) return '';

    return metric.value === 'count' ? `${bestPoint.value.count} póliza(s)` : formatCurrency(bestPoint.value.premium);
});

/** Redondea el máximo del eje a 1, 2 o 5 × 10^n para que las marcas sean legibles. */
const niceMax = (value: number) => {
    if (value <= 0) return 1;

    const exponent = Math.floor(Math.log10(value));
    const base = Math.pow(10, exponent);
    const normalized = value / base;
    const factor = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;

    return factor * base;
};

const scaleMax = computed(() => {
    const raw = Math.max(...values.value, 0);

    if (metric.value === 'count') {
        return Math.max(4, Math.ceil(raw / 4) * 4);
    }

    return niceMax(raw);
});
const ticks = computed(() => Array.from({ length: 5 }, (_, index) => (scaleMax.value * index) / 4));

const slotWidth = computed(() => {
    const length = props.data.length;
    if (length <= 7) return 64;
    if (length <= 15) return 44;
    if (length <= 31) return 30;
    return 24;
});

const barWidth = computed(() => Math.max(6, Math.min(38, slotWidth.value * 0.58)));
const chartWidth = computed(() => Math.max(360, padding.left + padding.right + props.data.length * slotWidth.value));
const chartHeight = plotBottom + padding.bottom;

const yFor = (value: number) => plotBottom - (value / scaleMax.value) * plotHeight;

const barX = (index: number) => padding.left + index * slotWidth.value + (slotWidth.value - barWidth.value) / 2;

const barHeight = (value: number) => (value > 0 ? Math.max(2, (value / scaleMax.value) * plotHeight) : 0);

const isPeak = (value: number) => value > 0 && value === Math.max(...values.value);

const labelStep = computed(() => {
    const length = props.data.length;
    if (length <= 16) return 1;
    return Math.ceil(length / 12);
});

const showXLabel = (index: number) => index % labelStep.value === 0 || index === props.data.length - 1;

const showValueLabel = computed(() => props.data.length <= 8);

const axisLabel = (value: number) => (metric.value === 'count' ? formatNumber(Math.round(value)) : formatCompactCurrency(value));

const selectedIndex = ref<number | null>(null);

const selectedPoint = computed(() => (selectedIndex.value === null ? null : (props.data[selectedIndex.value] ?? null)));

const selectedShare = computed(() => {
    if (!selectedPoint.value || totalPremium.value <= 0) return 0;

    return (selectedPoint.value.premium / totalPremium.value) * 100;
});

const toggleSelection = (index: number) => {
    selectedIndex.value = selectedIndex.value === index ? null : index;
};

const pluralize = (value: number, singular: string, plural: string) => `${formatNumber(value)} ${value === 1 ? singular : plural}`;

watch(
    () => props.data.map((item) => item.date).join('|'),
    () => {
        selectedIndex.value = null;
    },
);
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-gray-50/30 px-5 py-4">
            <div class="flex items-center">
                <BarChart2 class="mr-2 h-4 w-4 text-gray-400" />
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Tendencia de Pólizas</h3>
                    <p class="mt-0.5 text-xs text-gray-400">{{ groupingLabel }} · Solo pólizas pagadas</p>
                </div>
            </div>

            <div class="flex items-center rounded-xl border border-gray-200/50 bg-gray-100/60 p-0.5">
                <button
                    type="button"
                    class="rounded-lg px-3.5 py-1.5 text-[13px] font-medium transition-all duration-200 ease-out"
                    :class="metric === 'count' ? 'border border-gray-200/40 bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                    @click="metric = 'count'"
                >
                    Pólizas
                </button>
                <button
                    type="button"
                    class="rounded-lg px-3.5 py-1.5 text-[13px] font-medium transition-all duration-200 ease-out"
                    :class="metric === 'premium' ? 'border border-gray-200/40 bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                    @click="metric = 'premium'"
                >
                    Prima vendida
                </button>
            </div>
        </div>

        <template v-if="data && data.length > 0 && totalCount > 0">
            <!-- Resumen del periodo -->
            <div class="grid grid-cols-1 gap-4 px-6 pt-5 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium text-gray-400">Total de pólizas</p>
                    <p class="mt-0.5 text-lg font-bold text-gray-900">{{ formatNumber(totalCount) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-400">Prima vendida</p>
                    <p class="mt-0.5 text-lg font-bold text-gray-900">{{ formatCurrency(totalPremium) }}</p>
                </div>
                <div v-if="bestPoint">
                    <p class="text-xs font-medium text-gray-400">Mejor {{ grouping === 'month' ? 'mes' : 'día' }}</p>
                    <p class="mt-0.5 text-lg font-bold text-gray-900">
                        {{ bestPoint.label }}
                        <span class="text-sm font-medium text-gray-400">· {{ bestPointLabel }}</span>
                    </p>
                </div>
            </div>

            <!-- Leyenda -->
            <div class="flex flex-wrap items-center gap-4 px-6 pt-3 text-xs text-gray-400">
                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm bg-gray-800"></span>
                    Mejor {{ grouping === 'month' ? 'mes' : 'día' }}
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm bg-gray-200"></span>
                    Con venta
                </span>
                <span>Haz clic en una columna para ver su detalle.</span>
            </div>

            <!-- Detalle de la barra seleccionada -->
            <div
                v-if="selectedPoint"
                class="mx-6 mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3"
            >
                <div>
                    <p class="text-sm font-semibold text-gray-900">{{ selectedPoint.label }}</p>
                    <p class="text-xs text-gray-400">Detalle del {{ grouping === 'month' ? 'mes' : 'día' }} seleccionado</p>
                </div>
                <p class="text-sm text-gray-600">
                    <span class="font-semibold text-gray-900">{{ pluralize(selectedPoint.count, 'póliza', 'pólizas') }}</span>
                </p>
                <p class="text-sm text-gray-600">
                    Vendido: <span class="font-semibold text-gray-900">{{ formatCurrency(selectedPoint.premium) }}</span>
                </p>
                <p class="text-xs text-gray-400">{{ selectedShare.toFixed(1) }}% de la prima del periodo</p>
                <button
                    type="button"
                    class="ml-auto text-xs font-medium text-gray-500 transition-colors hover:text-gray-900"
                    @click="selectedIndex = null"
                >
                    Cerrar
                </button>
            </div>

            <div class="overflow-x-auto px-3 py-4">
                <svg :width="chartWidth" :height="chartHeight" class="mx-auto overflow-visible">
                    <!-- Rejilla y eje Y -->
                    <g v-for="(tick, tickIndex) in ticks" :key="`tick-${tickIndex}`">
                        <line
                            :x1="padding.left"
                            :y1="yFor(tick)"
                            :x2="chartWidth - padding.right"
                            :y2="yFor(tick)"
                            stroke="#f3f4f6"
                            stroke-width="1"
                        />
                        <text :x="padding.left - 10" :y="yFor(tick) + 3.5" text-anchor="end" class="fill-gray-400 text-[10px] font-medium">
                            {{ axisLabel(tick) }}
                        </text>
                    </g>

                    <!-- Barras -->
                    <g v-for="(item, index) in data" :key="item.date" class="group">
                        <!-- Área de clic de toda la columna (incluye días sin venta) -->
                        <rect
                            :x="padding.left + index * slotWidth"
                            :y="padding.top"
                            :width="slotWidth"
                            :height="plotHeight"
                            fill="transparent"
                            class="cursor-pointer outline-none"
                            tabindex="0"
                            role="button"
                            :aria-label="`${item.label}: ${item.count} póliza(s), ${formatCurrency(item.premium)} vendidos`"
                            :aria-pressed="selectedIndex === index"
                            @click="toggleSelection(index)"
                            @keydown.enter.prevent="toggleSelection(index)"
                            @keydown.space.prevent="toggleSelection(index)"
                        >
                            <title>{{ item.label }}: {{ item.count }} póliza(s) · {{ formatCurrency(item.premium) }}</title>
                        </rect>

                        <rect
                            :x="barX(index)"
                            :y="plotBottom - barHeight(metric === 'count' ? item.count : item.premium)"
                            :width="barWidth"
                            :height="barHeight(metric === 'count' ? item.count : item.premium)"
                            rx="3"
                            class="pointer-events-none transition-all duration-300"
                            :class="
                                isPeak(metric === 'count' ? item.count : item.premium) ? 'fill-gray-800' : 'fill-gray-200 group-hover:fill-gray-400'
                            "
                            :stroke="selectedIndex === index ? '#111827' : 'none'"
                            :stroke-width="selectedIndex === index ? 2 : 0"
                        />

                        <!-- Marca de columna sin venta (indica que también se puede consultar) -->
                        <rect
                            v-if="(metric === 'count' ? item.count : item.premium) <= 0"
                            :x="barX(index)"
                            :y="plotBottom - 2"
                            :width="barWidth"
                            :height="2"
                            rx="1"
                            class="pointer-events-none"
                            :class="selectedIndex === index ? 'fill-gray-700' : 'fill-gray-300 group-hover:fill-gray-400'"
                        />

                        <!-- Valor sobre la barra (solo con pocas barras o barra seleccionada) -->
                        <text
                            v-if="(showValueLabel || selectedIndex === index) && (metric === 'count' ? item.count : item.premium) > 0"
                            :x="barX(index) + barWidth / 2"
                            :y="plotBottom - barHeight(metric === 'count' ? item.count : item.premium) - 6"
                            text-anchor="middle"
                            class="pointer-events-none fill-gray-500 text-[10px] font-semibold"
                        >
                            {{ metric === 'count' ? formatNumber(item.count) : formatCompactCurrency(item.premium) }}
                        </text>

                        <!-- Etiqueta del eje X -->
                        <text
                            v-if="showXLabel(index)"
                            :x="barX(index) + barWidth / 2"
                            :y="plotBottom + 20"
                            text-anchor="middle"
                            class="fill-gray-400 text-[11px] font-medium"
                        >
                            {{ item.label }}
                        </text>
                    </g>

                    <!-- Línea base -->
                    <line :x1="padding.left" :y1="plotBottom" :x2="chartWidth - padding.right" :y2="plotBottom" stroke="#e5e7eb" stroke-width="1" />
                </svg>
            </div>

            <p class="px-6 pb-4 text-xs text-gray-400">
                Haz clic en una barra para ver cuántas pólizas y cuánto se vendió {{ grouping === 'month' ? 'ese mes' : 'ese día' }}. La gráfica se
                agrupa {{ grouping === 'month' ? 'por mes' : 'por día' }} según el periodo seleccionado.
            </p>
        </template>

        <div v-else class="px-5 py-12 text-center text-sm text-gray-400">Sin pólizas pagadas en este periodo.</div>
    </div>
</template>
