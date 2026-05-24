<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed, markRaw } from 'vue';
import TierActivityRatio from '../Bonuses/Partials/TierActivityRatio.vue';
import TierFirstYearProduction from '../Bonuses/Partials/TierFirstYearProduction.vue';
import TierAdditionalAgents from '../Bonuses/Partials/TierAdditionalAgents.vue';
import TierConnection from '../Bonuses/Partials/TierConnection.vue';
import TierMonthlyDevelopment from '../Bonuses/Partials/TierMonthlyDevelopment.vue';

const selectedTemplate = ref('activity_ratio');

const templates = {
     activity_ratio: {
         name: 'Compensación Vida Individual',
         code: 'agent_activity_ratio',
         target: 'agent',
         component: markRaw(TierActivityRatio),
         baseCondition: { classification: '', min_policies: 0, max_policies: undefined },
         pna_equivalences: [
              { min_pna: 16000, max_pna: 20999, policies: 0.5 },
              { min_pna: 21000, max_pna: 59000, policies: 1.0 },
              { min_pna: 60000, max_pna: 119999, policies: 1.5 },
              { min_pna: 120000, max_pna: undefined, policies: 2.0 }
          ],
         tiers: [{ conditions: { classification: 'Activo 12', min_policies: 1.0, max_policies: 1.49 }, agent_percentage: 2, promoter_percentage: 0 }]
     },
     first_year_production: {
         name: 'Producción de 1er Año Trimestral',
         code: 'promoter_first_year_production',
         target: 'promoter',
         component: markRaw(TierFirstYearProduction),
         baseCondition: { min_pp: 0, min_irp: 0, max_irp: undefined },
         tiers: [{ conditions: { min_pp: 555000, min_irp: 91, max_irp: 93.99 }, agent_percentage: 0, promoter_percentage: 18 }]
     },
     additional_agents: {
         name: 'Adicional por Agentes con Compensación',
         code: 'promoter_additional_agents',
         target: 'promoter',
         component: markRaw(TierAdditionalAgents),
         baseCondition: { min_agents: 0, max_agents: undefined },
         tiers: [{ conditions: { min_agents: 1, max_agents: 1 }, agent_percentage: 0, promoter_percentage: 2 }]
    },
     connection: {
         name: 'Conexión',
         code: 'promoter_connection',
         target: 'promoter',
         component: markRaw(TierConnection),
         baseCondition: { min_recruits: 0, max_recruits: undefined, min_pca: 0 },
         tiers: [{ conditions: { min_recruits: 1, max_recruits: 2, min_pca: 125000 }, agent_percentage: 0, promoter_percentage: 9 }]
     },
     monthly_development: {
         name: 'Desarrollo Mensual',
         code: 'promoter_monthly_development',
         target: 'promoter',
         component: markRaw(TierMonthlyDevelopment),
         baseCondition: { min_pca: 0, min_month: 1, max_month: 12 },
         tiers: [{ conditions: { min_pca: 125000, min_month: 1, max_month: 12 }, agent_percentage: 0, promoter_percentage: 9 }]
     }
 };

const currentTemplate = computed(() => templates[selectedTemplate.value as keyof typeof templates]); 

const form = useForm({
    name: templates.activity_ratio.name,
    code: templates.activity_ratio.code,
    type: 'bonus',
    target: templates.activity_ratio.target,
    is_active: true,
    version_name: 'Tabulador Inicial',
    starts_at: new Date().toISOString().split('T')[0],
    ends_at: '',
    pna_equivalences: JSON.parse(JSON.stringify(templates.activity_ratio.pna_equivalences)),
    tiers: JSON.parse(JSON.stringify(templates.activity_ratio.tiers))
});

watch(selectedTemplate, (newVal) => {
     const tpl = templates[newVal as keyof typeof templates];
     form.name = tpl.name;
     form.code = tpl.code;
     form.target = tpl.target;
     form.pna_equivalences = tpl.pna_equivalences ? JSON.parse(JSON.stringify(tpl.pna_equivalences)) : [];
     form.tiers = JSON.parse(JSON.stringify(tpl.tiers));
});

