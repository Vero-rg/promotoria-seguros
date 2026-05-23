<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TotalVencido from './Partials/TotalDue.vue';
import VentasPorAgente from './Partials/SalesAgent.vue';
import TotalComisiones from './Partials/TotalComissions.vue';
import TotalRecibir from './Partials/TotalReceive.vue';
import AppLayout from '@/layouts/AppLayout.vue';

// Aquí puedes recibir los props desde el controlador
// defineProps<{ ... }>();
</script>

<template>
    <AppLayout>
        <Head title="Dashboard" />

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                
                <!-- KPIs Superiores -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <TotalVencido :amount="0" />
                    <TotalComisiones :amount="0" />
                    <TotalRecibir :amount="0" />
                </div>

                <!-- Gráficos o Tablas -->
                <div class="grid grid-cols-1 gap-6">
                    <VentasPorAgente :agents="[]" />
                </div>

            </div>
        </div>
    </AppLayout>    
</template>