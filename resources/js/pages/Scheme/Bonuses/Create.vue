<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed, markRaw } from 'vue';
import TierActivityRatio from '../Bonuses/Partials/TierActivityRatio.vue';
import TierFirstYearProduction from '../Bonuses/Partials/TierFirstYearProduction.vue';
import TierAgentFirstYearProduction from '../Bonuses/Partials/TierAgentFirstYearProduction.vue';
import TierAdditionalAgents from '../Bonuses/Partials/TierAdditionalAgents.vue';
import TierConnection from '../Bonuses/Partials/TierConnection.vue';
import TierMonthlyDevelopment from '../Bonuses/Partials/TierMonthlyDevelopment.vue';

const selectedTemplate = ref('activity_ratio');

const templates = {
    activity_ratio: {
        name: 'Activity Ratio',
        target: 'agent',
        metric_base: 'PCA',
        frequency: 'trimestral',
        requires_anticipos: false,
        anticipos_config: { month_1_min: 0, month_2_min: 0 },
        applies_annual_adjustment: true,
        requires_product: ['Vida'],
        min_product_count: 0,
        requires_mix: false,
        dependency_scheme_id: 'produccion_1er_ano_vida',
        min_irp: 0,
        min_collection_efficiency: 0,
        quarterly_recruits: { q1: 0, q2: 0, q3: 0, q4: 0 },
        component: markRaw(TierActivityRatio),
        baseCondition: { classification: '', min_policies: 0, max_policies: undefined },
        pna_equivalences: [
            { min_pna: 16000, max_pna: 20999, policies: 0.5 },
            { min_pna: 21000, max_pna: 59000, policies: 1.0 },
            { min_pna: 60000, max_pna: 119999, policies: 1.5 },
            { min_pna: 120000, max_pna: undefined, policies: 2.0 }
        ],
        tiers: [
            { conditions: { classification: 'Activo 12', min_policies: 1.0, max_policies: 1.49 }, agent_percentage: 2},
            { conditions: { classification: 'Activo 18', min_policies: 1.5, max_policies: 1.99 }, agent_percentage: 3},
            { conditions: { classification: 'Productivo 24', min_policies: 2.0, max_policies: 2.99 }, agent_percentage: 5 },
            { conditions: { classification: 'Productivo 36', min_policies: 3.0, max_policies: undefined }, agent_percentage: 7}        
        ]
    },
    agent_first_year_production: {
        name: 'Producción 1er Año Vida Trimestral (3 meses)',
        target: 'agent',
        metric_base: 'PCA',
        frequency: 'trimestral',
        requires_anticipos: true,
        anticipos_config: { month_1_min: 0, month_2_min: 0 },
        applies_annual_adjustment: false,
        requires_product: ['Vida', 'Primordial'],
        min_product_count: 2,
        requires_mix: true,
        dependency_scheme_id: null,
        min_irp: 0,
        min_collection_efficiency: 0,
        quarterly_recruits: { q1: 0, q2: 0, q3: 0, q4: 0 },
        component: markRaw(TierAgentFirstYearProduction),
        baseCondition: { min_pca: 0 },
        pna_equivalences: [],
        tiers: [
            { conditions: { min_pca: 785000 }, agent_percentage: 36, agent_automatic_percentage: 44, promoter_percentage: 0 },
            { conditions: { min_pca: 650000 }, agent_percentage: 32, agent_automatic_percentage: 40, promoter_percentage: 0 },
            { conditions: { min_pca: 525000 }, agent_percentage: 28, agent_automatic_percentage: 34, promoter_percentage: 0 },
            { conditions: { min_pca: 460000 }, agent_percentage: 26, agent_automatic_percentage: 32, promoter_percentage: 0 },
            { conditions: { min_pca: 395000 }, agent_percentage: 24, agent_automatic_percentage: 30, promoter_percentage: 0 },
            { conditions: { min_pca: 330000 }, agent_percentage: 21, agent_automatic_percentage: 27, promoter_percentage: 0 },
            { conditions: { min_pca: 260000 }, agent_percentage: 19, agent_automatic_percentage: 25, promoter_percentage: 0 },
            { conditions: { min_pca: 200000 }, agent_percentage: 14, agent_automatic_percentage: 20, promoter_percentage: 0 },
            { conditions: { min_pca: 130000 }, agent_percentage: 10, agent_automatic_percentage: 16, promoter_percentage: 0 },
        ]
    },
    first_year_production: {
        name: 'Producción de 1er Año Trimestral',
        target: 'promoter',
        metric_base: 'PP',
        frequency: 'trimestral',
        requires_anticipos: true,
        anticipos_config: { month_1_min: 86666, month_2_min: 173333 },
        applies_annual_adjustment: true,
        requires_product: ['Primordial'],
        min_product_count: 3,
        requires_mix: false,
        dependency_scheme_id: null,
        min_irp: 91,
        min_collection_efficiency: 0,
        quarterly_recruits: { q1: 2, q2: 3, q3: 4, q4: 6 },
        component: markRaw(TierFirstYearProduction),
        baseCondition: { min_pp: 0, min_irp: 0, max_irp: undefined },
        pna_equivalences: [],
        tiers: [{ conditions: { min_pp: 555000, min_irp: 91, max_irp: 93.99 }, agent_percentage: 0, agent_automatic_percentage: 0, promoter_percentage: 18 }]
    },
    additional_agents: {
        name: 'Adicional por Agentes con Compensación',
        target: 'promoter',
        metric_base: 'PP',
        frequency: 'trimestral',
        requires_anticipos: false,
        anticipos_config: { month_1_min: 0, month_2_min: 0 },
        applies_annual_adjustment: false,
        requires_product: [],
        min_product_count: 0,
        requires_mix: false,
        dependency_scheme_id: 'produccion_1er_ano_vida',
        min_irp: 0,
        min_collection_efficiency: 0,
        quarterly_recruits: { q1: 0, q2: 0, q3: 0, q4: 0 },
        component: markRaw(TierAdditionalAgents),
        baseCondition: { min_agents: 0, max_agents: undefined },
        pna_equivalences: [],
        tiers: [{ conditions: { min_agents: 1, max_agents: 1 }, agent_percentage: 0, agent_automatic_percentage: 0, promoter_percentage: 2 }]
    },
    connection: {
        name: 'Conexión',
        target: 'promoter',
        metric_base: 'PCA',
        frequency: 'mensual',
        requires_anticipos: false,
        anticipos_config: { month_1_min: 0, month_2_min: 0 },
        applies_annual_adjustment: false,
        requires_product: [],
        min_product_count: 0,
        requires_mix: false,
        dependency_scheme_id: null,
        min_irp: 0,
        min_collection_efficiency: 0,
        quarterly_recruits: { q1: 1, q2: 2, q3: 3, q4: 4 },
        component: markRaw(TierConnection),
        baseCondition: { min_recruits: 0, max_recruits: undefined, min_pca: 0 },
        pna_equivalences: [],
        tiers: [{ conditions: { min_recruits: 1, max_recruits: 2, min_pca: 125000 }, agent_percentage: 0, agent_automatic_percentage: 0, promoter_percentage: 9 }]
    },
    monthly_development: {
        name: 'Desarrollo Mensual',
        target: 'promoter',
        metric_base: 'PCA',
        frequency: 'mensual',
        requires_anticipos: false,
        anticipos_config: { month_1_min: 0, month_2_min: 0 },
        applies_annual_adjustment: false,
        requires_product: [],
        min_product_count: 0,
        requires_mix: false,
        dependency_scheme_id: null,
        min_irp: 0,
        min_collection_efficiency: 81,
        quarterly_recruits: { q1: 1, q2: 2, q3: 3, q4: 4 },
        component: markRaw(TierMonthlyDevelopment),
        baseCondition: { min_pca: 0, min_month: 1, max_month: 12 },
        pna_equivalences: [],
        tiers: [{ conditions: { min_pca: 125000, min_month: 1, max_month: 12 }, agent_percentage: 0, agent_automatic_percentage: 0, promoter_percentage: 9 }]
    }
};

