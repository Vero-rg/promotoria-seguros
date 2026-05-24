<script setup lang="ts">
defineProps<{ conditions: Record<string, any> }>();
</script>

<template>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Mín. Agentes</label>
        <el-input-number v-model="conditions.min_agents" :min="0" :step="1" style="width: 100%;" required />
    </div>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Máx. Agentes</label>
        <el-input-number v-model="conditions.max_agents" :min="0" :step="1" style="width: 100%;" placeholder="Sin límite" />
    </div>
</template>