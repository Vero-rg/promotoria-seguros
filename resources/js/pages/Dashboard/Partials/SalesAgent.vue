<script setup lang="ts">
import { Users } from 'lucide-vue-next';

defineProps<{
    agents?: any[];
}>();
</script>

<template>
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900 flex items-center">
                <Users class="w-5 h-5 mr-2 text-indigo-500" />
                Ventas y Pólizas por Agente
            </h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Agente</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pólizas</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Ventas</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-if="!agents || agents.length === 0">
                        <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">
                            No hay datos disponibles.
                        </td>
                    </tr>
                    <tr v-else v-for="(agent, index) in agents" :key="index">
                        <!-- Aquí iterarás sobre los datos reales -->
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>