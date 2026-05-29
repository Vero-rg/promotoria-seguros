<script setup lang="ts">
defineProps<{ conditions: Record<string, any> }>();
</script>

<template>
    <div class="flex-1 min-w-[140px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">PP Mínima ($)</label>
        <el-input-number v-model="conditions.min_pp" :min="0" :step="0.01" :precision="2" style="width: 100%;" required />
    </div>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">IRP Mínimo (%)</label>
        <el-input-number v-model="conditions.min_irp" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" required />
    </div>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">IRP Máximo (%)</label>
        <el-input-number v-model="conditions.max_irp" :min="0" :max="100" :step="0.01" :precision="2" style="width: 100%;" placeholder="Sin límite" />
    </div>
</template>