const currentTemplate = computed(() => templates[selectedTemplate.value as keyof typeof templates]);

const form = useForm({
    name: templates.activity_ratio.name,
    type: 'bonus',
    target: templates.activity_ratio.target,
    is_active: true,
    version_name: 'Tabulador Inicial',
    starts_at: new Date().toISOString().split('T')[0],
    ends_at: '',
    
    // Reglas Globales
    metric_base: templates.activity_ratio.metric_base,
    frequency: templates.activity_ratio.frequency,
    requires_anticipos: templates.activity_ratio.requires_anticipos,
    anticipos_config: { ...templates.activity_ratio.anticipos_config },
    applies_annual_adjustment: templates.activity_ratio.applies_annual_adjustment,
    requires_product: [...templates.activity_ratio.requires_product],
    min_product_count: templates.activity_ratio.min_product_count,
    requires_mix: templates.activity_ratio.requires_mix,
    dependency_scheme_id: templates.activity_ratio.dependency_scheme_id,
    min_irp: templates.activity_ratio.min_irp,
    min_collection_efficiency: templates.activity_ratio.min_collection_efficiency,
    quarterly_recruits: { ...templates.activity_ratio.quarterly_recruits },
    
    pna_equivalences: JSON.parse(JSON.stringify(templates.activity_ratio.pna_equivalences)),
    tiers: JSON.parse(JSON.stringify(templates.activity_ratio.tiers))
});

