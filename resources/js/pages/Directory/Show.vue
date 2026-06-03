<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import BonusPanel from './BonusPanel.vue';
import {
    Calendar, UserCircle, Users, FileText, DollarSign, Award,
    UserPlus, TrendingUp, ChevronDown, ChevronUp,
    Check, AlertCircle, Clock,
} from 'lucide-vue-next';

const props = defineProps({
    entity: Object,
    type: String,
    stats: Object,
    filters: Object,
});

// ─── Formateo ──────────────────────────────────────
const formatDate = (dateString) => {
    if (!dateString) return '—';
    return new Date(dateString).toLocaleDateString('es-ES', {
        year: 'numeric', month: 'long', day: 'numeric'
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

/**
 * Formatea un valor de métrica: usa formato de moneda si es >= 1000
 * o si el target es >= 1000, de lo contrario lo muestra como número entero.
 */
const formatMetricValue = (value, target = 0) => {
    if ((target > 0 && target >= 1000) || (value >= 1000)) {
        return formatCurrency(value);
    }
    return Number.isInteger(value) ? value : value.toFixed(2);
};

const getInitials = (name) => {
    if (!name) return '?';
    return name.split(' ').filter(w => w.length > 0).map(w => w[0]?.toUpperCase()).slice(0, 2).join('');
};

const getPhotoUrl = (photoPath) => {
    if (!photoPath) return null;
    return `/storage/${photoPath}`;
};

const breadcrumbs = [
    { title: 'Directorio', href: route('directorio') },
    { title: 'Información' },
];

const productLabels = {
    'METLIFE': 'Vida (METLIFE)',
    'PERFECTLIFE': 'Vida (PERFECTLIFE)',
    'PRIMORDIAL': 'Primordial',
    '1': 'Producto 1', '2': 'Producto 2', '3': 'Producto 3',
};

// ─── Rango de Fechas ───────────────────────────────
const dateRange = ref([
    props.filters?.start_date || '',
    props.filters?.end_date || '',
]);

const fetchWithDateRange = () => {
    const routeName = props.type === 'promoter' ? 'promoters.show' : 'agents.show';
    router.get(route(routeName, props.entity.id), {
        start_date: dateRange.value[0],
        end_date: dateRange.value[1],
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

// ─── Botón Histórico ──────────────────────────────
const showHistorical = () => {
    const start = props.entity.entry_date || props.entity.created_at?.split('T')[0] || '';
    const end = new Date().toISOString().split('T')[0];

    dateRange.value = [start, end];

    const routeName = props.type === 'promoter' ? 'promoters.show' : 'agents.show';
    router.get(route(routeName, props.entity.id), {
        start_date: start,
        end_date: end,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

// ─── Filtros Rápidos por Trimestre ────────────────
const currentYear = new Date().getFullYear();

const quarters = [
    { label: 'Q1', months: 'Enero, Febrero, Marzo', start: `${currentYear}-01-01`, end: `${currentYear}-03-31` },
    { label: 'Q2', months: 'Abril, Mayo, Junio',     start: `${currentYear}-04-01`, end: `${currentYear}-06-30` },
    { label: 'Q3', months: 'Julio, Agosto, Septiembre', start: `${currentYear}-07-01`, end: `${currentYear}-09-30` },
    { label: 'Q4', months: 'Octubre, Noviembre, Diciembre', start: `${currentYear}-10-01`, end: `${currentYear}-12-31` },
];

const activeQuarter = ref(null);

const applyQuarter = (q) => {
    activeQuarter.value = q.label;
    dateRange.value = [q.start, q.end];
    fetchWithDateRange();
};

// ─── Acordeón de Agentes (Promotor) ────────────────
const expandedAgent = ref(null);
const toggleAgent = (agentId) => {
    expandedAgent.value = expandedAgent.value === agentId ? null : agentId;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="entity.name" />

        <div class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8 space-y-6">

            <!-- ========== HEADER CARD ========== -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-6 border-b border-gray-100 bg-gray-50/30 flex justify-between items-start">
                    <div class="flex items-center space-x-4">
                        <div v-if="getPhotoUrl(entity.photo)" class="flex-shrink-0">
                            <img :src="getPhotoUrl(entity.photo)" class="w-14 h-14 rounded-xl object-cover border-2 border-gray-100" />
                        </div>
                        <div v-else class="flex-shrink-0 w-14 h-14 rounded-xl bg-gray-200 flex items-center justify-center text-gray-600 font-bold text-lg">
                            {{ getInitials(entity.name) }}
                        </div>
                        <div>
                            <div class="flex items-center space-x-3">
                                <h1 class="text-2xl font-bold text-gray-900">{{ entity.name }}</h1>
                                <span v-if="entity.is_active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">Activo</span>
                                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">Inactivo</span>
                            </div>
                            <p class="text-sm text-gray-500 capitalize">{{ type === 'promoter' ? 'Promotor' : 'Agente' }}</p>
                        </div>
                    </div>
                    <Link :href="route(type === 'promoter' ? 'promoters.edit' : 'agents.edit', entity.id)" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors">
                        Editar
                    </Link>
                </div>

                <!-- Información General -->
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider">Detalles</h3>
                            <div class="flex items-center text-sm text-gray-900">
                                <Calendar class="w-4 h-4 mr-2 text-gray-400" />
                                Registrado el: {{ formatDate(entity.created_at) }}
                            </div>
                            <div v-if="type === 'agent'" class="flex items-center text-sm text-gray-900">
                                <UserCircle class="w-4 h-4 mr-2 text-gray-400" />
                                Promotor:
                                <Link v-if="entity.promoter" :href="route('promoters.show', entity.promoter.id)" class="ml-1 text-black font-medium hover:underline">
                                    {{ entity.promoter.name }}
                                </Link>
                                <span v-else class="ml-1 text-gray-500">Ninguno</span>
                            </div>
                            <div v-if="type === 'promoter'" class="flex items-center text-sm text-gray-900">
                                <Users class="w-4 h-4 mr-2 text-gray-400" />
                                Agentes en red: <span class="ml-1 font-medium">{{ entity.agents?.filter(a => a.is_active).length || 0 }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== FILTRO DE RANGO DE FECHAS ========== -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 flex flex-col sm:flex-row items-start sm:items-center gap-3">
                <Calendar class="w-5 h-5 text-gray-400 hidden sm:block" />
                <span class="text-sm font-medium text-gray-600">Periodo:</span>
                <el-date-picker
                    v-model="dateRange"
                    type="daterange"
                    range-separator="→"
                    start-placeholder="Inicio"
                    end-placeholder="Fin"
                    format="DD/MM/YYYY"
                    value-format="YYYY-MM-DD"
                    class="flex-1 sm:max-w-xs"
                    @change="fetchWithDateRange"
                />

                <!-- Botones rápidos de trimestre -->
                <div class="flex items-center gap-1">
                    <button
                        v-for="q in quarters"
                        :key="q.label"
                        type="button"
                        :title="`${q.label}: ${q.months}`"
                        @click="applyQuarter(q)"
                        class="px-2.5 py-1.5 text-xs font-semibold rounded-lg border transition-colors"
                        :class="activeQuarter === q.label
                            ? 'bg-[#4a7c59] text-white border-[#4a7c59] shadow-sm'
                            : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50 hover:border-gray-300'"
                    >
                        {{ q.label }}
                    </button>
                </div>

                <button
                    type="button"
                    @click="showHistorical"
                    class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition-colors"
                >
                    <Clock class="w-4 h-4 mr-1.5" />
                    Histórico
                </button>
            </div>

            <!-- ═══════════ AGENTE ═══════════ -->
            <template v-if="type === 'agent' && stats">

                <!-- 3 Tarjetas de Estadísticas -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex items-start space-x-4">
                        <div class="p-3 bg-blue-50 rounded-xl"><FileText class="w-6 h-6 text-blue-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Pólizas Vendidas</p>
                            <p class="text-2xl font-bold text-gray-900">{{ stats.policies_count }}</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex items-start space-x-4">
                        <div class="p-3 bg-green-50 rounded-xl"><DollarSign class="w-6 h-6 text-green-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Comisiones Generadas</p>
                            <p class="text-2xl font-bold text-gray-900">{{ formatCurrency(stats.total_commissions) }}</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex items-start space-x-4">
                        <div class="p-3 bg-amber-50 rounded-xl"><Award class="w-6 h-6 text-amber-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Estatus de Bonos</p>
                            <p class="text-2xl font-bold text-gray-900">
                                {{ stats.bonuses.filter(b => b.unlocked).length }} de {{ stats.bonuses.length }} desbloqueados
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Grid 2 Columnas -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Historial Transaccional -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="px-5 py-4 border-b border-gray-100">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Historial Transaccional</h3>
                        </div>
                        <div v-if="entity.policies && entity.policies.length > 0" class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-gray-50">
                                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Cliente</th>
                                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Producto</th>
                                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Fecha</th>
                                        <th class="px-5 py-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wider">Comisión</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="policy in entity.policies" :key="policy.id" class="hover:bg-gray-50/50 transition-colors">
                                        <td class="px-5 py-3 text-sm text-gray-900">{{ policy.client_name || '—' }}</td>
                                        <td class="px-5 py-3 text-sm text-gray-600">{{ productLabels[policy.product_type] || policy.product_type || '—' }}</td>
                                        <td class="px-5 py-3 text-sm text-gray-500">{{ formatDate(policy.issue_date) }}</td>
                                        <td class="px-5 py-3 text-sm text-right">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                {{ formatCurrency(policy.commission_amount) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="px-5 py-10 text-center text-gray-400 text-sm">No hay pólizas en este periodo.</div>
                    </div>

                    <!-- Panel de Bonos -->
                    <BonusPanel :bonuses="stats.bonuses" type="agent" />
                </div>
            </template>

            <!-- ═══════════ PROMOTOR ═══════════ -->
            <template v-if="type === 'promoter' && stats">

                <!-- 4 Tarjetas de Estadísticas -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- 1. Agentes Activos -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex items-start space-x-4">
                        <div class="p-3 bg-indigo-50 rounded-xl"><Users class="w-6 h-6 text-indigo-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Agentes Activos</p>
                            <p class="text-2xl font-bold text-gray-900">{{ stats.active_agents }}</p>
                        </div>
                    </div>
                    <!-- 2. Nuevos Reclutamientos -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex items-start space-x-4">
                        <div class="p-3 bg-teal-50 rounded-xl"><UserPlus class="w-6 h-6 text-teal-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Nuevos Reclutamientos</p>
                            <p class="text-2xl font-bold text-gray-900">{{ stats.new_recruitments }}</p>
                        </div>
                    </div>
                    <!-- 3. Volumen de Venta -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex items-start space-x-4">
                        <div class="p-3 bg-green-50 rounded-xl"><TrendingUp class="w-6 h-6 text-green-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Vol. Venta del Equipo</p>
                            <p class="text-2xl font-bold text-gray-900">{{ formatCurrency(stats.team_sales_volume) }}</p>
                        </div>
                    </div>
                    <!-- 4. Bonos Alcanzados -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex items-start space-x-4">
                        <div class="p-3 bg-amber-50 rounded-xl"><Award class="w-6 h-6 text-amber-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Bonos Alcanzados</p>
                            <p class="text-2xl font-bold text-gray-900">{{ stats.bonuses_achieved }} de {{ stats.bonuses_total }}</p>
                        </div>
                    </div>
                </div>

                <!-- Grid 2 Columnas -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- ▸ Columna Izquierda: Directorio Operativo (Acordeón de Agentes) -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="px-5 py-4 border-b border-gray-100">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Directorio Operativo</h3>
                        </div>
                        <div v-if="stats.agents && stats.agents.length > 0" class="divide-y divide-gray-50">
                            <div v-for="agent in stats.agents" :key="agent.id">
                                <!-- Cabecera del Agente -->
                                <button
                                    @click="toggleAgent(agent.id)"
                                    class="w-full px-5 py-4 flex items-center justify-between hover:bg-gray-50/50 transition-colors text-left"
                                >
                                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                                        <div v-if="getPhotoUrl(agent.photo)" class="flex-shrink-0 w-10 h-10 rounded-lg overflow-hidden">
                                            <img :src="getPhotoUrl(agent.photo)" class="w-full h-full object-cover" />
                                        </div>
                                        <div v-else class="flex-shrink-0 w-10 h-10 rounded-lg bg-gray-200 flex items-center justify-center text-gray-500 font-bold text-sm">
                                            {{ getInitials(agent.name) }}
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-gray-900 truncate">{{ agent.name }}</p>
                                            <p class="text-xs text-gray-400">{{ agent.policies_count }} póliza(s)</p>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <Check v-if="agent.meets_requirement" class="w-5 h-5 text-green-600" />
                                            <AlertCircle v-else class="w-5 h-5 text-amber-500" />
                                        </div>
                                    </div>
                                    <ChevronDown v-if="expandedAgent !== agent.id" class="w-4 h-4 text-gray-400 ml-3 flex-shrink-0" />
                                    <ChevronUp v-else class="w-4 h-4 text-gray-400 ml-3 flex-shrink-0" />
                                </button>

                                <!-- Detalle expandido -->
                                <div v-if="expandedAgent === agent.id" class="px-5 pb-4 bg-gray-50/50 border-t border-gray-100">
                                    <div class="pt-3 space-y-2">
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-500">Pólizas en el periodo:</span>
                                            <span class="font-medium text-gray-900">{{ agent.policies_count }}</span>
                                        </div>
                                        <div v-if="agent.required_product" class="flex items-center justify-between text-sm">
                                            <span class="text-gray-500">Prod. requerido ({{ productLabels[agent.required_product] || agent.required_product }}):</span>
                                            <span class="font-medium" :class="agent.meets_requirement ? 'text-green-700' : 'text-amber-700'">
                                                {{ agent.meets_requirement ? 'Cumplido ✓' : `Falta (mín. ${agent.min_policies_for_bonus})` }}
                                            </span>
                                        </div>
                                        <div v-else class="flex items-center justify-between text-sm">
                                            <span class="text-gray-500">Requisito de desarrollo:</span>
                                            <span class="font-medium" :class="agent.meets_requirement ? 'text-green-700' : 'text-amber-700'">
                                                {{ agent.meets_requirement ? 'Cumplido ✓' : `Falta (mín. ${agent.min_policies_for_bonus} póliza(s))` }}
                                            </span>
                                        </div>

                                        <!-- Mini historial del agente -->
                                        <div v-if="agent.policies && agent.policies.length > 0" class="pt-3">
                                            <p class="text-xs text-gray-400 mb-2 uppercase tracking-wider">Últimas pólizas</p>
                                            <div class="space-y-2">
                                                <div v-for="pol in agent.policies.slice(0, 5)" :key="pol.id" class="flex items-center justify-between bg-white rounded-lg px-3 py-2 text-xs border border-gray-100">
                                                    <div>
                                                        <span class="font-medium text-gray-900">{{ pol.client_name || '—' }}</span>
                                                        <span class="text-gray-400 ml-2">{{ productLabels[pol.product_type] || pol.product_type }}</span>
                                                    </div>
                                                    <div class="text-right">
                                                        <span class="text-gray-600">{{ formatDate(pol.issue_date) }}</span>
                                                        <span class="ml-2 font-medium text-green-700">{{ formatCurrency(pol.commission_amount) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="pt-2 text-xs text-gray-400">Sin pólizas en este periodo.</div>

                                        <Link :href="route('agents.show', agent.id)" class="inline-block mt-2 text-xs font-medium text-black hover:underline">
                                            Ver perfil completo →
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="px-5 py-10 text-center text-gray-400 text-sm">No hay agentes asignados.</div>
                    </div>

                    <!-- ▸ Columna Derecha: Ruta de Bonos (Timeline Stepper) -->
                    <BonusPanel :bonuses="stats.bonuses" type="promoter" />

                </div>

                <!-- ▸ Sección: Comisiones del Promotor por Agente -->
                <div v-if="stats.commissions && stats.commissions.length > 0" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 bg-emerald-50/30">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider flex items-center">
                                <DollarSign class="w-4 h-4 mr-2 text-emerald-600" />
                                Comisiones por Ventas del Equipo
                            </h3>
                            <span class="text-sm font-bold text-emerald-700">
                                Total: {{ formatCurrency(stats.total_promoter_commission) }}
                            </span>
                        </div>
                        <p v-if="stats.commission_scheme_name" class="text-xs text-gray-400 mt-1">
                            Esquema: {{ stats.commission_scheme_name }}
                        </p>
                    </div>

                    <div class="divide-y divide-gray-50">
                        <div v-for="(agentComm, acIdx) in stats.commissions" :key="acIdx">
                            <!-- Cabecera del Agente -->
                            <div class="px-5 py-4 flex items-center justify-between bg-gray-50/30">
                                <div class="flex items-center space-x-3">
                                    <div v-if="getPhotoUrl(agentComm.photo)" class="w-8 h-8 rounded-lg overflow-hidden flex-shrink-0">
                                        <img :src="getPhotoUrl(agentComm.photo)" class="w-full h-full object-cover" />
                                    </div>
                                    <div v-else class="w-8 h-8 rounded-lg bg-gray-200 flex items-center justify-center text-gray-500 font-bold text-xs flex-shrink-0">
                                        {{ getInitials(agentComm.agent_name) }}
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ agentComm.agent_name }}</p>
                                        <p class="text-xs text-gray-400">{{ agentComm.policies_count }} póliza(s)</p>
                                    </div>
                                </div>
                                <span class="text-sm font-bold text-emerald-700">{{ formatCurrency(agentComm.total_commission) }}</span>
                            </div>

                            <!-- Detalle de pólizas del agente -->
                            <div v-if="agentComm.policies && agentComm.policies.length > 0" class="px-5 py-3">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="border-b border-gray-100">
                                                <th class="py-2 text-left text-gray-400 font-medium">Cliente</th>
                                                <th class="py-2 text-left text-gray-400 font-medium">Producto</th>
                                                <th class="py-2 text-right text-gray-400 font-medium">Prima</th>
                                                <th class="py-2 text-right text-gray-400 font-medium">% Com.</th>
                                                <th class="py-2 text-right text-gray-400 font-medium">Comisión</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            <tr v-for="(pol, pIdx) in agentComm.policies" :key="pIdx" class="hover:bg-gray-50/50">
                                                <td class="py-2 text-gray-900">{{ pol.client_name || '—' }}</td>
                                                <td class="py-2 text-gray-600">{{ productLabels[pol.product_type] || pol.product_type }}</td>
                                                <td class="py-2 text-right text-gray-700">{{ formatCurrency(pol.premium_amount) }}</td>
                                                <td class="py-2 text-right text-gray-500">{{ pol.percentage }}%</td>
                                                <td class="py-2 text-right">
                                                    <span class="font-medium text-emerald-700">{{ formatCurrency(pol.commission) }}</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div v-else class="px-5 py-3 text-xs text-gray-400">Sin pólizas en este periodo.</div>
                        </div>
                    </div>
                </div>

                <!-- Sin comisiones configuradas -->
                <div v-else-if="!stats.commissions || stats.commissions.length === 0" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 text-center text-gray-400 text-sm">
                    <DollarSign class="w-5 h-5 mx-auto mb-2 text-gray-300" />
                    No hay comisiones configuradas o no hay pólizas en el periodo.
                </div>
            </template>

        </div>
    </AppLayout>
</template>