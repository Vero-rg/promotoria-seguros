<script setup lang="ts">
import { DollarSign } from 'lucide-vue-next';

defineProps<{
    amount?: number;
}>();
</script>

<template>
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 border-l-4 border-amber-500">
        <div class="flex items-center">
            <div class="p-3 rounded-full bg-amber-100 text-amber-600 mr-4">
                <DollarSign class="w-6 h-6" />
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 truncate">Total a Pagar (Comisiones)</p>
                <p class="text-2xl font-semibold text-gray-900">
                    ${{ amount?.toFixed(2) ?? '0.00' }}
                </p>
            </div>
        </div>
    </div>
</template>