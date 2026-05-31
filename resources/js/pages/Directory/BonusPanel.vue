<script setup>
/**
 * BonusPanel.vue
 *
 * Componente reutilizable para mostrar el progreso de bonos de un Agente o Promotor.
 *
 * Props:
 *   - bonuses:  Array de bonos en formato frontend (desde BonusOrchestratorService::toFrontendFormat)
 *   - type:     'agent' | 'promoter' — determina el estilo de presentación.
 *
 * Cada objeto de bonus tiene la forma:
 *   {
 *       name, description, step, target, progress, unlocked,
 *       depends_on_previous, conditions: [{ label, current, target, met }],
 *       amount, scheme_id, frequency, metric_base, tier_index
 *   }
 */
import { CheckCircle2, Lock, Award } from 'lucide-vue-next';

const props = defineProps({
    bonuses: {
        type: Array,
        required: true,
    },
    type: {
        type: String,
        required: true,
        validator: (v) => ['agent', 'promoter'].includes(v),
    },
});

// ─── Formateo ──────────────────────────────────────
const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

/**
 * Formatea un valor de métrica: usa formato de moneda si es >= 1000
 * o si el target es >= 1000, de lo contrario lo muestra como número entero.
 */
const formatMetricValue = (value, target = 0) => {
    if ((target > 0 && target >= 1000) || (value >= 1000)) {
        return formatCurrency(value);
    }
    return Number.isInteger(value) ? value : value.toFixed(2);
};

/**
 * Determina si una condición es de tipo monetario (para decidir formato).
 */
const isMonetaryLabel = (label) => {
    return label.includes('($)') || label.includes('PCA') || label.includes('PP') || label.includes('PNA');
};

// ─── Helpers ────────────────────────────────────────
const hasConditions = (bonus) => {
    return bonus.conditions && bonus.conditions.length > 0;
};

const isLockedByDependency = (bonus, index) => {
    return bonus.depends_on_previous && index > 0 && !props.bonuses[index - 1]?.unlocked;
};
</script>