watch(selectedTemplate, (newVal) => {
    const tpl = templates[newVal as keyof typeof templates];
    form.name = tpl.name;
    form.target = tpl.target;
    
    form.metric_base = tpl.metric_base;
    form.frequency = tpl.frequency;
    form.requires_anticipos = tpl.requires_anticipos;
    form.anticipos_config = { ...tpl.anticipos_config };
    form.applies_annual_adjustment = tpl.applies_annual_adjustment;
    form.requires_product = [...tpl.requires_product];
    form.min_product_count = tpl.min_product_count;
    form.requires_mix = tpl.requires_mix;
    form.dependency_scheme_id = tpl.dependency_scheme_id;
    form.min_irp = tpl.min_irp;
    form.min_collection_efficiency = tpl.min_collection_efficiency;
    form.quarterly_recruits = { ...tpl.quarterly_recruits };

    form.pna_equivalences = tpl.pna_equivalences ? JSON.parse(JSON.stringify(tpl.pna_equivalences)) : [];
    form.tiers = JSON.parse(JSON.stringify(tpl.tiers));
});

const addTier = () => {
    form.tiers.push({
        conditions: { ...currentTemplate.value.baseCondition },
        agent_percentage: 0,
        agent_automatic_percentage: 0,
        promoter_percentage: 0
    });
};

const addEquivalence = () => {
    if (!form.pna_equivalences) form.pna_equivalences = [];
    form.pna_equivalences.push({ min_pna: 0, max_pna: undefined, policies: 1 });
};

const removeEquivalence = (index: number) => {
    form.pna_equivalences.splice(index, 1);
};

const removeTier = (index: number) => {
    form.tiers.splice(index, 1);
};

const submit = () => {
    form.post('/schemes');
};
</script>

