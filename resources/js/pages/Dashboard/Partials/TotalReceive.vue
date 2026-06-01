<script setup lang="ts">
import { TrendingUp } from 'lucide-vue-next';

defineProps<{
    amount?: number;
}>();
</script>

<template>
    <div class="bg-white rounded-xl shadow-[0_1px_2px_rgba(0,0,0,0.02)] border border-gray-200/60 p-5 flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <p class="text-[13px] font-medium text-gray-500">Total a Recibir (Mes)</p>
            <div class="p-1.5 rounded-md border border-gray-100 bg-gray-50 flex items-center justify-center">
                <TrendingUp class="w-[15px] h-[15px] text-gray-500" stroke-width="2" />
            </div>
        </div>
        <div>
            <p class="text-[22px] font-semibold text-gray-900 tracking-tight">
                ${{ amount?.toFixed(2) ?? '0.00' }}
            </p>
            <p class="mt-1 text-[11px] text-gray-400 font-medium">
                Deduciendo bonos, comisiones y retenciones.
            </p>
        </div>
    </div>
</template>