<template>
    <!-- ═══════════ AGENTE: Panel de Bonos (estilo tarjetas) ═══════════ -->
    <div v-if="type === 'agent'" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Panel de Bonos</h3>
        </div>

        <div v-if="bonuses.length > 0" class="p-5 space-y-6">
            <div v-for="(bonus, idx) in bonuses" :key="idx" class="space-y-3">
                <!-- Cabecera del Bono -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                        <CheckCircle2 v-if="bonus.unlocked" class="w-5 h-5 text-green-600" />
                        <Lock v-else class="w-5 h-5 text-gray-400" />
                        <span class="text-sm font-medium text-gray-900">{{ bonus.name }}</span>
                    </div>
                    <span class="text-xs font-medium" :class="bonus.unlocked ? 'text-green-700' : 'text-gray-500'">
                        {{ bonus.unlocked ? 'Desbloqueado' : 'Bloqueado' }}
                    </span>
                </div>

                <!-- Condiciones detalladas del progress_breakdown -->
                <div v-if="hasConditions(bonus)" class="space-y-2">
                    <div v-for="(cond, cIdx) in bonus.conditions" :key="cIdx" class="space-y-1">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">{{ cond.label }}</span>
                            <span class="font-medium flex items-center gap-1.5">
                                <span :class="cond.met ? 'text-green-700' : 'text-red-600'">
                                    {{ formatMetricValue(cond.current, cond.target) }}
                                    <template v-if="cond.target > 0"> / {{ formatMetricValue(cond.target, cond.target) }}</template>
                                </span>
                                <span v-if="cond.met && cond.target > 0" class="text-green-600 text-[10px]">✓</span>
                                <span v-else-if="!cond.met && cond.target > 0" class="text-red-500 text-[10px] whitespace-nowrap">
                                    Falta {{ formatMetricValue(Math.max(0, cond.target - cond.current), cond.target) }}
                                </span>
                            </span>
                        </div>
                        <!-- Barra de progreso -->
                        <div class="w-full h-2 rounded-full overflow-hidden"
                            :class="cond.target > 0 ? 'bg-[#f5f0eb]' : 'bg-green-100'">
                            <div class="h-full rounded-full transition-all duration-700"
                                :class="cond.met ? 'bg-[#4a7c59]' : (cond.target > 0 ? 'bg-[#d9775b]' : 'bg-[#4a7c59]')"
                                :style="{ width: `${cond.target > 0 ? Math.min((cond.current / cond.target) * 100, 100) : 100}%` }">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barra de progreso principal (fallback si no hay conditions) -->
                <template v-if="!hasConditions(bonus)">
                    <div class="relative">
                        <div class="w-full h-5 bg-[#f5f0eb] rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-700 ease-out"
                                :class="bonus.unlocked ? 'bg-[#4a7c59]' : 'bg-[#6a9e7a]'"
                                :style="{ width: `${bonus.target > 0 ? Math.min((bonus.progress / bonus.target) * 100, 100) : 0}%` }">
                            </div>
                        </div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-xs font-bold text-white drop-shadow-sm">
                                {{ bonus.target > 0 ? Math.round((bonus.progress / bonus.target) * 100) : 0 }}%
                            </span>
                        </div>
                    </div>
                    <div class="flex justify-between text-xs text-gray-400">
                        <span>{{ formatCurrency(bonus.progress) }}</span>
                        <span>Meta: {{ formatCurrency(bonus.target) }}</span>
                    </div>
                </template>

                <!-- Monto del bono si fue alcanzado -->
                <div v-if="bonus.unlocked && bonus.amount > 0" class="text-xs text-green-700 font-medium text-right">
                    Monto: {{ formatCurrency(bonus.amount) }}
                </div>
            </div>
        </div>

        <div v-else class="px-5 py-10 text-center text-gray-400 text-sm">
            No hay bonos configurados.
        </div>
    </div>

    <!-- ═══════════ PROMOTOR: Panel de Bonos (estilo tarjetas) ═══════════ -->
    <div v-else-if="type === 'promoter'" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Panel de Bonos</h3>
        </div>

        <div v-if="bonuses.length > 0" class="p-5 space-y-6">
            <div v-for="(bonus, idx) in bonuses" :key="idx" class="space-y-3" :class="isLockedByDependency(bonus, idx) ? 'opacity-50' : ''">
                <!-- Cabecera del Bono -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                        <CheckCircle2 v-if="bonus.unlocked" class="w-5 h-5 text-green-600" />
                        <Lock v-else class="w-5 h-5 text-gray-400" />
                        <span class="text-sm font-medium text-gray-900">{{ bonus.name }}</span>
                    </div>
                    <span class="text-xs font-medium" :class="bonus.unlocked ? 'text-green-700' : 'text-gray-500'">
                        {{ bonus.unlocked ? 'Desbloqueado' : 'Bloqueado' }}
                    </span>
                </div>

                <!-- Condiciones detalladas -->
                <div v-if="hasConditions(bonus)" class="space-y-2">
                    <div v-for="(cond, cIdx) in bonus.conditions" :key="cIdx" class="space-y-1">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">{{ cond.label }}</span>
                            <span class="font-medium flex items-center gap-1.5">
                                <span :class="cond.met ? 'text-green-700' : 'text-red-600'">
                                    {{ formatMetricValue(cond.current, cond.target) }}
                                    <template v-if="cond.target > 0"> / {{ formatMetricValue(cond.target, cond.target) }}</template>
                                </span>
                                <span v-if="cond.met && cond.target > 0" class="text-green-600 text-[10px]">✓</span>
                                <span v-else-if="!cond.met && cond.target > 0" class="text-red-500 text-[10px] whitespace-nowrap">
                                    Falta {{ formatMetricValue(Math.max(0, cond.target - cond.current), cond.target) }}
                                </span>
                            </span>
                        </div>
                        <div class="w-full h-2 rounded-full overflow-hidden"
                            :class="cond.target > 0 ? 'bg-[#f5f0eb]' : 'bg-green-100'">
                            <div class="h-full rounded-full transition-all duration-700"
                                :class="cond.met ? 'bg-[#4a7c59]' : (cond.target > 0 ? 'bg-[#d9775b]' : 'bg-[#4a7c59]')"
                                :style="{ width: `${cond.target > 0 ? Math.min((cond.current / cond.target) * 100, 100) : 100}%` }">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monto del bono si fue alcanzado -->
                <div v-if="bonus.unlocked && bonus.amount > 0" class="text-xs text-green-700 font-medium text-right">
                    Monto: {{ formatCurrency(bonus.amount) }}
                </div>
            </div>
        </div>

        <div v-else class="px-5 py-10 text-center text-gray-400 text-sm">
            No hay bonos configurados.
        </div>
    </div>
</template>
