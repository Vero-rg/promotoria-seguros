<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <div class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
        <AppLogoIcon class="size-5 fill-current text-white dark:text-black" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate font-semibold leading-none">Gestor de Pólizas</span>
    </div>
    <!-- Icono de gráfica minimalista (stroke) -->
    <svg class="ml-2 size-5 text-sidebar-primary-foreground/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="3 20 9 13 13 16 21 6" />
        <polyline points="17 6 21 6 21 10" />
        <line x1="21" y1="20" x2="3" y2="20" />
    </svg>
</template>
