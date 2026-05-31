<script setup lang="ts">
import { ref } from 'vue';
import { Clock, Target, Users, Bell, CheckCheck, X } from 'lucide-vue-next';

const props = defineProps<{
    alerts: Array<{
        type: string;
        message: string;
        icon: string;
    }>;
}>();

const dismissed = ref<Set<number>>(new Set());

const iconMap: Record<string, any> = {
    clock: Clock,
    target: Target,
    users: Users,
};

const hasActiveAlerts = () => {
    return props.alerts.some((_, idx) => !dismissed.value.has(idx));
};

const dismissOne = (idx: number) => {
    dismissed.value = new Set([...dismissed.value, idx]);
};

const dismissAll = () => {
    dismissed.value = new Set(props.alerts.map((_, i) => i));
};
</script>

<template>
    <div
        class="rounded-2xl border p-5 transition-all duration-300"
        :class="hasActiveAlerts()
            ? 'bg-[#fef9f0] border-amber-300 shadow-sm'
            : 'bg-gray-50/50 border-gray-200'"
    >
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center space-x-2">
                <div class="w-2 h-2 rounded-full" :class="hasActiveAlerts() ? 'bg-amber-500 animate-pulse' : 'bg-gray-300'" />
                <Bell class="w-4 h-4" :class="hasActiveAlerts() ? 'text-amber-600' : 'text-gray-400'" />
                <h3 class="text-xs font-semibold uppercase tracking-wider" :class="hasActiveAlerts() ? 'text-amber-800' : 'text-gray-400'">
                    {{ hasActiveAlerts() ? 'Oportunidades Detectadas' : 'Sin alertas activas' }}
                </h3>
            </div>
            <button
                v-if="hasActiveAlerts()"
                @click="dismissAll"
                class="flex items-center space-x-1 px-2.5 py-1 text-xs font-medium rounded-lg transition-colors bg-white/80 text-gray-600 hover:bg-white hover:text-gray-900 border border-gray-200"
            >
                <CheckCheck class="w-3.5 h-3.5" />
                <span>Marcar todas como leídas</span>
            </button>
        </div>

        <div v-if="alerts && alerts.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <div
                v-for="(alert, idx) in alerts"
                :key="idx"
                v-show="!dismissed.has(idx)"
                class="flex items-start space-x-3 bg-white/80 rounded-xl px-4 py-3 border border-amber-100 hover:bg-white transition-colors group"
            >
                <div class="flex-shrink-0 mt-0.5">
                    <component :is="iconMap[alert.icon] || Target" class="w-4 h-4 text-amber-600" />
                </div>
                <p class="text-sm text-gray-700 leading-relaxed flex-1">{{ alert.message }}</p>
                <button @click="dismissOne(idx)" class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity text-gray-400 hover:text-gray-600">
                    <X class="w-4 h-4" />
                </button>
            </div>
        </div>

        <div v-if="!hasActiveAlerts() && alerts && alerts.length > 0" class="text-center text-sm text-gray-400 py-2">
            Todas las alertas han sido leídas ✓
        </div>
        <div v-if="!alerts || alerts.length === 0" class="text-center text-sm text-gray-400 py-2">
            Todo en orden. No hay alertas por ahora.
        </div>
    </div>
</template>