<template>
    <AppLayout>
        <Head title="Nuevo Bono" />

        <div class="py-12">
            <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm sm:rounded-lg border border-gray-100 p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-semibold">Crear Esquema de Bono</h2>
                        <Link href="/esquemas/bonos" class="text-gray-500 hover:text-gray-700 text-sm">
                            &larr; Volver
                        </Link>
                    </div>

                    <form @submit.prevent="submit" class="space-y-8">
                        <!-- Datos del Esquema -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">1. Identidad del Esquema</h3>
                            
                            <div class="mb-6 bg-gray-50 p-4 rounded-md border border-gray-200">
                                <label class="block text-sm font-bold text-gray-900 mb-2">Plantilla de Bono</label>
                                <el-select v-model="selectedTemplate" style="width: 100%;">
                                    <el-option label="Activity Ratio (Agentes)" value="activity_ratio" />
                                    <el-option label="Producción 1er Año Vida Trimestral (Agentes)" value="agent_first_year_production" />
                                    <el-option label="Producción de 1er Año Trimestral (Promotor)" value="first_year_production" />
                                    <el-option label="Adicional por Agentes con Compensación" value="additional_agents" />
                                    <el-option label="Conexión (Reclutamiento PCA)" value="connection" />
                                    <el-option label="Desarrollo Mensual" value="monthly_development" />
                                </el-select>
                                <p class="text-xs text-gray-500 mt-2">Las reglas y condiciones globales se adaptarán automáticamente a la plantilla.</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Bono</label>
                                    <el-input v-model="form.name" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Dirigido a</label>
                                    <el-select v-model="form.target" style="width: 100%;" disabled>
                                        <el-option label="Promotor" value="promoter" />
                                        <el-option label="Agente" value="agent" />
                                    </el-select>
                                </div>
                            </div>
                        </div>

                        <!-- Reglas de Frecuencia y Conciliación -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">2. Reglas de Frecuencia y Conciliación</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Métrica Base</label>
                                    <el-select v-model="form.metric_base" style="width: 100%;">
                                        <el-option label="PCA (Prima Computable Ajustada)" value="PCA" />
                                        <el-option label="PP (Prima Pagada)" value="PP" />
                                        <el-option label="PNA (Prima Nueva Anualizada)" value="PNA" />
                                    </el-select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Frecuencia de Evaluación</label>
                                    <el-select v-model="form.frequency" style="width: 100%;">
                                        <el-option label="Mensual" value="mensual" />
                                        <el-option label="Trimestral" value="trimestral" />
                                        <el-option label="Anual" value="anual" />
                                    </el-select>
                                </div>
                                <div class="flex flex-col justify-center gap-2">
                                    <el-checkbox v-model="form.applies_annual_adjustment" label="Aplica Ajuste Anual al cierre de diciembre" />
                                    <el-checkbox v-model="form.requires_anticipos" label="Permite Adelantos / Anticipos Mensuales" />
                                </div>
                            </div>

                            <div v-if="form.requires_anticipos" class="mt-4 p-4 bg-blue-50 border border-blue-100 rounded-md grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-blue-900 mb-1">Meta Mínima Mes 1 para Anticipo ($)</label>
                                    <el-input-number v-model="form.anticipos_config.month_1_min" :min="0" :step="0.01" :precision="2" style="width: 100%;" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-blue-900 mb-1">Meta Acumulada Mín. Mes 2 para Anticipo ($)</label>
                                    <el-input-number v-model="form.anticipos_config.month_2_min" :min="0" :step="0.01" :precision="2" style="width: 100%;" />
                                </div>
                            </div>
                        </div>

                        <!-- Validaciones de Portafolio y Dependencias -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">3. Validaciones de Portafolio y Métricas</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Productos Requeridos (Opcional)</label>
                                        <el-select v-model="form.requires_product" multiple placeholder="Selecciona productos..." style="width: 100%;">
                                            <el-option label="Vida" value="Vida" />
                                            <el-option label="Primordial" value="Primordial" />
                                            <el-option label="Gastos Médicos" value="GMM" />
                                        </el-select>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <div class="flex-1">
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad Mínima Requerida</label>
                                            <el-input-number v-model="form.min_product_count" :min="0" style="width: 100%;" />
                                        </div>
                                        <div class="flex-1 mt-6">
                                            <el-checkbox v-model="form.requires_mix" label="Exigir Mix de Ramos" />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Dependencia de otro Bono</label>
                                        <el-select v-model="form.dependency_scheme_id" clearable placeholder="Debe ganar primero..." style="width: 100%;">
                                            <el-option label="Producción 1er Año Vida" value="produccion_1er_ano_vida" />
                                            <!-- Aquí irían los bonos desde BD -->
                                        </el-select>
                                    </div>
                                </div>
                                <div class="space-y-4 border-l pl-6 border-gray-100">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Índice de Retención Mínimo (IRP %)</label>
                                        <el-input-number v-model="form.min_irp" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Eficiencia de Cobro Mínima (%)</label>
                                        <el-input-number v-model="form.min_collection_efficiency" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" />
                                    </div>
                                </div>
                            </div>

                            <div v-if="form.target === 'promoter'" class="p-4 bg-gray-50 border border-gray-200 rounded-md">
                                <label class="block text-sm font-bold text-gray-700 mb-3">Metas de Reclutas Acumulados por Trimestre (Año Calendario)</label>
                                <div class="grid grid-cols-4 gap-4">
                                    <div>
                                        <span class="text-xs text-gray-500">1Q (Q1)</span>
                                        <el-input-number v-model="form.quarterly_recruits.q1" :min="0" style="width: 100%;" />
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500">2Q (Q2)</span>
                                        <el-input-number v-model="form.quarterly_recruits.q2" :min="0" style="width: 100%;" />
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500">3Q (Q3)</span>
                                        <el-input-number v-model="form.quarterly_recruits.q3" :min="0" style="width: 100%;" />
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500">4Q (Q4)</span>
                                        <el-input-number v-model="form.quarterly_recruits.q4" :min="0" style="width: 100%;" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Datos de la Versión -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">4. Vigencia de la Versión</h3>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                                <div class="col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de la Versión</label>
                                    <el-input v-model="form.version_name" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de Inicio</label>
                                    <el-date-picker v-model="form.starts_at" type="date" value-format="YYYY-MM-DD" style="width: 100%;" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de Término</label>
                                    <el-date-picker v-model="form.ends_at" type="date" value-format="YYYY-MM-DD" style="width: 100%;" placeholder="Sin límite" />
                                </div>
                            </div>
                            <div class="mt-4">
                                <el-checkbox v-model="form.is_active" label="Activar inmediatamente" />
                            </div>
                        </div>

                        <!-- Equivalencias PNA (Si aplica) -->
                        <div v-if="selectedTemplate === 'activity_ratio'">
                            <div class="flex justify-between items-center border-b pb-2 mb-4">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">5. Reglas de Equivalencia (PNA a Pólizas)</h3>
                                </div>
                                <el-button type="success" plain size="small" @click="addEquivalence">+ Agregar Regla</el-button>
                            </div>
                            <div class="space-y-3 mb-8">
                                <div v-for="(eq, index) in form.pna_equivalences" :key="index" class="flex items-center gap-4 bg-gray-50 p-4 border rounded-md">
                                    <div class="flex-1">
                                        <label class="block text-xs text-gray-500 mb-1">Mínimo PNA ($)</label>
                                        <el-input-number v-model="eq.min_pna" :min="0" :step="0.01" :precision="2" style="width: 100%;" />
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-xs text-gray-500 mb-1">Máximo PNA ($)</label>
                                        <el-input-number v-model="eq.max_pna" :min="0" :step="0.01" :precision="2" style="width: 100%;" placeholder="Sin límite" />
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-xs text-gray-500 mb-1">Valor en Pólizas</label>
                                        <el-input-number v-model="eq.policies" :min="0" :step="0.5" :precision="2" style="width: 100%;" />
                                    </div>
                                    <el-button type="danger" plain @click="removeTier(index)" class="mt-2 md:mt-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                        </svg>
                                    </el-button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Condiciones y Porcentajes Dinámicos (Tiers) -->
                        <div>
                            <div class="flex justify-between items-center border-b pb-2 mb-4">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">{{ selectedTemplate === 'activity_ratio' ? '6.' : '5.' }} Matriz de Compensación</h3>
                                </div>
                                <el-button type="primary" plain size="small" @click="addTier">+ Agregar Nivel</el-button>
                            </div>

                            <div class="space-y-4">
                                <div v-for="(tier, index) in form.tiers" :key="index" class="flex flex-wrap md:flex-nowrap items-end gap-4 p-4 border rounded-md bg-gray-50">
                                     <component :is="currentTemplate.component" :conditions="tier.conditions" />

                                     <template v-if="selectedTemplate === 'agent_first_year_production'">
                                         <div class="flex-1 min-w-[140px]">
                                             <label class="block text-sm font-medium text-gray-700 mb-1">Bono Pago Directo (%)</label>
                                             <el-input-number v-model="tier.agent_percentage" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" required />
                                         </div>
                                         <div class="flex-1 min-w-[140px]">
                                             <label class="block text-sm font-medium text-gray-700 mb-1">Bono Automático (%)</label>
                                             <el-input-number v-model="tier.agent_automatic_percentage" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" required />
                                         </div>
                                     </template>
                                     <template v-else>
                                         <div class="flex-1 min-w-[140px]" v-if="form.target === 'agent' || form.target === 'both'">
                                             <label class="block text-sm font-medium text-gray-700 mb-1">Bono Agente (%)</label>
                                             <el-input-number v-model="tier.agent_percentage" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" required />
                                         </div>
                                     </template>

                                     <div class="flex-1 min-w-[140px]" v-if="form.target === 'promoter' || form.target === 'both'">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Bono Promotor (%)</label>
                                        <el-input-number v-model="tier.promoter_percentage" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" required />
                                    </div>
                                    <el-button type="danger" plain @click="removeTier(index)" class="mt-2 md:mt-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                        </svg>
                                    </el-button>
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="flex justify-end pt-4 border-t">
                            <el-button native-type="submit" color="#10b981" :loading="form.processing" size="large">
                                Guardar Bono Completo
                            </el-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>