<script setup>
/**
 * MonthlyBonusBreakdown.vue
 *
 * Desglose mes a mes de un bono mensual cuando el rango visual abarca varios
 * meses (ej. un trimestre). Permite ver cuánto generó el bono en cada mes y
 * las condiciones que se cumplieron, sin perder el total del periodo.
 *
 * Props:
 *   - months: Array<{ month, label, period_label, unlocked, amount, conditions }>
 */
import { CheckCircle2, ChevronDown, ChevronRight, Lock } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({
    months: {
        type: Array,
        required: true,
    },
});

const expanded = ref(false);

const unlockedCount = computed(() => props.months.filter((month) => month.unlocked).length);

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value ?? 0);
};

const formatMetricValue = (value, target = 0) => {
    if ((target > 0 && target >= 1000) || (value ?? 0) >= 1000) {
        return formatCurrency(value);
    }
    return Number.isInteger(value) ? value : Number(value ?? 0).toFixed(2);
};

const progressWidth = (condition) => {
    if (condition.met) return '100%';
    if (condition.target > 0) {
        return `${Math.min((condition.current / condition.target) * 100, 100)}%`;
    }
    return '0%';
};
</script>

<template>
    <div v-if="months.length > 0" class="overflow-hidden rounded-xl border border-gray-100 bg-gray-50/40">
        <button
            type="button"
            class="flex w-full items-center justify-between px-3 py-2 text-left transition-colors hover:bg-gray-100/60"
            @click="expanded = !expanded"
        >
            <span class="flex items-center gap-1.5 text-[11px] font-medium text-gray-600">
                <ChevronDown v-if="expanded" class="h-3.5 w-3.5 text-gray-400" />
                <ChevronRight v-else class="h-3.5 w-3.5 text-gray-400" />
                {{ expanded ? 'Ocultar desglose mensual' : `Ver desglose mensual (${months.length} meses)` }}
            </span>
            <span class="text-[11px] text-gray-400">{{ unlockedCount }} de {{ months.length }} desbloqueados</span>
        </button>

        <div v-if="expanded" class="divide-y divide-gray-100 border-t border-gray-100">
            <div v-for="month in months" :key="month.month" class="space-y-2 px-3 py-3">
                <div class="flex items-center justify-between">
                    <div class="flex min-w-0 items-center gap-1.5">
                        <CheckCircle2 v-if="month.unlocked" class="h-4 w-4 flex-shrink-0 text-green-600" />
                        <Lock v-else class="h-4 w-4 flex-shrink-0 text-gray-400" />
                        <span class="truncate text-xs font-semibold text-gray-800">{{ month.label }}</span>
                    </div>
                    <span class="ml-2 flex-shrink-0 text-xs font-medium" :class="month.amount > 0 ? 'text-green-700' : 'text-gray-400'">
                        {{ formatCurrency(month.amount) }}
                    </span>
                </div>

                <div v-if="month.conditions && month.conditions.length > 0" class="space-y-1.5">
                    <div v-for="(condition, index) in month.conditions" :key="index" class="space-y-1">
                        <div class="flex justify-between text-[11px]">
                            <span class="text-gray-500">{{ condition.label }}</span>
                            <span class="font-medium" :class="condition.met ? 'text-green-700' : 'text-red-600'">
                                {{ formatMetricValue(condition.current, condition.target) }}
                                <template v-if="condition.target > 0"> / {{ formatMetricValue(condition.target, condition.target) }}</template>
                            </span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full" :class="condition.target > 0 ? 'bg-[#f5f0eb]' : 'bg-gray-200'">
                            <div
                                class="h-full rounded-full transition-all duration-700"
                                :class="condition.met ? 'bg-[#4a7c59]' : condition.target > 0 ? 'bg-[#d9775b]' : 'bg-gray-300'"
                                :style="{ width: progressWidth(condition) }"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
