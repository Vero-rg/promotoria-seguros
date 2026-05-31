<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    scheme: {
        id: number;
        name: string;
        code: string;
        type: string;
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
        versions: Array<{
            id: number;
            version_name: string;
            starts_at: string;
            ends_at: string | null;
            tiers: Array<{
                id: number;
                conditions: Record<string, any>;
                agent_percentage: string;
                promoter_percentage: string;
            }>;
        }>;
    };
}>();

const breadcrumbs = [
    { title: 'Comisiones', href: '/esquemas' },
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
                        <h2 class="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-slate-800 to-slate-500 flex items-center gap-3">
                            {{ scheme.name }}
                            <span v-if="scheme.code" class="text-lg font-mono bg-slate-200 text-slate-600 px-3 py-1 rounded-lg">{{ scheme.code }}</span>
                        </h2>
                    </div>
                    <div class="flex items-center gap-3">
                        <span :class="scheme.is_active ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-rose-100 text-rose-700 border-rose-200'" class="px-4 py-1.5 rounded-full text-sm font-semibold border shadow-sm">
                            {{ scheme.is_active ? 'Activo' : 'Inactivo' }}
                        </span>
                        <span class="px-4 py-1.5 rounded-full text-sm font-semibold bg-emerald-100 text-emerald-700 border border-emerald-200 shadow-sm capitalize">
                            {{ scheme.type === 'commission' ? 'Comisión' : 'Bono' }}
                        </span>
                    </div>
                </div>

                <!-- Tarjeta Principal: Información General -->
                <div class="bg-white rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] border border-slate-100 overflow-hidden">
                    <div class="p-8">
                        <h3 class="text-xl font-bold text-slate-800 mb-6 flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Resumen de la Comisión
                        </h3>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <!-- Celdas estilo iOS -->
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Nombre Completo</span>
                                <span class="block text-base font-medium text-slate-800">{{ scheme.name }}</span>
                            </div>
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Código Interno</span>
                                <span class="block text-base font-medium text-slate-800">{{ scheme.code || 'N/A' }}</span>
                            </div>
                            <div class="p-5 rounded-2xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition-colors">
                                <span class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Aplicable A</span>
                                <span class="block text-base font-medium text-slate-800">{{ scheme.target === 'both' ? 'Agente y Promotor' : (scheme.target === 'agent' ? 'Agente' : 'Promotor') }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tarjetas de Versiones y Porcentajes -->
                <div v-if="scheme.versions && scheme.versions.length > 0" class="space-y-6">
                    <div v-for="version in scheme.versions" :key="version.id" class="bg-white rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] border border-slate-100 overflow-hidden">
                        
                        <div class="px-8 py-6 bg-slate-50/50 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <div>
                                <h3 class="text-xl font-bold text-slate-800">Versión Tabulador: {{ version.version_name }}</h3>
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
                                    <el-table-column label="Producto Especificado" min-width="200">
                                        <template #default="scope">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                </div>
                                                <span class="font-medium text-slate-700">{{ scope.row.conditions.product_type || 'General / N/A' }}</span>
                                            </div>
                                        </template>
                                    </el-table-column>
                                    <el-table-column label="Comisión Agente (%)" min-width="150" align="center">
                                        <template #default="scope">
                                            <span class="inline-flex items-center justify-center px-4 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 font-bold text-base border border-emerald-100">
                                                {{ scope.row.agent_percentage }}%
                                            </span>
                                        </template>
                                    </el-table-column>
                                    <el-table-column label="Comisión Promotor (%)" min-width="150" align="center">
                                        <template #default="scope">
                                            <span class="inline-flex items-center justify-center px-4 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 font-bold text-base border border-indigo-100">
                                                {{ scope.row.promoter_percentage }}%
                                            </span>
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
                    <h3 class="text-lg font-medium text-slate-900">Sin Tabuladores de Comisión</h3>
                    <p class="mt-1 text-sm text-slate-500">Este esquema aún no tiene versiones ni niveles de comisión establecidos.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>