<script setup>
import { Head } from '@inertiajs/vue3';
import PolicyForm from './Partials/PolicyForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps({
    agents: Array,
});

const breadcrumbs = [
    {
        title: 'Pólizas',
        href: route('policies.index'),
    },
    {
        title: `Crear póliza`,
    },
];

</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Nueva Póliza" />

        <div class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 bg-gray-50/50">
                    <h1 class="text-xl font-semibold text-gray-900">Registrar Nueva Póliza</h1>
                    <p class="text-sm text-gray-500 mt-1">Ingresa los detalles de la póliza y comisiones.</p>
                </div>

                <PolicyForm :agents="agents" :isEdit="false" />
            </div>
        </div>
    </AppLayout>    
</template>