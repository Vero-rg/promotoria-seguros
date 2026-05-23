<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Percent, Award, Plus, ArrowRight } from 'lucide-vue-next';

// Recibimos los esquemas (que desde el controlador serán solo los bonos)
defineProps<{
    schemes: Array<{
        id: number;
        name: string;
        code: string;
        type: string;
        target: string;
        is_active: boolean;
        tiers: any[];
    }>;
}>();
</script>

<template>
    <AppLayout>
        <Head title="Esquemas de Bonos" />

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

                    <!-- Botón de Crear (Apunta a bonos) -->
                    <Link href="/esquemas/bonos/crear" class="inline-flex items-center justify-center px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-md hover:bg-emerald-700 transition-colors">
                        <Plus class="w-4 h-4 mr-2" />
                        Nuevo Bono
                    </Link>
                </div>

                <!-- Tabs (Pestañas) -->
                <div class="border-b border-gray-200 mb-6">
                    <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                        <!-- Tab Comisiones (Inactivo) -->
                        <Link 
                            href="/esquemas" 
                            class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 group inline-flex items-center border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                        >
                            <Percent class="text-gray-400 group-hover:text-gray-500 -ml-0.5 mr-2 h-5 w-5 transition-colors" />
                            <span>Comisiones</span>
                        </Link>

                        <!-- Tab Bonos (Activo) -->
                        <Link 
                            href="/esquemas/bonos" 
                            class="border-emerald-500 text-emerald-600 group inline-flex items-center border-b-2 py-4 px-1 text-sm font-medium"
                            aria-current="page"
                        >
                            <Award class="text-emerald-500 -ml-0.5 mr-2 h-5 w-5" />
                            <span>Bonos</span>
                        </Link>
                    </nav>
                </div>

                <!-- Grid de Tarjetas de Bonos -->
                <div v-if="schemes.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div 
                        v-for="scheme in schemes" 
                        :key="scheme.id"
                        class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md hover:border-emerald-300 transition-all duration-200 overflow-hidden flex flex-col"
                    >
                        <!-- Card Header -->
                        <div class="p-5 border-b border-gray-100 flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 leading-tight">
                                    {{ scheme.name }}
                                </h3>
                                <p class="text-xs text-gray-500 font-mono mt-1">{{ scheme.code }}</p>
                            </div>
                            <span 
                                :class="scheme.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                            >
                                {{ scheme.is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </div>

                        <!-- Card Body -->
                        <div class="p-5 flex-1">
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
                            </div>
                        </div>

                        <!-- Card Footer / Actions -->
                        <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex justify-between items-center">
                            <div class="flex space-x-3">
                                <button class="text-sm font-medium text-blue-600 hover:text-blue-800 transition-colors">Editar</button>
                                <button class="text-sm font-medium text-red-600 hover:text-red-800 transition-colors">Eliminar</button>
                            </div>
                            <!-- Apuntaremos a la vista show de bonos cuando la crees -->
                            <Link :href="`/esquemas/bonos/${scheme.id}`" class="inline-flex items-center text-sm font-medium text-emerald-600 hover:text-emerald-700 transition-colors">
                                Ver Detalles
                                <ArrowRight class="ml-1 w-4 h-4" />
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Estado Vacío -->
                <div v-else class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
                    <Award class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold text-gray-900">Sin bonos</h3>
                    <p class="mt-1 text-sm text-gray-500">No has registrado ningún esquema de bonos aún.</p>
                    <div class="mt-6">
                        <Link href="/esquemas/bonos/crear" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-md hover:bg-emerald-700 transition-colors">
                            <Plus class="w-4 h-4 mr-2" />
                            Crear mi primer bono
                        </Link>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>    
</template>