<script setup>
/**
 * BonusAgentBreakdown.vue
 *
 * Desglose por agente del bono de Conexión: PCA del periodo de cada agente,
 * porcentaje aplicado según la columna que alcanza y monto del bono.
 */
import { computed } from 'vue';

const props = defineProps({
    agents: { type: Array, required: true },
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 2 }).format(value ?? 0);

const total = computed(() => props.agents.reduce((sum, agent) => sum + Number(agent.amount ?? 0), 0));

const qualifiedCount = computed(() => props.agents.filter((agent) => agent.qualified).length);
</script>

<template>
    <div v-if="agents.length > 0" class="overflow-hidden rounded-xl border border-sky-100 bg-sky-50/40">
        <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-0.5 border-b border-sky-100 px-3 py-2">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-sky-800">Desglose por agente</span>
            <span class="text-xs font-semibold text-sky-900">
                {{ qualifiedCount }} de {{ agents.length }} califican · Total: {{ formatCurrency(total) }}
            </span>
        </div>

        <div class="divide-y divide-sky-100/70">
            <div v-for="agent in agents" :key="agent.agent_id" class="flex items-center justify-between gap-2 px-3 py-2">
                <div class="min-w-0">
                    <div class="truncate text-xs font-medium" :class="agent.qualified ? 'text-gray-800' : 'text-gray-400'">
                        {{ agent.agent_label || agent.agent_name }}
                    </div>
                    <div class="text-[11px] text-gray-500">
                        PCA {{ formatCurrency(agent.pca) }}
                        <template v-if="agent.percentage !== null && agent.percentage !== undefined">
                            · <span class="font-medium text-sky-700">{{ agent.percentage }}%</span>
                        </template>
                        <template v-else-if="agent.note"> · {{ agent.note }}</template>
                        <template v-else> · no califica</template>
                        <template v-if="agent.tenure_month"> · Mes {{ agent.tenure_month }}</template>
                        <template v-if="agent.fixed_amount"> · fijo {{ formatCurrency(agent.fixed_amount) }}</template>
                    </div>
                </div>
                <span class="flex-shrink-0 text-xs font-semibold" :class="agent.amount > 0 ? 'text-sky-900' : 'text-gray-400'">
                    {{ formatCurrency(agent.amount) }}
                </span>
            </div>
        </div>
    </div>
</template>
