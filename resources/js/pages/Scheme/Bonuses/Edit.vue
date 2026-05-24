<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, markRaw } from 'vue';
import TierActivityRatio from '../Bonuses/Partials/TierActivityRatio.vue';
import TierFirstYearProduction from '../Bonuses/Partials/TierFirstYearProduction.vue';
import TierAdditionalAgents from '../Bonuses/Partials/TierAdditionalAgents.vue';
import TierConnection from '../Bonuses/Partials/TierConnection.vue';
import TierMonthlyDevelopment from '../Bonuses/Partials/TierMonthlyDevelopment.vue';

const props = defineProps<{
    scheme: any;
}>();

const templates = {
    agent_activity_ratio: {
       target: 'agent',
        component: markRaw(TierActivityRatio),
        baseCondition: { classification: '', min_policies: 0, max_policies: undefined },
    },
    promoter_first_year_production: {
        target: 'promoter',
        component: markRaw(TierFirstYearProduction),
        baseCondition: { min_pp: 0, min_irp: 0, max_irp: undefined },
    },
    promoter_additional_agents: {
        target: 'promoter',
        component: markRaw(TierAdditionalAgents),
        baseCondition: { min_agents: 0, max_agents: undefined },
    },
    promoter_connection: {
        target: 'promoter',
        component: markRaw(TierConnection),
        baseCondition: { min_recruits: 0, max_recruits: undefined, min_pca: 0 },
    },
    promoter_monthly_development: {
        target: 'promoter',
        component: markRaw(TierMonthlyDevelopment),
        baseCondition: { min_pca: 0, min_month: 1, max_month: 12 },
    }
};

// Identificar plantilla por el código
const activeTemplateKey = Object.keys(templates).includes(props.scheme.code) 
    ? props.scheme.code 
    : 'agent_activity_ratio';

const currentTemplate = computed(() => templates[activeTemplateKey as keyof typeof templates]);

const currentVersion = props.scheme.versions[props.scheme.versions.length - 1] || {};

const form = useForm({
    name: props.scheme.name,
    code: props.scheme.code,
    type: props.scheme.type,
    target: props.scheme.target,
    is_active: props.scheme.is_active,
    version_name: currentVersion.version_name || 'Tabulador Inicial',
    starts_at: currentVersion.starts_at || new Date().toISOString().split('T')[0],
    ends_at: currentVersion.ends_at || '',
    tiers: currentVersion.tiers && currentVersion.tiers.length > 0
        ? JSON.parse(JSON.stringify(currentVersion.tiers))
        : [{ conditions: { ...currentTemplate.value.baseCondition }, agent_percentage: 0, promoter_percentage: 0 }]
});

const addTier = () => {
    form.tiers.push({ 
        conditions: { ...currentTemplate.value.baseCondition }, 
        agent_percentage: 0, 
        promoter_percentage: 0 
    });
};

const removeTier = (index: number) => {
    form.tiers.splice(index, 1);
};

const submit = () => {
    form.put(`/schemes/${props.scheme.id}`);
};
</script>

<template>
    <AppLayout>
        <Head title="Editar Bono" />

        <div class="py-12">
            <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm sm:rounded-lg border border-gray-100 p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-semibold">Editar Bono: {{ scheme.name }}</h2>
                        <Link href="/esquemas/bonos" class="text-gray-500 hover:text-gray-700 text-sm">
                            &larr; Volver
                        </Link>
                    </div>

                    <form @submit.prevent="submit" class="space-y-8">
                        <!-- Datos del Esquema -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">1. Datos Generales</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Bono</label>
                                    <el-input v-model="form.name" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Código Interno</label>
                                    <el-input v-model="form.code" disabled />
                                </div>
                                <div class="flex items-center mt-6">
                                    <el-checkbox v-model="form.is_active" label="Esquema Activo" size="large" />
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
                                        type="date" value-format="YYYY-MM-DD" style="width: 100%;" required 
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de Término (Opcional)</label>
                                    <el-date-picker 
                                        v-model="form.ends_at" 
                                        type="date" value-format="YYYY-MM-DD" style="width: 100%;" placeholder="Sin fecha límite"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Condiciones y Porcentajes -->
                        <div>
                            <div class="flex justify-between items-center border-b pb-2 mb-4">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">3. Reglas del Bono</h3>
                                </div>
                                <el-button type="primary" plain size="small" @click="addTier">
                                    + Agregar Nivel
                                </el-button>
                            </div>

                            <div class="space-y-4">
                                <div v-for="(tier, index) in form.tiers" :key="index" class="flex flex-wrap md:flex-nowrap items-end gap-4 p-4 border rounded-md bg-gray-50">
                                    <component :is="currentTemplate.component" :conditions="tier.conditions" />
                                    <div class="flex-1 min-w-[140px]" v-if="form.target === 'promoter' || form.target === 'both'">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Bono Promotor (%)</label>
                                        <el-input-number v-model="tier.promoter_percentage" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" required />
                                    </div>
                                    <el-button type="danger" plain @click="removeTier(index)" class="mt-2 md:mt-0">
                                        X
                                    </el-button>
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="flex justify-end pt-4 border-t">
                            <el-button native-type="submit" color="#10b981" :loading="form.processing" size="large">
                                Guardar Cambios
                            </el-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>