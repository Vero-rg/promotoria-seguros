    <script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Percent, Award, Plus, ArrowRight, Edit, Trash2, AlertCircle } from 'lucide-vue-next';
import { ElMessage, ElMessageBox } from 'element-plus';
import { onMounted } from 'vue';

// Recibimos los esquemas (que desde el controlador serán solo las comisiones)
defineProps<{
    schemes: Array<{
        id: number;
        name: string;
        type: string;
        target: string;
        is_active: boolean;
        tiers: any[];
        versions: Array<{ id: number; version_name: string; starts_at: string; ends_at: string | null }>;
    }>;
}>();

onMounted(() => {
    const flash = (usePage().props.flash as any) || {};
    if (flash.success) {
        ElMessage({ type: 'success', message: flash.success });
    }
    if (flash.error) {
        ElMessage({ type: 'error', message: flash.error });
    }
});

const handleDelete = (schemeId: number, schemeName: string) => {
    ElMessageBox.confirm(
        `¿Estás seguro de eliminar "${schemeName}"? Esta acción no se puede deshacer.`,
        'Confirmar eliminación',
        { confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', type: 'warning' }
    ).then(() => {
        router.delete(`/schemes/${schemeId}`, {
            onSuccess: () => ElMessage({ type: 'success', message: 'Esquema eliminado correctamente.' }),
            onError: () => ElMessage({ type: 'error', message: 'Error al eliminar el esquema.' }),
        });
    }).catch(() => {});
};
</script>

<template>
    <AppLayout>
        <Head title="Esquemas de Comisiones" />

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                
                <!-- Header y Navegación de Pestañas -->
                <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold tracking-tight text-gray-900">
                            Módulo de Esquemas
                        </h2>
                        <p class="text-gray-500 mt-1 text-sm">
                            Gestiona las reglas de cálculo para tu promotoría.
                        </p>
                    </div>

                    <!-- Botón de Crear (Apunta a la nueva estructura sugerida) -->
                    <Link href="/esquemas/comisiones/crear" class="inline-flex items-center justify-center px-4 py-2 bg-black text-white text-sm font-medium rounded-md hover:bg-gray-800 transition-colors">
                        <Plus class="w-4 h-4 mr-2" />
                        Nueva Comisión
                    </Link>
                </div>

                <!-- Tabs (Pestañas) -->
                <div class="border-b border-gray-200 mb-6">
                    <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                        <!-- Tab Comisiones (Activo) -->
                        <Link 
                            href="/esquemas" 
                            class="border-black text-black group inline-flex items-center border-b-2 py-4 px-1 text-sm font-medium"
                            aria-current="page"
                        >
                            <Percent class="text-black -ml-0.5 mr-2 h-5 w-5" />
                            <span>Comisiones</span>
                        </Link>

                        <!-- Tab Bonos (Inactivo) -->
                        <Link 
                            href="/esquemas/bonos" 
                            class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 group inline-flex items-center border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                        >
                            <Award class="text-gray-400 group-hover:text-gray-500 -ml-0.5 mr-2 h-5 w-5 transition-colors" />
                            <span>Bonos</span>
                        </Link>
                    </nav>
                </div>

                <!-- Aviso: solo un esquema de comisiones activo -->
                <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-lg flex items-start gap-3">
                    <AlertCircle class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                    <div>
                        <p class="text-sm font-medium text-amber-800">Solo puede haber un esquema de comisiones activo a la vez.</p>
                        <p class="text-xs text-amber-700 mt-1">
                            Puedes crear varios esquemas, pero únicamente el que esté <strong>activo</strong> aparecerá como opción al registrar pólizas. 
                            Al activar uno nuevo, los demás se desactivarán automáticamente.
                        </p>
                    </div>
                </div>

                <!-- Grid de Tarjetas de Comisiones -->
                <div v-if="schemes.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div 
                        v-for="scheme in schemes" 
                        :key="scheme.id"
                        class="bg-white/80 backdrop-blur-xl rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 overflow-hidden flex flex-col"
                    >
                        <!-- Card Header -->
                        <div class="p-6 border-b border-gray-50 flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 leading-tight">
                                    {{ scheme.name }}
                                </h3>
                                <!-- <p class="text-xs text-gray-500 font-mono mt-1">{{ scheme.code }}</p> -->
                            </div>
                            <span 
                                :class="scheme.is_active ? 'bg-green-50 text-green-700 ring-1 ring-green-600/20' : 'bg-red-50 text-red-700 ring-1 ring-red-600/20'"
                                class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold tracking-wide"
                            >
                                {{ scheme.is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </div>

                        <!-- Card Body -->
                        <div class="p-6 flex-1">
                            <div class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Dirigido a:</span>
                                    <span class="font-medium text-gray-900 capitalize">
                                        {{ scheme.target === 'both' ? 'Agente y Promotor' : (scheme.target === 'agent' ? 'Agente' : 'Promotor') }}
                                    </span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Niveles configurados:</span>
                                    <span class="font-medium text-gray-900">{{ scheme.tiers?.length || 0 }}</span>
                                </div>
                                <div class="flex justify-between text-sm" v-if="scheme.versions?.length">
                                    <span class="text-gray-500">Versión:</span>
                                    <span class="font-medium text-gray-900">{{ scheme.versions[scheme.versions.length - 1]?.version_name || '—' }}</span>
                                </div>
                                <div class="flex justify-between text-sm" v-if="scheme.versions?.length">
                                    <span class="text-gray-500">Vigencia:</span>
                                    <span class="font-medium text-gray-900 text-xs text-right">
                                        {{ scheme.versions[scheme.versions.length - 1]?.starts_at }}
                                        <template v-if="scheme.versions[scheme.versions.length - 1]?.ends_at"> → {{ scheme.versions[scheme.versions.length - 1]?.ends_at }}</template>
                                        <template v-else> → Indefinido</template>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer / Actions -->
                        <div class="px-6 py-4 bg-gray-50/50 border-t border-gray-50 flex justify-between items-center">
                             <div class="flex space-x-2">
                                 <Link :href="`/esquemas/comisiones/${scheme.id}/editar`" class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-all duration-200" title="Editar">
                                     <Edit class="w-4 h-4" />
                                 </Link>
                                 <button @click="handleDelete(scheme.id, scheme.name)" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all duration-200" title="Eliminar">
                                     <Trash2 class="w-4 h-4" />
                                 </button>
                             </div>
                            <!-- Apunta a la nueva estructura sugerida -->
                            <Link :href="`/esquemas/comisiones/${scheme.id}`" class="inline-flex items-center text-sm font-medium text-black hover:text-gray-600 transition-colors">
                                Ver Detalles
                                <ArrowRight class="ml-1 w-4 h-4" />
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Estado Vacío -->
                <div v-else class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
                    <Percent class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold text-gray-900">Sin comisiones</h3>
                    <p class="mt-1 text-sm text-gray-500">No has registrado ningún esquema de comisiones aún.</p>
                    <div class="mt-6">
                        <Link href="/esquemas/comisiones/crear" class="inline-flex items-center px-4 py-2 bg-black text-white text-sm font-medium rounded-md hover:bg-gray-800 transition-colors">
                            <Plus class="w-4 h-4 mr-2" />
                            Crear mi primera comisión
                        </Link>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>    
</template>