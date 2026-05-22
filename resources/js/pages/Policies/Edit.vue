<script setup>
import { Head } from '@inertiajs/vue3';
import PolicyForm from './Partials/PolicyForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps({
    policy: Object,
    agents: Array,
});

const breadcrumbs = [
    {
        title: 'Pólizas',
        href: route('policies.index'),
    },
    {
        title: `Editar póliza`,
    },
];

</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Editar Póliza ${policy.policy_number}`" />

        <div class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 bg-gray-50/50">
                    <h1 class="text-xl font-semibold text-gray-900">Editar Póliza: {{ policy.policy_number }}</h1>
                </div>

                <PolicyForm :policy="policy" :agents="agents" :isEdit="true" />
            </div>
        </div>
    </AppLayout>    
</template>