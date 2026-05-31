<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { DollarSign, FileText, Award, Users, LayoutDashboard } from 'lucide-vue-next';
import GlobalKpi from './Partials/GlobalKpi.vue';
import SmartAlerts from './Partials/SmartAlerts.vue';
import TopPerformers from './Partials/TopPerformers.vue';
import TrendsChart from './Partials/TrendsChart.vue';

const props = defineProps<{
    filter?: string;
    start_date?: string;
    end_date?: string;
    kpis?: {
        net_income: number;
        prev_net_income: number;
        income_growth: number;
        policies_by_product: Record<string, number>;
        prev_policies_by_product: Record<string, number>;
        total_policies: number;
        prev_total_policies: number;
        policies_growth: number;
        premium_by_product: Record<string, number>;
        prev_premium_by_product: Record<string, number>;
        projected_bonuses: number;
        projected_agent_bonuses: number;
        projected_promoter_bonuses: number;
        prev_projected_bonuses: number;
        bonuses_growth: number;
        active_promoters: number;
        active_agents: number;
    };
    alerts?: Array<{ type: string; message: string; icon: string }>;
    top_agents?: Array<{
        id: number;
        name: string;
        photo: string | null;
        policies_count: number;
        total_volume: number;
        sparkline: number[];
    }>;
    top_promoters?: Array<{
        id: number;
        name: string;
        photo: string | null;
        team_volume: number;
        bonuses_secured: number;
        bonuses_total: number;
    }>;
    trends?: Array<{ date: string; count: number; label: string }>;
}>();

const activeFilter = ref(props.filter || 'month');
const showCustomRange = ref(props.filter === 'custom');
const customRange = ref([props.start_date || '', props.end_date || '']);

const fetchDashboard = () => {
    router.get(route('dashboard'), {
        filter: activeFilter.value,
        start_date: customRange.value[0] || undefined,
        end_date: customRange.value[1] || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const setFilter = (f: string) => {
    activeFilter.value = f;
    showCustomRange.value = f === 'custom';
    if (f !== 'custom') fetchDashboard();
};

const onCustomRangeChange = () => {
    if (customRange.value[0] && customRange.value[1]) {
        fetchDashboard();
    }
};

const formatCurrency = (v: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v);

const formatNumber = (v: number) =>
    new Intl.NumberFormat('es-MX').format(v);
</script>

<template>
    <AppLayout>
        <Head title="Dashboard" />

        <div class="p-4 sm:p-6 lg:p-8 max-w-full space-y-6">

            <!-- ========== ENCABEZADO + FILTROS ========== -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center space-x-3">
                    <div class="p-2.5 bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl shadow-md shadow-gray-900/20">
                        <LayoutDashboard class="w-5 h-5 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Dashboard</h1>
                        <p class="text-xs text-gray-400 mt-0.5">Resumen de operaciones y rendimiento</p>
                    </div>
                </div>

                <div class="flex items-center space-x-1 bg-gray-100/80 backdrop-blur-md rounded-2xl p-1 shadow-inner">
                    <button
                        v-for="f in [
                            { key: 'today', label: 'Hoy' },
                            { key: 'month', label: 'Mes' },
                            { key: 'year', label: 'Año' },
                            { key: 'custom', label: 'Personalizado' },
                        ]"
                        :key="f.key"
                        @click="setFilter(f.key)"
                        class="px-4 py-2 text-xs font-semibold rounded-xl transition-all duration-300 ease-out"
                       :class="activeFilter === f.key ? 'bg-white text-gray-900 shadow-[0_2px_8px_-2px_rgba(0,0,0,0.08)]' : 'text-gray-500 hover:text-gray-800'"
                    >{{ f.label }}</button>
                </div>
            </div>

            <div v-if="showCustomRange" class="flex items-center space-x-3 bg-white/80 backdrop-blur-xl rounded-2xl border border-white/50 p-3 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)]">
                <span class="text-sm text-gray-500 font-medium">Rango personalizado:</span>
                <el-date-picker
                    v-model="customRange"
                    type="daterange"
                    range-separator="→"
                    start-placeholder="Inicio"
                    end-placeholder="Fin"
                    format="DD/MM/YYYY"
                    value-format="YYYY-MM-DD"
                    class="max-w-xs"
                    @change="onCustomRangeChange"
                />
            </div>

            <!-- ========== KPIs GLOBALES ========== -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <!-- Ingreso Neto -->
                <GlobalKpi
                    label="Ingreso Neto"
                    :value="formatCurrency(kpis?.net_income || 0)"
                    :prev-value="formatCurrency(kpis?.prev_net_income || 0)"
                    :icon="DollarSign"
                    :growth="kpis?.income_growth"
                    color="bg-green-50"
                />
                <!-- Volumen de Pólizas -->
                <GlobalKpi
                    label="Volumen de Pólizas"
                    :value="formatNumber(kpis?.total_policies || 0)"
                    :prev-value="formatNumber(kpis?.prev_total_policies || 0)"
                    :icon="FileText"
                    :growth="kpis?.policies_growth"
                    color="bg-blue-50"
                    :detail="[
                        { label: 'METLIFE', current: kpis?.policies_by_product?.['1'] || 0, prev: kpis?.prev_policies_by_product?.['1'] || 0 },
                        { label: 'PERFECTLIFE', current: kpis?.policies_by_product?.['2'] || 0, prev: kpis?.prev_policies_by_product?.['2'] || 0 },
                        { label: 'PRIMORDIAL', current: kpis?.policies_by_product?.['3'] || 0, prev: kpis?.prev_policies_by_product?.['3'] || 0 },
                    ]"
                />
                <!-- Bonos Proyectados -->
                <GlobalKpi
                    label="Bonos Proyectados"
                    :value="formatCurrency(kpis?.projected_bonuses || 0)"
                    :prev-value="formatCurrency(kpis?.prev_projected_bonuses || 0)"
                    :icon="Award"
                    :growth="kpis?.bonuses_growth"
                    color="bg-amber-50"
                    :detail="[
                        { label: 'Agentes', current: formatCurrency(kpis?.projected_agent_bonuses || 0) },
                        { label: 'Promotores', current: formatCurrency(kpis?.projected_promoter_bonuses || 0) },
                    ]"
                />
                <!-- Fuerza de Ventas -->
                <GlobalKpi
                    label="Fuerza de Ventas"
                    :value="`${kpis?.active_promoters || 0} P · ${kpis?.active_agents || 0} A`"
                    :icon="Users"
                    color="bg-indigo-50"
                    :detail="[
                        { label: 'Promotores activos', current: kpis?.active_promoters || 0 },
                        { label: 'Agentes activos', current: kpis?.active_agents || 0 },
                    ]"
                />
            </div>

            <!-- ========== ALERTAS INTELIGENTES ========== -->
            <SmartAlerts :alerts="alerts || []" />

            <!-- ========== RENDIMIENTO (Top 5) ========== -->
            <TopPerformers
                :top-agents="top_agents || []"
                :top-promoters="top_promoters || []"
            />

            <!-- ========== TENDENCIAS ========== -->
            <TrendsChart :data="trends || []" />

        </div>
    </AppLayout>
</template>