const addTier = () => {
     form.tiers.push({ 
         conditions: { ...currentTemplate.value.baseCondition }, 
         agent_percentage: 0, 
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
            <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
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
                            <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">1. Datos Generales</h3>
                            
                            <div class="mb-6 bg-gray-50 p-4 rounded-md border border-gray-200">
                                <label class="block text-sm font-bold text-gray-900 mb-2">Plantilla de Bono</label>
                                <el-select v-model="selectedTemplate" style="width: 100%;">
                                    <el-option label="Compensación Vida Individual (Agentes)" value="activity_ratio" />
                                    <el-option label="Producción de 1er Año Trimestral" value="first_year_production" />
                                    <el-option label="Adicional por Agentes con Compensación" value="additional_agents" />
                                    <el-option label="Conexión (Reclutamiento PCA)" value="connection" />
                                    <el-option label="Desarrollo Mensual" value="monthly_development" />
                                </el-select>
                                <p class="text-xs text-gray-500 mt-2">Al cambiar la plantilla, los campos de condiciones se adaptarán automáticamente a la estructura correcta.</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Bono</label>
                                    <el-input v-model="form.name" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Código Interno</label>
                                    <el-input v-model="form.code" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Dirigido a</label>
                                    <el-select v-model="form.target" class="w-full" disabled style="width: 100%;">
                                        <el-option label="Promotor" value="promoter" />
                                        <el-option label="Agente" value="agent" />
                                    </el-select>
                                    <p class="text-xs text-gray-500 mt-1">Definido automáticamente por la plantilla seleccionada.</p>
                                </div>
                                <div class="flex items-center mt-6">
                                    <el-checkbox v-model="form.is_active" label="Activar inmediatamente" size="large" />
                                </div>
                            </div>
                        </div>

                        <!-- Datos de la Versión -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">2. Vigencia</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de la Versión</label>
                                    <el-input v-model="form.version_name" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de Inicio</label>
                                    <el-date-picker 
                                        v-model="form.starts_at" 
                                        type="date" 
                                        value-format="YYYY-MM-DD" 
                                        style="width: 100%;" 
                                        required 
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de Término (Opcional)</label>
                                    <el-date-picker 
                                        v-model="form.ends_at" 
                                        type="date" 
                                        value-format="YYYY-MM-DD" 
                                        style="width: 100%;" 
                                        placeholder="Sin fecha límite"
                                    />
                                </div>
                            </div>
                        </div>

                        <div v-if="selectedTemplate === 'activity_ratio'">
                            <div class="flex justify-between items-center border-b pb-2 mb-4">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">3. Reglas de Equivalencia (PNA a Pólizas)</h3>
                                    <p class="text-sm text-gray-500">Define a cuántas pólizas equivale el rango de Prima Neta Anual (PNA).</p>
                                </div>
                                <el-button type="success" plain size="small" @click="addEquivalence">
                                    + Agregar Regla
                                </el-button>
                            </div>

                            <div class="space-y-3 mb-8">
                                <div v-for="(eq, index) in form.pna_equivalences" :key="index" class="flex flex-wrap md:flex-nowrap items-center gap-4 bg-gray-50 p-4 rounded-md border border-gray-200">
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Mínimo PNA ($)</label>
                                        <el-input-number v-model="eq.min_pna" :min="0" :step="1000" style="width: 100%;" />
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Máximo PNA ($)</label>
                                        <el-input-number v-model="eq.max_pna" :min="0" :step="1000" style="width: 100%;" placeholder="Sin límite" />
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Valor en Pólizas</label>
                                        <el-input-number v-model="eq.policies" :min="0" :step="0.5" style="width: 100%;" />
                                    </div>
                                    <el-button type="danger" plain @click="removeEquivalence(index)" class="mt-5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                        </svg>
                                    </el-button>
                                </div>
                                <div v-if="!form.pna_equivalences?.length" class="text-sm text-gray-500 text-center py-4 border-2 border-dashed border-gray-200 rounded-lg">
                                    No hay reglas configuradas. Haz clic en "Agregar Regla" para definir las equivalencias.
                                </div>
                            </div>
                        </div>
                        
                        <!-- Condiciones y Porcentajes -->
                        <div>
                            <div class="flex justify-between items-center border-b pb-2 mb-4">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">{{ selectedTemplate === 'activity_ratio' ? '4.' : '3.' }} Reglas del Bono</h3>
                                    <p class="text-sm text-gray-500">Define las metas y compensaciones para esta plantilla.</p>
                                </div>
                                <el-button type="primary" plain size="small" @click="addTier">
                                    + Agregar Nivel
                                </el-button>
                            </div>

                            <div class="space-y-4">
                                <div v-for="(tier, index) in form.tiers" :key="index" class="flex flex-wrap md:flex-nowrap items-end gap-4 p-4 border rounded-md bg-gray-50">
                                    <!-- RENDERIZADO DINÁMICO DE COMPONENTES DE CONDICIÓN -->
                                     <component 
                                         :is="currentTemplate.component" 
                                         :conditions="tier.conditions" 
                                     />

                                    <div class="flex-1 min-w-[140px]" v-if="form.target === 'agent' || form.target === 'both'">
                                         <label class="block text-sm font-medium text-gray-700 mb-1">Bono Agente (%)</label>
                                         <el-input-number v-model="tier.agent_percentage" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" required />
                                     </div>
                                     <div class="flex-1 min-w-[140px]" v-if="form.target === 'promoter' || form.target === 'both'">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Bono Promotor (%)</label>
                                        <el-input-number 
                                            v-model="tier.promoter_percentage" 
                                            :min="0" 
                                            :max="100" 
                                            :step="0.01" 
                                            :precision="2" 
                                            style="width: 100%;" 
                                            placeholder="Ej. 2" 
                                            required 
                                        />
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
                                Guardar Bono
                            </el-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>