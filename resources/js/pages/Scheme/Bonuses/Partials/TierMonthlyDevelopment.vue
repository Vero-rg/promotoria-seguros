<script setup lang="ts">
defineProps<{ conditions: Record<string, any> }>();
</script>

<template>
    <div class="flex-1 min-w-[140px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">PCA Mínima Acum. ($)</label>
        <el-input 
        v-model="conditions.min_pca" :min="0" :step="1000" style="width: 100%;" required 
         :formatter="(value) => `$ ${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')"
         :parser="(value) => value.replace(/\$\s?|(,*)/g, '')"   
        />
    </div>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Mes Inicial</label>
        <el-input-number v-model="conditions.min_month" :min="1" :step="1" style="width: 100%;" required />
    </div>
    <div class="flex-1 min-w-[120px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Mes Final</label>
        <el-input-number v-model="conditions.max_month" :min="1" :step="1" style="width: 100%;" placeholder="Sin límite" />
    </div>
</template>