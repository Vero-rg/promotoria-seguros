<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

// Recibimos los esquemas desde el controlador
defineProps<{
    schemes: Array<{
        id: number;
        name: string;
        type: string;
        target: string;
        is_active: boolean;
        tiers: any[];
    }>;
}>();
</script>

<template>
    <AppLayout>
        <Head title="Esquemas de Comisiones" />

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-100">
                    <div class="p-6 text-gray-900">
                        <div class="flex flex-col sm:flex-row justify-between items-center mb-6">
                            <h2 class="text-2xl font-semibold mb-4 sm:mb-0">Esquemas de Comisiones</h2>
                            <button class="px-4 py-2 bg-black text-white text-sm font-medium rounded-md hover:bg-gray-800 transition-colors">
                                Nueva Comisión
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="border-b bg-gray-50">
                                        <th class="py-3 px-4 text-sm font-medium text-gray-600">Nombre del Esquema</th>
                                        <th class="py-3 px-4 text-sm font-medium text-gray-600">Dirigido a</th>
                                        <th class="py-3 px-4 text-sm font-medium text-gray-600">Niveles Configurados</th>
                                        <th class="py-3 px-4 text-sm font-medium text-gray-600">Estado</th>
                                        <th class="py-3 px-4 text-sm font-medium text-gray-600 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="scheme in schemes" :key="scheme.id" class="border-b hover:bg-gray-50 transition-colors">
                                        <td class="py-3 px-4 text-sm">{{ scheme.name }}</td>
                                        <td class="py-3 px-4 text-sm capitalize">
                                            {{ scheme.target === 'promoter' ? 'Promotor' : 'Agente' }}
                                        </td>
                                        <td class="py-3 px-4 text-sm text-gray-500">
                                            {{ scheme.tiers?.length || 0 }} bandas
                                        </td>
                                        <td class="py-3 px-4">
                                            <span v-if="scheme.is_active" class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">Activo</span>
                                            <span v-else class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs font-medium">Inactivo</span>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-right">
                                            <button class="text-blue-600 hover:underline mr-3">Editar</button>
                                            <button class="text-red-600 hover:underline">Eliminar</button>
                                        </td>
                                    </tr>
                                    <tr v-if="schemes.length === 0">
                                        <td colspan="5" class="py-8 text-center text-gray-500 text-sm">
                                            No hay esquemas de comisiones registrados.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>