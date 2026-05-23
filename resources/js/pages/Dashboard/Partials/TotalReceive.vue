<script setup lang="ts">
import { TrendingUp } from 'lucide-vue-next';

defineProps<{
    amount?: number;
}>();
</script>

<template>
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 border-l-4 border-emerald-500">
        <div class="flex items-center">
            <div class="p-3 rounded-full bg-emerald-100 text-emerald-600 mr-4">
                <TrendingUp class="w-6 h-6" />
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 truncate">Monto Total a Recibir (Mes)</p>
                <p class="text-2xl font-semibold text-gray-900">
                    ${{ amount?.toFixed(2) ?? '0.00' }}
                </p>
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-400">
            * Deduciendo bonos, comisiones y retenciones.
        </p>
    </div>
</template>