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
 *       amount, scheme_id, frequency, metric_base, requires_anticipos, tier_index,
 *       dependency_scheme_id, dependency_scheme_name,
 *       periodo_evaluado: string (ej. "01/01/2026 al 31/03/2026"),
 *       mostrar_alerta_periodo: bool (true si el periodo real > rango visual),
 *       monthly_breakdown: Array (montos por mes de los bonos mensuales),
 *       monthly_total: bool (true si el monto es la suma de varios meses),
 *       matrix: Object (matriz de compensación para consulta),
 *       earned: { percentage, fixed_amount, amount, note },
 *       next_level: { label, requirement, percentage, gaps[] }
 *       advances: { months[], total, calculated_total } (bonos con anticipos),
 *       payment_info: { cash_pca, automatic_pca, cash_percentage, automatic_percentage, automatic_ratio, applied_percentage, strategy }
 *   }
 */
import { ref } from 'vue';
import BonusAdvancesTable from './BonusAdvancesTable.vue';
import BonusMatrixModal from './BonusMatrixModal.vue';
import BonusPaymentInfo from './BonusPaymentInfo.vue';
import MonthlyBonusBreakdown from './MonthlyBonusBreakdown.vue';
import { CheckCircle2, Lock, Info, Table2 } from 'lucide-vue-next';

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
    userId: {
        type: Number,
        required: true,
    },
});

// ─── Formateo ──────────────────────────────────────
const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

/**
 * Construye la etiqueta de frecuencia + métrica base para el tag visual.
 * Ejemplo: "TRIMESTRAL (PCA)", "MENSUAL (PNA)", "ANUAL (PP)"
 */
