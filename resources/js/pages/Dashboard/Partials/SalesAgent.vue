<script setup lang="ts">
import { Users } from 'lucide-vue-next';

defineProps<{
    agents?: any[];
}>();
</script>

<template>
    <div class="bg-white rounded-xl shadow-[0_1px_2px_rgba(0,0,0,0.02)] border border-gray-200/60 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-[13px] font-medium text-gray-900 flex items-center">
                <Users class="w-4 h-4 mr-2 text-gray-400" />
                Ventas y Pólizas por Agente
            </h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-[13px]">
                <thead class="bg-gray-50/50">
                    <tr>
                        <th scope="col" class="px-5 py-3 text-left font-medium text-gray-500">Agente</th>
                        <th scope="col" class="px-5 py-3 text-left font-medium text-gray-500">Pólizas</th>
                        <th scope="col" class="px-5 py-3 text-left font-medium text-gray-500">Total Ventas</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    <tr v-if="!agents || agents.length === 0">
                        <td colspan="3" class="px-5 py-8 text-center text-gray-400">
                            No hay datos disponibles.
                        </td>
                    </tr>
                    <tr v-else v-for="(agent, index) in agents" :key="index" class="hover:bg-gray-50/50 transition-colors">
                        <!-- Aquí iterarás sobre los datos reales -->
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>