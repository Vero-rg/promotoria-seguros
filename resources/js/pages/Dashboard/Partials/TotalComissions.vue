<script setup lang="ts">
import { DollarSign } from 'lucide-vue-next';

defineProps<{
    amount?: number;
}>();
</script>

<template>
    <div class="bg-gradient-to-br from-amber-50/50 to-white overflow-hidden rounded-3xl shadow-[0_4px_20px_-4px_rgba(245,158,11,0.1)] border border-amber-100/50 p-6">
        <div class="flex items-center">
            <div class="p-3.5 rounded-2xl bg-amber-500/10 text-amber-600 mr-4 shadow-sm">
                <DollarSign class="w-6 h-6" />
            </div>
            <div>
                <p class="text-xs font-semibold text-amber-600/80 uppercase tracking-wider truncate">Total a Pagar (Comisiones)</p>
                <p class="text-2xl font-bold text-gray-800 tracking-tight">
                    ${{ amount?.toFixed(2) ?? '0.00' }}
                </p>
            </div>
        </div>
    </div>
</template>