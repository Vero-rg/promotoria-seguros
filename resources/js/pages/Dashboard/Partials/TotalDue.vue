<script setup lang="ts">
import { AlertCircle } from 'lucide-vue-next';

defineProps<{
    amount?: number;
}>();
</script>

<template>
    <div class="bg-gradient-to-br from-red-50/50 to-white overflow-hidden rounded-3xl shadow-[0_4px_20px_-4px_rgba(239,68,68,0.1)] border border-red-100/50 p-6">
        <div class="flex items-center">
            <div class="p-3.5 rounded-2xl bg-red-500/10 text-red-600 mr-4 shadow-sm">
                <AlertCircle class="w-6 h-6" />
            </div>
            <div>
                <p class="text-xs font-semibold text-red-600/80 uppercase tracking-wider truncate">Total Vencido</p>
                <p class="text-2xl font-bold text-gray-800 tracking-tight">
                    ${{ amount?.toFixed(2) ?? '0.00' }}
                </p>
            </div>
        </div>
    </div>
</template>