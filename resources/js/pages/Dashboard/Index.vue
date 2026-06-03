<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { DollarSign, FileText, Award, Users } from 'lucide-vue-next';
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
        total_pna: number;
        prev_pna: number;
        total_pca: number;
        prev_pca: number;
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
        total_commission: number;
        bonus_names: string[];
        bonus_details: Array<{ name: string; amount: number; progress_label: string }>;
        sparkline: number[];
    }>;
    top_promoters?: Array<{
        id: number;
        name: string;
        photo: string | null;
        team_volume: number;
        bonuses_secured: number;
        bonuses_total: number;
        bonus_names: string[];
        bonus_details: Array<{ name: string; amount: number; progress_label: string }>;
        total_commission: number;
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
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-2">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Dashboard</h1>
                    <p class="text-sm text-gray-400 mt-0.5">Resumen general de la operación</p>
                </div>

                <div class="flex items-center p-0.5 bg-gray-100/60 border border-gray-200/50 rounded-xl">
                    <button
                        v-for="f in [
                            { key: 'today', label: 'Hoy' },
                            { key: 'month', label: 'Mes' },
                            { key: 'q1', label: 'Q1' },
                            { key: 'q2', label: 'Q2' },
                            { key: 'q3', label: 'Q3' },
                            { key: 'q4', label: 'Q4' },
                            { key: 'year', label: 'Año' },
                            { key: 'custom', label: 'Personalizado' },
                        ]"
                        :key="f.key"
                        @click="setFilter(f.key)"
                        class="px-3.5 py-1.5 text-[13px] font-medium rounded-lg transition-all duration-200 ease-out"
                       :class="activeFilter === f.key ? 'bg-white text-gray-900 shadow-sm border border-gray-200/40' : 'text-gray-500 hover:text-gray-800 hover:bg-gray-50/50'"
                    >{{ f.label }}</button>
                </div>
            </div>

            <div v-if="showCustomRange" class="flex items-center space-x-3 bg-white rounded-2xl border border-gray-100 shadow-sm p-3">
                <span class="text-sm text-gray-500 font-medium">Rango:</span>
                <el-date-picker
                    v-model="customRange"
                    type="daterange"
                    range-separator="→"
                    start-placeholder="Inicio"
                    end-placeholder="Fin"
                    format="DD/MM/YYYY"
                    value-format="YYYY-MM-DD"
                    class="max-w-xs !border-gray-200 !rounded-lg"
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
                    icon-bg-class="bg-emerald-50"
                    icon-color-class="text-emerald-600"
                    :growth="kpis?.income_growth"
                    :detail="[
                        { label: 'PNA (Prima Pagada)', current: formatCurrency(kpis?.total_pna || 0) },
                        { label: 'PCA (Prima Computable)', current: formatCurrency(kpis?.total_pca || 0) },
                    ]"
                />
                <!-- Volumen de Pólizas -->
                <GlobalKpi
                    label="Vol. de Pólizas"
                    :value="formatNumber(kpis?.total_policies || 0)"
                    :prev-value="formatNumber(kpis?.prev_total_policies || 0)"
                    :icon="FileText"
                    icon-bg-class="bg-blue-50"
                    icon-color-class="text-blue-600"
                    :growth="kpis?.policies_growth"
                    :detail="[
                        { label: 'METLIFE', current: kpis?.policies_by_product?.METLIFE || 0 },
                        { label: 'PERFECTLIFE', current: kpis?.policies_by_product?.PERFECTLIFE || 0 },
                        { label: 'PRIMORDIAL', current: kpis?.policies_by_product?.PRIMORDIAL || 0 },
                    ]"
                />
                <!-- Bonos Proyectados -->
                <GlobalKpi
                    label="Bonos Proyectados"
                    :value="formatCurrency(kpis?.projected_bonuses || 0)"
                    :prev-value="formatCurrency(kpis?.prev_projected_bonuses || 0)"
                    :icon="Award"
                    icon-bg-class="bg-amber-50"
                    icon-color-class="text-amber-600"
                    :growth="kpis?.bonuses_growth"
                    :detail="[
                        { label: 'Agentes', current: formatCurrency(kpis?.projected_agent_bonuses || 0) },
                        { label: 'Promotores', current: formatCurrency(kpis?.projected_promoter_bonuses || 0) },
                    ]"
                />
                <!-- Fuerza de Ventas -->
                <GlobalKpi
                    label="Fuerza de Ventas"
                    :value="formatNumber((kpis?.active_promoters || 0) + (kpis?.active_agents || 0))"
                    :icon="Users"
                    icon-bg-class="bg-indigo-50"
                    icon-color-class="text-indigo-600"
                    subtitle="Personal activo"
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