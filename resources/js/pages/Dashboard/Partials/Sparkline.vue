<script setup lang="ts">
defineProps<{
    data: number[];
    width?: number;
    height?: number;
    color?: string;
}>();
</script>

<template>
    <svg :width="width || 80" :height="height || 24" class="flex-shrink-0">
        <polyline
            v-if="data && data.length > 0"
            :points="(() => {
                const w = width || 80;
                const h = height || 24;
                const max = Math.max(...data, 1);
                const pad = 2;
                return data.map((v, i) => {
                    const x = pad + (i / Math.max(data.length - 1, 1)) * (w - pad * 2);
                    const y = h - pad - (v / max) * (h - pad * 2);
                    return `${x},${y}`;
                }).join(' ');
            })()"
            :stroke="color || '#4a7c59'"
            stroke-width="1.5"
            fill="none"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <line v-if="!data || data.length === 0" x1="4" y1="12" x2="76" y2="12" stroke="#e5e7eb" stroke-width="1" />
    </svg>
</template>
