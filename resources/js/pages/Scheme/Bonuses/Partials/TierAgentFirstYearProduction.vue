<script setup lang="ts">
defineProps<{ conditions: Record<string, any> }>();
</script>

<template>
    <div class="flex-1 min-w-[150px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Desde (Mín. PCA $)</label>
        <el-input 
        v-model="conditions.min_pca" :min="0" :step="1000" style="width: 100%;" required
        :formatter="(value) => `$ ${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')"
        :parser="(value) => value.replace(/\$\s?|(,*)/g, '')" />
    </div>
</template>