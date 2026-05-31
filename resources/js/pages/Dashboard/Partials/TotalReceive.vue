<script setup lang="ts">
import { TrendingUp } from 'lucide-vue-next';

defineProps<{
    amount?: number;
}>();
</script>

<template>
    <div class="bg-gradient-to-br from-emerald-50/50 to-white overflow-hidden rounded-3xl shadow-[0_4px_20px_-4px_rgba(16,185,129,0.1)] border border-emerald-100/50 p-6">
        <div class="flex items-center">
            <div class="p-3.5 rounded-2xl bg-emerald-500/10 text-emerald-600 mr-4 shadow-sm">
                <TrendingUp class="w-6 h-6" />
            </div>
            <div>
                <p class="text-xs font-semibold text-emerald-600/80 uppercase tracking-wider truncate">Monto Total a Recibir (Mes)</p>
                <p class="text-2xl font-bold text-gray-800 tracking-tight">
                    ${{ amount?.toFixed(2) ?? '0.00' }}
                </p>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-400/80 font-medium">
            * Deduciendo bonos, comisiones y retenciones.
        </p>
    </div>
</template>