const frequencyMetricTag = (bonus) => {
    const freq = (bonus.frequency || 'mensual').toUpperCase();
    const metric = (bonus.metric_base || '—').toUpperCase();
    return `${freq} (${metric})`;
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

/**
 * Formatea el valor de una condición: los montos por mes (bonos mensuales
 * agregados) siempre se muestran como moneda.
 */
const formatConditionValue = (condition) => {
    if (condition._isMonthlyAmount) {
        return formatCurrency(condition.current);
    }
    return formatMetricValue(condition.current, condition.target);
};

// ─── Helpers ────────────────────────────────────────
const hasConditions = (bonus) => {
    return bonus.conditions && bonus.conditions.length > 0;
};

const hasMonthlyBreakdown = (bonus) => {
    return Array.isArray(bonus.monthly_breakdown) && bonus.monthly_breakdown.length > 1;
};

const hasMatrix = (bonus) => {
    return Array.isArray(bonus.matrix?.tiers) && bonus.matrix.tiers.length > 0;
};

// ─── Matriz de compensación ────────────────────────
const openMatrixIndex = ref(null);

const openMatrix = (index) => {
    openMatrixIndex.value = index;
};

const closeMatrix = () => {
    openMatrixIndex.value = null;
};

const formatCompactNumber = (value) => {
    const number = Number(value ?? 0);
    return Number.isInteger(number) ? String(number) : number.toFixed(2);
};

/**
 * Texto del pago obtenido: «40% · $279,440».
 */
const earnedLabel = (bonus) => {
    const earned = bonus.earned;
    if (!earned) return null;

    const amount = formatCurrency(earned.amount ?? 0);

    if (earned.fixed_amount) {
        return `Bono fijo ${formatCurrency(earned.fixed_amount)}`;
    }

    if (earned.percentage !== null && earned.percentage !== undefined) {
        return `${formatCompactNumber(earned.percentage)}% · ${amount}`;
    }

    return earned.amount > 0 ? amount : null;
};

const isLockedByDependency = (bonus, index) => {
    return bonus.depends_on_previous && index > 0 && !props.bonuses[index - 1]?.unlocked;
};

/**
 * Retorna las condiciones efectivas de un bono, inyectando una barra
 * de progreso adicional si el bono tiene un esquema prerequisite
 * (dependency_scheme_id). Esto permite visualizar en la UI el estado
 * del bono padre como una condición más dentro del bono dependiente.
 *
 * La barra inyectada tiene:
 *   - label: nombre del bono prerequisite (dependency_scheme_name)
 *   - target: 1 (condición binaria)
 *   - current: 1 si el bono padre está unlocked, 0 si no
 *   - met: true si current >= target
 */
const getEffectiveConditions = (bonus) => {
    const baseConditions = bonus.conditions || [];

    if (!bonus.dependency_scheme_id) {
        return baseConditions;
    }

    const parentBonus = props.bonuses.find(
        (b) => b.template_key === bonus.dependency_scheme_id
    );

    const parentUnlocked = parentBonus?.unlocked ?? false;
    const parentName = bonus.dependency_scheme_name || parentBonus?.name || 'Bono Prerequisite';

    const dependencyCondition = {
        label: parentName,
        current: parentUnlocked ? 1 : 0,
        target: 1,
        met: parentUnlocked,
        _isDependency: true,
    };

    // Si el backend ya mandó la barra de dependencia como primera condición
    // (identificada por _isDependency: true), la reemplazamos con la versión
    // actualizada desde el estado real del bono padre. Si no, la insertamos.
    const firstCondition = baseConditions[0];
    if (firstCondition?._isDependency) {
        return [dependencyCondition, ...baseConditions.slice(1)];
    }

    return [dependencyCondition, ...baseConditions];
};

/**
 * Determina si un bono tiene condiciones (originales o inyectadas por dependencia).
 */
const hasEffectiveConditions = (bonus) => {
    return getEffectiveConditions(bonus).length > 0;
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
                    <div class="flex items-center space-x-2 min-w-0">
                        <CheckCircle2 v-if="bonus.unlocked" class="w-5 h-5 text-green-600 flex-shrink-0" />
                        <Lock v-else class="w-5 h-5 text-gray-400 flex-shrink-0" />
                        <span class="text-sm font-medium text-gray-900 truncate">{{ bonus.name }}</span>
                        <span class="flex-shrink-0 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold tracking-wide bg-gray-100 text-gray-500">
                            {{ frequencyMetricTag(bonus) }}
                        </span>
                        <button
                            v-if="hasMatrix(bonus)"
                            type="button"
                            class="flex-shrink-0 p-1 rounded-md text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                            title="Ver matriz de compensación"
                            @click="openMatrix(idx)"
                        >
                            <Table2 class="w-3.5 h-3.5" />
                        </button>
                    </div>
                    <span class="text-xs font-medium flex-shrink-0 ml-2" :class="bonus.unlocked ? 'text-green-700' : 'text-gray-500'">
                        {{ bonus.unlocked ? 'Desbloqueado' : 'Bloqueado' }}
                    </span>
                </div>

                <!-- Condiciones detalladas del progress_breakdown -->
                <div v-if="hasEffectiveConditions(bonus)" class="space-y-2">
                    <div v-for="(cond, cIdx) in getEffectiveConditions(bonus)" :key="cIdx" class="space-y-1">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">{{ cond.label }}</span>
                            <span class="font-medium flex items-center gap-1.5">
                                <span :class="cond.met ? 'text-green-700' : 'text-red-600'">
                                    {{ formatConditionValue(cond) }}
                                    <template v-if="cond.target > 0"> / {{ formatMetricValue(cond.target, cond.target) }}</template>
                                </span>
                                <span v-if="cond.met && (cond.target > 0 || cond._isMonthlyAmount)" class="text-green-600 text-[10px]">✓</span>
                                <span v-else-if="!cond.met && cond.target > 0" class="text-red-500 text-[10px] whitespace-nowrap">
                                    Falta {{ formatMetricValue(Math.max(0, cond.target - cond.current), cond.target) }}
                                </span>
                            </span>
                        </div>
                        <!-- Barra de progreso -->
                        <div v-if="!cond._isMonthlyAmount" class="w-full h-2 rounded-full overflow-hidden"
                            :class="cond.target > 0 ? 'bg-[#f5f0eb]' : 'bg-gray-200'">
                            <div class="h-full rounded-full transition-all duration-700"
                                :class="cond.met ? 'bg-[#4a7c59]' : (cond.target > 0 ? 'bg-[#d9775b]' : 'bg-gray-300')"
                                :style="{ width: cond.met ? '100%' : (cond.target > 0 ? `${Math.min((cond.current / cond.target) * 100, 100)}%` : '0%') }">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barra de progreso principal (fallback si no hay conditions) -->
                <template v-if="!hasEffectiveConditions(bonus)">
                    <div class="relative">
                        <div class="w-full h-5 bg-[#f5f0eb] rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-700 ease-out"
                                :class="bonus.unlocked ? 'bg-[#4a7c59]' : 'bg-gray-300'"
                                :style="{ width: bonus.unlocked ? '100%' : (bonus.target > 0 ? `${Math.min((bonus.progress / bonus.target) * 100, 100)}%` : '0%') }">
                            </div>
                        </div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-xs font-bold drop-shadow-sm" :class="bonus.unlocked ? 'text-white' : 'text-gray-600'">
                                {{ bonus.target > 0 ? Math.round((bonus.progress / bonus.target) * 100) : 0 }}%
                            </span>
                        </div>
                    </div>
                    <div class="flex justify-between text-xs text-gray-400">
                        <span>{{ formatCurrency(bonus.progress) }}</span>
                        <span>Meta: {{ formatCurrency(bonus.target) }}</span>
                    </div>
                </template>

                <!-- Indicador de anticipos -->
                <div v-if="bonus.requires_anticipos" class="text-[11px] text-amber-600 font-medium italic">
                    Permite anticipos mensuales
                </div>

                <!-- Pago obtenido: nivel, porcentaje y monto -->
                <div v-if="bonus.unlocked && earnedLabel(bonus)" class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">{{ bonus.monthly_total ? 'Total del periodo' : 'Nivel alcanzado' }}</span>
                    <span class="font-semibold text-green-700">
                        {{ earnedLabel(bonus) }}
                    </span>
                </div>
                <div v-if="bonus.unlocked && bonus.earned?.note" class="text-[11px] text-gray-400 text-right -mt-1">
                    {{ bonus.earned.note }}
                </div>

                <!-- Alerta: lo que falta para el siguiente nivel -->
                <div
                    v-if="bonus.next_level && bonus.next_level.gaps?.length"
                    class="flex items-start gap-1.5 px-2.5 py-1.5 bg-amber-50/70 border border-amber-100 rounded-lg text-[11px] text-amber-800"
                >
                    <Info class="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-amber-500" />
                    <span>{{ bonus.next_level.message }}</span>
                </div>

                <!-- Desglose mes a mes (bonos mensuales) -->
                <MonthlyBonusBreakdown v-if="hasMonthlyBreakdown(bonus)" :months="bonus.monthly_breakdown" />

                <!-- Informativo: composición del pago (efectivo / automático) -->
                <BonusPaymentInfo v-if="bonus.payment_info" :info="bonus.payment_info" />

                <!-- Anticipos mensuales (bonos de 1er año de vida) -->
                <BonusAdvancesTable
                    v-if="bonus.advances?.months?.length"
                    :advances="bonus.advances"
                    :scheme-id="bonus.scheme_id"
                    :user-type="type"
                    :user-id="userId"
                />

                <!-- Alerta: periodo de evaluación más amplio que el rango visual -->
                <div v-if="bonus.mostrar_alerta_periodo" class="flex items-start gap-1.5 px-2.5 py-1.5 bg-blue-50/60 border border-blue-100 rounded-lg text-[11px] text-blue-700">
                    <Info class="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-blue-500" />
                    <span>Progreso acumulado del periodo <strong>{{ bonus.periodo_evaluado }}</strong>. Incluye meses anteriores por la frecuencia del bono.</span>
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
            <div v-for="(bonus, idx) in bonuses" :key="idx" class="space-y-3" :class="isLockedByDependency(bonus, idx) ? '' : ''">
                <!-- Cabecera del Bono -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2 min-w-0">
                        <CheckCircle2 v-if="bonus.unlocked" class="w-5 h-5 text-green-600 flex-shrink-0" />
                        <Lock v-else class="w-5 h-5 text-gray-400 flex-shrink-0" />
                        <span class="text-sm font-medium text-gray-900 truncate">{{ bonus.name }}</span>
                        <span class="flex-shrink-0 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold tracking-wide bg-gray-100 text-gray-500">
                            {{ frequencyMetricTag(bonus) }}
                        </span>
                        <button
                            v-if="hasMatrix(bonus)"
                            type="button"
                            class="flex-shrink-0 p-1 rounded-md text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                            title="Ver matriz de compensación"
                            @click="openMatrix(idx)"
                        >
                            <Table2 class="w-3.5 h-3.5" />
                        </button>
                    </div>
                    <span class="text-xs font-medium flex-shrink-0 ml-2" :class="bonus.unlocked ? 'text-green-700' : 'text-gray-500'">
                        {{ bonus.unlocked ? 'Desbloqueado' : 'Bloqueado' }}
                    </span>
                </div>

                <!-- Condiciones detalladas -->
                <div v-if="hasEffectiveConditions(bonus)" class="space-y-2">
                    <div v-for="(cond, cIdx) in getEffectiveConditions(bonus)" :key="cIdx" class="space-y-1">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">{{ cond.label }}</span>
                            <span class="font-medium flex items-center gap-1.5">
                                <span :class="cond.met ? 'text-green-700' : 'text-red-600'">
                                    {{ formatConditionValue(cond) }}
                                    <template v-if="cond.target > 0"> / {{ formatMetricValue(cond.target, cond.target) }}</template>
                                </span>
                                <span v-if="cond.met && (cond.target > 0 || cond._isMonthlyAmount)" class="text-green-600 text-[10px]">✓</span>
                                <span v-else-if="!cond.met && cond.target > 0" class="text-red-500 text-[10px] whitespace-nowrap">
                                    Falta {{ formatMetricValue(Math.max(0, cond.target - cond.current), cond.target) }}
                                </span>
                            </span>
                        </div>
                        <div v-if="!cond._isMonthlyAmount" class="w-full h-2 rounded-full overflow-hidden"
                            :class="cond.target > 0 ? 'bg-[#f5f0eb]' : 'bg-gray-200'">
                            <div class="h-full rounded-full transition-all duration-700"
                                :class="cond.met ? 'bg-[#4a7c59]' : (cond.target > 0 ? 'bg-[#d9775b]' : 'bg-gray-300')"
                                :style="{ width: cond.met ? '100%' : (cond.target > 0 ? `${Math.min((cond.current / cond.target) * 100, 100)}%` : '0%') }">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Indicador de anticipos -->
                <div v-if="bonus.requires_anticipos" class="text-[11px] text-amber-600 font-medium italic">
                    Permite anticipos mensuales
                </div>

                <!-- Pago obtenido: nivel, porcentaje y monto -->
                <div v-if="bonus.unlocked && earnedLabel(bonus)" class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">{{ bonus.monthly_total ? 'Total del periodo' : 'Nivel alcanzado' }}</span>
                    <span class="font-semibold text-green-700">
                        {{ earnedLabel(bonus) }}
                    </span>
                </div>
                <div v-if="bonus.unlocked && bonus.earned?.note" class="text-[11px] text-gray-400 text-right -mt-1">
                    {{ bonus.earned.note }}
                </div>

                <!-- Alerta: lo que falta para el siguiente nivel -->
                <div
                    v-if="bonus.next_level && bonus.next_level.gaps?.length"
                    class="flex items-start gap-1.5 px-2.5 py-1.5 bg-amber-50/70 border border-amber-100 rounded-lg text-[11px] text-amber-800"
                >
                    <Info class="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-amber-500" />
                    <span>{{ bonus.next_level.message }}</span>
                </div>

                <!-- Desglose mes a mes (bonos mensuales) -->
                <MonthlyBonusBreakdown v-if="hasMonthlyBreakdown(bonus)" :months="bonus.monthly_breakdown" />

                <!-- Informativo: composición del pago (efectivo / automático) -->
                <BonusPaymentInfo v-if="bonus.payment_info" :info="bonus.payment_info" />

                <!-- Anticipos mensuales (bonos de 1er año de vida) -->
                <BonusAdvancesTable
                    v-if="bonus.advances?.months?.length"
                    :advances="bonus.advances"
                    :scheme-id="bonus.scheme_id"
                    :user-type="type"
                    :user-id="userId"
                />

                <!-- Alerta: periodo de evaluación más amplio que el rango visual -->
                <div v-if="bonus.mostrar_alerta_periodo" class="flex items-start gap-1.5 px-2.5 py-1.5 bg-blue-50/60 border border-blue-100 rounded-lg text-[11px] text-blue-700">
                    <Info class="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-blue-500" />
                    <span>Progreso acumulado del periodo <strong>{{ bonus.periodo_evaluado }}</strong>. Incluye meses anteriores por la frecuencia del bono.</span>
                </div>
            </div>
        </div>

        <div v-else class="px-5 py-10 text-center text-gray-400 text-sm">
            No hay bonos configurados.
        </div>

    </div>

    <!-- Consulta de la matriz de compensación del bono seleccionado -->
    <BonusMatrixModal
        v-if="openMatrixIndex !== null && bonuses[openMatrixIndex]"
        :bonus="bonuses[openMatrixIndex]"
        @close="closeMatrix"
    />
</template>
