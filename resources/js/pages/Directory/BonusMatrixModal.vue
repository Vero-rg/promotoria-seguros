<script setup>
/**
 * BonusMatrixModal.vue
 *
 * Ventana de consulta rápida de un bono: matriz de compensación, requisitos
 * del esquema y equivalencias. Solo renderiza la estructura ya normalizada
 * por BonusCompensationMatrix.
 */
import { CheckCircle2, X } from 'lucide-vue-next';

defineProps({
    bonus: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 }).format(value ?? 0);
};

const paymentLabel = (tier) => {
    return tier.payment ?? `${Number(tier.percentage ?? 0)}%`;
};
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
        <!-- Fondo -->
        <div class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px]" @click="emit('close')"></div>

        <!-- Tarjeta -->
        <div class="relative max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-gray-100 bg-white shadow-xl">
            <div class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-gray-100 bg-white px-5 py-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-gray-900">{{ bonus.name }}</h3>
                    <p class="mt-0.5 text-[11px] text-gray-400">Matriz de compensación</p>
                </div>
                <button
                    type="button"
                    class="flex-shrink-0 rounded-md p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                    aria-label="Cerrar"
                    @click="emit('close')"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="space-y-4 px-5 py-4">
                <!-- Requiere antes -->
                <div v-if="bonus.matrix?.dependency" class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">Requiere antes</span>
                    <span class="text-right font-medium text-gray-800">{{ bonus.matrix.dependency }}</span>
                </div>

                <!-- Productos requeridos -->
                <div v-if="bonus.matrix?.products?.length" class="space-y-1.5">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Productos requeridos</p>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="product in bonus.matrix.products"
                            :key="product.label"
                            class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-700"
                        >
                            {{ product.label }} ×{{ product.min }}
                        </span>
                    </div>
                </div>

                <!-- Reclutas por trimestre -->
                <div v-if="bonus.matrix?.recruits" class="space-y-1.5">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Reclutas requeridos por trimestre</p>
                    <div class="grid grid-cols-4 gap-1.5">
                        <div
                            v-for="quarter in bonus.matrix.recruits.quarters"
                            :key="quarter.label"
                            class="rounded-lg border px-2 py-1.5 text-center"
                            :class="
                                Number(bonus.matrix.recruits.current_quarter) === Number(quarter.label.replace('Q', ''))
                                    ? 'border-amber-200 bg-amber-50'
                                    : 'border-gray-100 bg-gray-50'
                            "
                        >
                            <p class="text-[10px] text-gray-400">{{ quarter.label }}</p>
                            <p class="text-sm font-semibold text-gray-800">{{ quarter.required }}</p>
                        </div>
                    </div>
                    <p v-if="bonus.matrix.recruits.actual !== null" class="text-[11px] text-gray-500">
                        Acumulados del trimestre:
                        <span class="font-medium text-gray-800">{{ bonus.matrix.recruits.actual }}</span>
                        de {{ bonus.matrix.recruits.goal }}
                    </p>
                </div>

                <!-- Anticipos -->
                <div v-if="bonus.matrix?.anticipos?.length" class="space-y-1.5">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Anticipos mensuales</p>
                    <div v-for="anticipo in bonus.matrix.anticipos" :key="anticipo.label" class="flex items-center justify-between text-xs">
                        <span class="text-gray-500">{{ anticipo.label }}</span>
                        <span class="font-medium text-gray-800">{{ anticipo.min > 0 ? `≥ ${formatCurrency(anticipo.min)}` : 'Permitido' }}</span>
                    </div>
                </div>

                <!-- Equivalencias PNA -->
                <div v-if="bonus.matrix?.equivalences?.length" class="space-y-1.5">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Equivalencias por PNA</p>
                    <div class="overflow-hidden rounded-lg border border-gray-100">
                        <div
                            v-for="(row, index) in bonus.matrix.equivalences"
                            :key="index"
                            class="flex items-center justify-between px-3 py-1.5 text-[11px]"
                            :class="index % 2 === 0 ? 'bg-white' : 'bg-gray-50/60'"
                        >
                            <span class="text-gray-600">{{ row.label }}</span>
                            <span class="font-semibold text-gray-800">{{ row.value }} póliza(s)</span>
                        </div>
                    </div>
                </div>

                <!-- Matriz de compensación -->
                <div v-if="bonus.matrix?.tiers?.length" class="space-y-1.5">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Matriz de compensación</p>
                    <div class="overflow-hidden rounded-lg border border-gray-100">
                        <div
                            class="grid grid-cols-[1.3fr_1fr_auto] gap-2 bg-gray-50 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500"
                        >
                            <span>Nivel</span>
                            <span>Requisito</span>
                            <span class="text-right">Pago</span>
                        </div>
                        <div
                            v-for="(tier, index) in bonus.matrix.tiers"
                            :key="index"
                            class="grid grid-cols-[1.3fr_1fr_auto] gap-2 border-t border-gray-50 px-3 py-2 text-[11px]"
                            :class="tier.achieved ? 'bg-green-50/70' : 'bg-white'"
                        >
                            <span class="flex min-w-0 items-center gap-1 font-medium text-gray-800">
                                <CheckCircle2 v-if="tier.achieved" class="h-3.5 w-3.5 flex-shrink-0 text-green-600" />
                                <span class="truncate">{{ tier.label }}</span>
                            </span>
                            <span class="text-gray-500">{{ tier.requirement }}</span>
                            <span class="text-right font-semibold" :class="tier.achieved ? 'text-green-700' : 'text-gray-800'">
                                {{ paymentLabel(tier) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Reglas -->
                <ul v-if="bonus.matrix?.rules?.length" class="space-y-1">
                    <li v-for="(rule, index) in bonus.matrix.rules" :key="index" class="flex gap-1.5 text-[11px] text-gray-500">
                        <span class="text-gray-300">•</span>
                        <span>{{ rule }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
