<script setup>
/**
 * BonusPaymentInfo.vue
 *
 * Resumen informativo del conducto de pago del bono de 1.er año de vida para
 * agentes: cuánto fue en efectivo, cuánto en automático y el porcentaje que
 * finalmente se aplicó. No son requisitos del bono.
 */
defineProps({
    info: { type: Object, required: true },
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 2 }).format(value ?? 0);
};

const formatPercent = (value) => {
    const number = Number(value ?? 0);
    return `${Number.isInteger(number) ? number : number.toFixed(2)}%`;
};

/** Etiqueta del canal aplicado: automático, efectivo o mixto. */
const strategyLabel = (info) => {
    if (info.strategy === 'automatic') return 'automático';
    if (Number(info.automatic_pca ?? 0) <= 0) return 'efectivo';
    if (Number(info.cash_pca ?? 0) <= 0) return 'automático';
    return 'efectivo + automático';
};

/** Porcentaje de un canal: solo se muestra si ese canal tiene PCA. */
const channelPercent = (pca, percentage) => {
    return Number(pca ?? 0) > 0 ? formatPercent(percentage) : '—';
};
</script>

<template>
    <div class="rounded-xl border px-3 py-2" :class="info.strategy === 'automatic' ? 'border-sky-100 bg-sky-50/50' : 'border-gray-100 bg-gray-50/60'">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Composición del pago</p>

        <div class="mt-1.5 space-y-1 text-[11px]">
            <div class="flex items-center justify-between">
                <span class="text-gray-500">PCA en efectivo</span>
                <span class="font-medium text-gray-700">
                    {{ formatCurrency(info.cash_pca) }}
                    <span class="text-gray-400">· {{ channelPercent(info.cash_pca, info.cash_percentage) }}</span>
                </span>
            </div>

            <div class="flex items-center justify-between">
                <span class="text-gray-500">PCA en automático</span>
                <span class="font-medium text-gray-700">
                    {{ formatCurrency(info.automatic_pca) }}
                    <span class="text-gray-400">· {{ channelPercent(info.automatic_pca, info.automatic_percentage) }}</span>
                </span>
            </div>

            <div class="flex items-center justify-between">
                <span class="text-gray-500">Pólizas en automático</span>
                <span class="font-medium text-gray-700">
                    {{ formatPercent(info.automatic_ratio) }}
                    <span class="text-gray-400">({{ info.automatic_policy_count }} de {{ info.total_policy_count }})</span>
                </span>
            </div>
        </div>

        <div class="mt-1.5 flex items-center justify-between border-t border-gray-200/70 pt-1.5">
            <span class="text-[11px] font-medium text-gray-600">Porcentaje aplicado</span>
            <span class="text-xs font-semibold text-gray-900">
                {{ formatPercent(info.applied_percentage) }}
                <span class="text-[10px] font-normal text-gray-400">{{ strategyLabel(info) }}</span>
            </span>
        </div>
    </div>
</template>
