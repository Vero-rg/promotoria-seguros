<script setup lang="ts">
defineProps<{ conditions: Record<string, any> }>();
</script>

<template>
    <div class="flex-1 min-w-[200px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Producto</label>
        <el-input v-model="conditions.product_type" placeholder="Ej. METLIFE" required />
    </div>
</template>