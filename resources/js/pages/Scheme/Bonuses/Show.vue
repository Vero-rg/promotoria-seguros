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
</script>

<template>
    <AppLayout>
        <Head :title="`Detalles - ${scheme.name}`" />

        <div class="py-12">
            <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div class="flex justify-between items-center">
                    <h2 class="text-2xl font-semibold">Detalles del Bono</h2>
                    <Link href="/esquemas/bonos" class="text-gray-500 hover:text-gray-700 text-sm">
                        &larr; Volver
                    </Link>
                </div>

                <!-- Tarjeta de Info General -->
                <div class="bg-white shadow-sm sm:rounded-lg border border-gray-100 p-6">
                    <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">Información General</h3>
                    <el-descriptions border :column="2">
                        <el-descriptions-item label="Nombre">{{ scheme.name }}</el-descriptions-item>
                        <el-descriptions-item label="Código">{{ scheme.code }}</el-descriptions-item>
                        <el-descriptions-item label="Tipo">
                            <el-tag :type="scheme.type === 'commission' ? 'success' : 'warning'">
                                {{ scheme.type === 'commission' ? 'Comisión' : 'Bono' }}
                            </el-tag>
                        </el-descriptions-item>
                        <el-descriptions-item label="Dirigido a">
                            {{ scheme.target === 'both' ? 'Agente y Promotor' : (scheme.target === 'agent' ? 'Agente' : 'Promotor') }}
                        </el-descriptions-item>
                        <el-descriptions-item label="Estado">
                            <el-tag :type="scheme.is_active ? 'success' : 'danger'">
                                {{ scheme.is_active ? 'Activo' : 'Inactivo' }}
                            </el-tag>
                        </el-descriptions-item>
                    </el-descriptions>
                </div>

                <!-- Tarjeta de Versiones y Porcentajes -->
                <div v-for="version in scheme.versions" :key="version.id" class="bg-white shadow-sm sm:rounded-lg border border-gray-100 p-6">
                    <div class="flex justify-between items-center border-b pb-2 mb-4">
                        <h3 class="text-lg font-medium text-gray-900">
                            Versión: {{ version.version_name }}
                        </h3>
                        <div class="text-sm text-gray-500">
                            Vigencia: {{ version.starts_at }} a {{ version.ends_at ? version.ends_at : 'Sin límite' }}
                        </div>
                    </div>

                    <el-table :data="version.tiers" border style="width: 100%">
                        <el-table-column label="Condiciones Dinámicas (JSON)" min-width="300">
                            <template #default="scope">
                                <template v-if="scope.row.conditions">
                                     <div v-for="(value, key) in scope.row.conditions" :key="key" class="text-sm mb-1">
                                        <span class="font-semibold text-gray-700 capitalize">
                                             {{ key.replace(/_/g, ' ') }}:
                                         </span> 
                                         <span class="text-gray-600">
                                             {{ value !== null && value !== undefined ? value : 'Sin límite' }}
                                         </span>
                                     </div>
                                 </template>
                            </template>
                        </el-table-column>
                        <el-table-column label="Bono Agente (%)" min-width="150">
                             <template #default="scope">{{ scope.row.agent_percentage }}%</template>
                         </el-table-column>
                         <el-table-column label="Bono Promotor (%)" min-width="150">
                             <template #default="scope">{{ scope.row.promoter_percentage }}%</template>
                         </el-table-column>
                     </el-table>
                </div>
                
                <div v-if="!scheme.versions || scheme.versions.length === 0" class="bg-white shadow-sm sm:rounded-lg border border-gray-100 p-6 text-center text-gray-500">
                    No hay versiones configuradas para este esquema.
                </div>
            </div>
        </div>
    </AppLayout>
</template>