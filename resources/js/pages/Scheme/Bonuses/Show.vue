<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    scheme: {
        id: number;
        name: string;
        type: string;
        template_key: string | null;
        target: string;
        is_active: boolean;
        metric_base: string | null;
        frequency: string | null;
        requires_anticipos: boolean;
        anticipos_config: Record<string, any> | null;
        applies_annual_adjustment: boolean;
        requires_product: string[] | null;
        min_product_count: number;
        requires_mix: boolean;
        dependency_scheme_id: string | null;
        min_irp: number;
        min_collection_efficiency: number;
        quarterly_recruits: Record<string, any> | null;
        pna_equivalences: Array<{
            min_pna: number;
            max_pna: number | null;
            policies: number;
        }> | null;
        versions: Array<{
            id: number;
            version_name: string;
            starts_at: string;
            ends_at: string | null;
            tiers: Array<{
                id: number;
                conditions: Record<string, any>;
                agent_percentage: string;
                agent_automatic_percentage: string;
                promoter_percentage: string;
            }>;
        }>;
    };
}>();

const breadcrumbs = [
    { title: 'Bonos', href: '/esquemas/bonos' },
    { title: 'Información', href: '' },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Detalles - ${scheme.name}`" />

        <div class="py-12 bg-slate-50 min-h-screen">
            <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-8">
                
                <!-- Encabezado con Diseño Moderno -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h2 class="text-3xl font-bold bg-clip-text text-zinc-800">
                            {{ scheme.name }}
                        </h2>
                    </div>
                    <div class="flex items-center gap-3">
                        <span :class="scheme.is_active ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-rose-100 text-rose-700 border-rose-200'" class="px-4 py-1.5 rounded-full text-sm font-semibold border shadow-sm">
                            {{ scheme.is_active ? 'Activo' : 'Inactivo' }}
                        </span>
                        <span class="px-4 py-1.5 rounded-full text-sm font-semibold bg-amber-100 text-amber-700 border border-amber-200 shadow-sm capitalize">
                            {{ scheme.type === 'commission' ? 'Comisión' : 'Bono' }}
                        </span>
                    </div>
                </div>

                <!-- Tarjeta Principal: Información General & Reglas -->
                <div class="bg-white rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] border border-slate-100 overflow-hidden">
                    <div class="p-8">
                        <h3 class="text-xl font-bold text-slate-800 mb-6 flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Parámetros Generales
                        </h3>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <!-- Celdas estilo iOS -->
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Dirigido A</span>
                                <span class="block text-base font-medium text-slate-800">{{ scheme.target === 'both' ? 'Agente y Promotor' : (scheme.target === 'agent' ? 'Agente' : 'Promotor') }}</span>
                            </div>
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Métrica Base</span>
                                <span class="block text-base font-medium text-slate-800 uppercase">{{ scheme.metric_base || 'N/A' }}</span>
                            </div>
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Frecuencia</span>
                                <span class="block text-base font-medium text-slate-800 capitalize">{{ scheme.frequency || 'N/A' }}</span>
                            </div>
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Ajuste Anual</span>
                                <span class="block text-base font-medium text-slate-800">{{ scheme.applies_annual_adjustment ? 'Aplica' : 'No Aplica' }}</span>
                            </div>
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Anticipos</span>
                                <span class="block text-base font-medium text-slate-800">{{ scheme.requires_anticipos ? 'Permitidos' : 'No Permitidos' }}</span>
                            </div>
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">IRP Mínimo</span>
                                <span class="block text-base font-medium text-slate-800">{{ scheme.min_irp || 0 }}%</span>
                            </div>
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Eficiencia Cobro</span>
                                <span class="block text-base font-medium text-slate-800">{{ scheme.min_collection_efficiency || 0 }}%</span>
                            </div>
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Dependencia</span>
                                <span class="block text-base font-medium text-slate-800 truncate" :title="scheme.dependency_scheme_id || 'Ninguna'">{{ scheme.dependency_scheme_id || 'Ninguna' }}</span>
                            </div>
                        </div>

                        <!-- Sección de Productos (Si aplica) -->
                        <div class="mt-6 p-5 rounded-2xl bg-indigo-50/50 border border-indigo-100 flex flex-col sm:flex-row gap-6 items-start sm:items-center">
                            <div>
                                <span class="block text-xs font-semibold text-indigo-400 uppercase tracking-wider mb-1">Mix Requerido</span>
                                <span class="block text-base font-medium text-indigo-900">{{ scheme.requires_mix ? 'Sí' : 'No' }}</span>
                            </div>
                            <div class="flex-1">
                                <span class="block text-xs font-semibold text-indigo-400 uppercase tracking-wider mb-1">Productos Válidos</span>
                                <span class="block text-base font-medium text-indigo-900">
                                    {{ scheme.requires_product && scheme.requires_product.length > 0 ? scheme.requires_product.join(', ') : 'Todos los productos' }}
                                    <span v-if="scheme.min_product_count > 0" class="text-indigo-600 text-sm ml-1">(Mínimo {{ scheme.min_product_count }})</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tarjeta de Equivalencias PNA -->
                <div v-if="scheme.pna_equivalences && scheme.pna_equivalences.length > 0" class="bg-white rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] border border-slate-100 overflow-hidden p-8">
                    <h3 class="text-xl font-bold text-slate-800 mb-6 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                        </svg>
                        Equivalencias PNA a Pólizas
                    </h3>
                    <div class="rounded-2xl border border-slate-100 overflow-hidden">
                        <el-table :data="scheme.pna_equivalences" stripe style="width: 100%">
                            <el-table-column label="Mínimo PNA ($)" min-width="150">
                                <template #default="scope">
                                    <span class="font-medium text-slate-700">{{ scope.row.min_pna | currency }}</span>
                                </template>
                            </el-table-column>
                            <el-table-column label="Máximo PNA ($)" min-width="150">
                                <template #default="scope">
                                    <span class="font-medium text-slate-700">{{ scope.row.max_pna !== null && scope.row.max_pna !== undefined ? scope.row.max_pna : 'Sin límite' }}</span>
                                </template>
                            </el-table-column>
                            <el-table-column label="Valor en Pólizas" min-width="150">
                                <template #default="scope">
                                    <span class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 font-bold text-sm">
                                        {{ scope.row.policies }}
                                    </span>
                                </template>
                            </el-table-column>
                        </el-table>
                    </div>
                </div>

                <!-- Tarjetas de Versiones y Porcentajes -->
                <div v-if="scheme.versions && scheme.versions.length > 0" class="space-y-6">
                    <div v-for="version in scheme.versions" :key="version.id" class="bg-white rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] border border-slate-100 overflow-hidden">
                        
                        <div class="px-8 py-6 bg-slate-50/50 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <div>
                                <h3 class="text-xl font-bold text-slate-800">Versión: {{ version.version_name }}</h3>
                            </div>
                            <div class="flex items-center gap-2 text-sm text-slate-500 bg-white px-4 py-2 rounded-xl border border-slate-100 shadow-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <span>{{ version.starts_at }}</span>
                                <span>&rarr;</span>
                                <span>{{ version.ends_at ? version.ends_at : 'Vigente' }}</span>
                            </div>
                        </div>

                        <div class="p-8">
                            <div class="rounded-2xl border border-slate-100 overflow-hidden">
                                <el-table :data="version.tiers" stripe style="width: 100%">
                                    <el-table-column label="Condiciones Dinámicas" min-width="300">
                                        <template #default="scope">
                                            <div v-if="scope.row.conditions" class="flex flex-wrap gap-2 py-2">
                                                <div v-for="(value, key) in scope.row.conditions" :key="key" class="inline-flex items-center gap-1 bg-slate-100 text-slate-600 px-3 py-1 rounded-lg text-sm border border-slate-200">
                                                    <span class="font-semibold capitalize">{{ key.replace(/_/g, ' ') }}:</span> 
                                                    <span>{{ value !== null && value !== undefined ? value : '∞' }}</span>
                                                </div>
                                            </div>
                                        </template>
                                    </el-table-column>
                                    <el-table-column v-if="scheme.target === 'agent' || scheme.target === 'both'" min-width="140" align="center">
                                        <template #header>
                                            <span v-if="scheme.template_key === 'agent_first_year_production'">Bono Automático (%)</span>
                                            <span v-else>Bono Agente (%)</span>
                                        </template>
                                        <template #default="scope">
                                            <span v-if="scheme.template_key === 'agent_first_year_production'" class="font-bold text-slate-700 text-lg">{{ scope.row.agent_automatic_percentage }}%</span>
                                            <span v-else class="font-bold text-slate-700 text-lg">{{ scope.row.agent_percentage }}%</span>
                                        </template>
                                    </el-table-column>
                                    <el-table-column v-if="scheme.target === 'promoter' || scheme.target === 'both'" label="Bono Promotor (%)" min-width="140" align="center">
                                        <template #default="scope">
                                            <span class="font-bold text-slate-700 text-lg">{{ scope.row.promoter_percentage }}%</span>
                                        </template>
                                    </el-table-column>
                                </el-table>
                            </div>
                        </div>

                    </div>
                </div>
                
                <!-- Empty State Versiones -->
                <div v-else class="bg-white rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] border border-slate-100 p-12 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-slate-300 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    <h3 class="text-lg font-medium text-slate-900">Sin Versiones Configuradas</h3>
                    <p class="mt-1 text-sm text-slate-500">Este bono aún no tiene rangos ni versiones establecidas.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>