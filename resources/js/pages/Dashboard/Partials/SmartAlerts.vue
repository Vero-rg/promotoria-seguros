<script setup lang="ts">
import { ref, computed } from 'vue';
import { Clock, Target, Users, Bell, CheckCheck, X, Award, AlertTriangle, DollarSign, Package, TrendingUp } from 'lucide-vue-next';

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
    award: Award,
    alert: AlertTriangle,
    dollar: DollarSign,
    package: Package,
    trending: TrendingUp,
};

const typeColors: Record<string, string> = {
    period_close: 'border-l-amber-400 bg-amber-50/60',
    agent_near_bonus: 'border-l-emerald-400 bg-emerald-50/60',
    agent_product_mix: 'border-l-blue-400 bg-blue-50/60',
    agent_low_commission: 'border-l-orange-400 bg-orange-50/60',
    promoter_near_bonus: 'border-l-indigo-400 bg-indigo-50/60',
    promoter_recruits: 'border-l-violet-400 bg-violet-50/60',
    promoter_low_irp: 'border-l-red-400 bg-red-50/60',
    promoter_no_recruits: 'border-l-cyan-400 bg-cyan-50/60',
    promoter_inactive_agents: 'border-l-rose-400 bg-rose-50/60',
    data_quality: 'border-l-gray-400 bg-gray-50/60',
};

const activeAlerts = computed(() =>
    props.alerts.filter((_, idx) => !dismissed.value.has(idx))
);

const hasActiveAlerts = computed(() => activeAlerts.value.length > 0);

const dismissOne = (idx: number) => {
    dismissed.value = new Set([...dismissed.value, idx]);
};

const dismissAll = () => {
    dismissed.value = new Set(props.alerts.map((_, i) => i));
};
</script>

<template>
    <div
        class="rounded-2xl border p-5 transition-all duration-300 shadow-sm"
        :class="hasActiveAlerts
            ? 'bg-amber-50/20 border-amber-200/60'
            : 'bg-white border-gray-100'"
    >
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center space-x-2">
                <div :class="hasActiveAlerts ? 'bg-amber-100' : 'bg-gray-50'" class="p-2 rounded-lg">
                    <Bell class="w-4 h-4" :class="hasActiveAlerts ? 'text-amber-600' : 'text-gray-400'" stroke-width="1.8" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold" :class="hasActiveAlerts ? 'text-amber-800' : 'text-gray-500'">
                        {{ hasActiveAlerts ? `${activeAlerts.length} Oportunidad(es) Detectada(s)` : 'Sin alertas activas' }}
                    </h3>
                    <p v-if="hasActiveAlerts" class="text-xs text-amber-600/70 mt-0.5">Impulsa a tu equipo antes del cierre</p>
                </div>
            </div>
            <button
                v-if="hasActiveAlerts"
                @click="dismissAll"
                class="flex items-center space-x-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors text-gray-500 hover:bg-white/80 hover:text-gray-800 border border-gray-200/60"
            >
                <CheckCheck class="w-3.5 h-3.5" />
                <span>Marcar como leídas</span>
            </button>
        </div>

        <!-- Grid de alertas -->
        <div v-if="alerts && alerts.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-2.5 mt-2">
            <div
                v-for="(alert, idx) in alerts"
                :key="idx"
                v-show="!dismissed.has(idx)"
                class="flex items-start space-x-3 bg-white rounded-xl px-4 py-3 border border-gray-100 shadow-sm group hover:shadow-md transition-shadow border-l-4"
                :class="typeColors[alert.type] || 'border-l-gray-300'"
            >
                <div class="flex-shrink-0 mt-0.5">
                    <component :is="iconMap[alert.icon] || Target" class="w-4 h-4 text-gray-500" stroke-width="1.8" />
                </div>
                <p class="text-sm text-gray-700 leading-relaxed flex-1">{{ alert.message }}</p>
                <button @click="dismissOne(idx)" class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity text-gray-400 hover:text-gray-600 p-1 rounded-md hover:bg-gray-50">
                    <X class="w-3.5 h-3.5" />
                </button>
            </div>
        </div>

        <div v-if="!hasActiveAlerts && alerts && alerts.length > 0" class="text-left text-sm text-gray-400 mt-1">
            Todas las alertas han sido revisadas. ✓
        </div>
        <div v-if="!alerts || alerts.length === 0" class="text-left text-sm text-gray-400 mt-1">
            Todo en orden. No hay elementos que requieran tu atención.
        </div>
    </div>
</template>