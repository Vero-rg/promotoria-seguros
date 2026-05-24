<script setup lang="ts">
defineProps<{ conditions: Record<string, any> }>();
</script>

<template>
    <div class="flex-1 min-w-[150px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Clasificación</label>
        <el-input v-model="conditions.classification" placeholder="Ej. Activo 12" required />
    </div>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Mín. Pólizas</label>
        <el-input-number v-model="conditions.min_policies" :min="0" :step="0.01" style="width: 100%;" required />
    </div>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Máx. Pólizas</label>
        <el-input-number v-model="conditions.max_policies" :min="0" :step="0.01" style="width: 100%;" placeholder="Sin límite" />
    </div>
</template>