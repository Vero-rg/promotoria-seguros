<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="width: 24px; height: 24px;">
  <polyline points="3 4 3 20 21 20" />
  <rect x="6" y="15" width="2" height="5" />
  <rect x="11" y="10" width="2" height="10" />
  <rect x="16" y="5" width="2" height="15" />
</svg>

</template>
