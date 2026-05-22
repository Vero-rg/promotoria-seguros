<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Percent, Award } from 'lucide-vue-next';

// Nota: Si usas un componente de Layout (como AppLayout) en tu proyecto, 
// puedes importarlo y envolver el contenido dentro de él.
// import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <AppLayout>
        <Head title="Esquemas" />

        <!-- Si usas un AppLayout, descomenta y envuelve este div con <AppLayout> -->
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                
                <div class="mb-6">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900">
                        Módulo de Esquemas
                    </h2>
                    <p class="text-gray-500 mt-1">
                        Selecciona la configuración de esquemas que deseas administrar.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Botón / Tarjeta: Comisiones -->
                    <Link 
                        :href="route('esquemas.comisiones')" 
                        class="group relative flex items-center p-6 bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md hover:border-indigo-500 transition-all duration-200"
                    >
                        <div class="p-4 bg-indigo-50 text-indigo-600 rounded-lg group-hover:bg-indigo-600 group-hover:text-white transition-colors duration-200">
                            <Percent class="w-8 h-8" />
                        </div>
                        <div class="ml-5">
                            <h3 class="text-xl font-semibold text-gray-900 group-hover:text-indigo-600 transition-colors">
                                Comisiones
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Configura porcentajes, reglas y cálculos de comisiones para ventas.
                            </p>
                        </div>
                    </Link>

                    <!-- Botón / Tarjeta: Bonos -->
                    <Link 
                        :href="route('esquemas.bonos')" 
                        class="group relative flex items-center p-6 bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md hover:emerald-500 transition-all duration-200"
                    >
                        <div class="p-4 bg-emerald-50 text-emerald-600 rounded-lg group-hover:bg-emerald-600 group-hover:text-white transition-colors duration-200">
                            <Award class="w-8 h-8" />
                        </div>
                        <div class="ml-5">
                            <h3 class="text-xl font-semibold text-gray-900 group-hover:text-emerald-600 transition-colors">
                                Bonos
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Administra bonos fijos, metas y recompensas adicionales.
                            </p>
                        </div>
                    </Link>
                </div>

            </div>
        </div>
    </AppLayout>    
</template>