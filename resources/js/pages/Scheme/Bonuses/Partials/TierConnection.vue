<script setup lang="ts">
defineProps<{ conditions: Record<string, any> }>();
</script>

<template>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Mín. Reclutas</label>
        <el-input-number v-model="conditions.min_recruits" :min="0" :step="1" style="width: 100%;" required />
    </div>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Máx. Reclutas</label>
        <el-input-number v-model="conditions.max_recruits" :min="0" :step="1" style="width: 100%;" placeholder="Sin límite" />
    </div>
    <div class="flex-1 min-w-[140px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">PCA Acumulada ($)</label>
        <el-input-number v-model="conditions.min_pca" :min="0" :step="1000" style="width: 100%;" required />
    </div>
</template>