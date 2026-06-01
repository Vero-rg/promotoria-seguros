<script setup lang="ts">
defineProps<{
    data: number[];
    width?: number;
    height?: number;
    color?: string;
}>();
</script>

<template>
    <svg :width="width || 70" :height="height || 24" class="flex-shrink-0">
        <polyline
            v-if="data && data.length > 0"
            :points="(() => {
                const w = width || 70;
                const h = height || 24;
                const max = Math.max(...data, 1);
                const pad = 2;
                return data.map((v, i) => {
                    const x = pad + (i / Math.max(data.length - 1, 1)) * (w - pad * 2);
                    const y = h - pad - (v / max) * (h - pad * 2);
                    return `${x},${y}`;
                }).join(' ');
            })()"
            :stroke="color || '#111827'"
            stroke-width="1.5"
            fill="none"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="opacity-80"
        />
        <line v-if="!data || data.length === 0" x1="4" y1="12" x2="66" y2="12" stroke="#f3f4f6" stroke-width="1.5" stroke-linecap="round" />
    </svg>
</template>