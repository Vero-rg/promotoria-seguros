<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Plus, FileText, Calendar, DollarSign, Activity } from 'lucide-vue-next';

defineProps({
    policies: Array,
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount);
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-MX');
};

const statusColors = {
    activa: 'bg-green-100 text-green-800',
    cancelada: 'bg-red-100 text-red-800',
    pagada: 'bg-blue-100 text-blue-800',
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Pólizas" />

        <div class="max-w-7xl mx-200px p-4 sm:p-6 lg:p-8">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-semibold text-gray-900 tracking-tight flex items-center">
                    <FileText class="w-6 h-6 mr-2 text-gray-500" />
                    Pólizas
                </h1>
                <Link :href="route('policies.create')" class="inline-flex items-center px-4 py-2 bg-black text-white text-sm font-medium rounded-lg hover:bg-gray-800 transition-colors">
                    <Plus class="w-4 h-4 mr-2" />
                    Nueva Póliza
                </Link>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50/50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No. Póliza</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Agente</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Emisión</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prima</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estatus</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="policy in policies" :key="policy.id" class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    {{ policy.policy_number }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ policy.agent?.name || 'N/A' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ formatDate(policy.issue_date) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                                    {{ formatCurrency(policy.premium_amount) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full capitalize" :class="statusColors[policy.status?.toLowerCase()] || 'bg-gray-100 text-gray-800'">
                                        {{ policy.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <Link :href="route('policies.show', policy.id)" class="text-blue-600 hover:text-blue-900 mr-3">Ver</Link>
                                    <Link :href="route('policies.edit', policy.id)" class="text-indigo-600 hover:text-indigo-900">Editar</Link>
                                </td>
                            </tr>
                            <tr v-if="!policies.length">
                                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">
                                    No hay pólizas registradas.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>    
</template>