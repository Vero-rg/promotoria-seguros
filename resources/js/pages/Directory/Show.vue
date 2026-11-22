<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    Award,
    Calendar,
    Check,
    ChevronDown,
    ChevronUp,
    DollarSign,
    FileText,
    TrendingUp,
    UserCircle,
    UserPlus,
    Users,
} from 'lucide-vue-next';
import { ref } from 'vue';
import BonusPanel from './BonusPanel.vue';

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
        year: 'numeric',
        month: 'long',
        day: 'numeric',
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
    if ((target > 0 && target >= 1000) || value >= 1000) {
        return formatCurrency(value);
    }
    return Number.isInteger(value) ? value : value.toFixed(2);
};

const getInitials = (name) => {
    if (!name) return '?';
    return name
        .split(' ')
        .filter((w) => w.length > 0)
        .map((w) => w[0]?.toUpperCase())
        .slice(0, 2)
        .join('');
};

const getPhotoUrl = (photoPath) => {
    if (!photoPath) return null;
    return `/storage/${photoPath}`;
};

const breadcrumbs = [{ title: 'Directorio', href: route('directorio') }, { title: 'Información' }];

const productLabels = {
    METALIFE: 'Vida (METALIFE)',
    PERFECTLIFE: 'Vida (PERFECTLIFE)',
    PRIMORDIAL: 'Primordial',
    1: 'Producto 1',
    2: 'Producto 2',
    3: 'Producto 3',
};

// ─── Rango de Fechas ───────────────────────────────
const dateRange = ref([props.filters?.start_date || '', props.filters?.end_date || '']);

const fetchWithDateRange = () => {
    const routeName = props.type === 'promoter' ? 'promoters.show' : 'agents.show';
    router.get(
        route(routeName, props.entity.id),
        {
            start_date: dateRange.value[0],
            end_date: dateRange.value[1],
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

// ─── Botón Histórico ──────────────────────────────
// const showHistorical = () => {
//     const start = props.entity.entry_date || props.entity.created_at?.split('T')[0] || '';
//     const end = new Date().toISOString().split('T')[0];

//     dateRange.value = [start, end];

//     const routeName = props.type === 'promoter' ? 'promoters.show' : 'agents.show';
//     router.get(route(routeName, props.entity.id), {
//         start_date: start,
//         end_date: end,
//     }, {
//         preserveState: true,
//         preserveScroll: true,
//         replace: true,
//     });
// };

// ─── Filtros Rápidos por Trimestre ────────────────
const currentYear = new Date().getFullYear();

const quarters = [
    { label: 'Q1', months: 'Enero, Febrero, Marzo', start: `${currentYear}-01-01`, end: `${currentYear}-03-31` },
    { label: 'Q2', months: 'Abril, Mayo, Junio', start: `${currentYear}-04-01`, end: `${currentYear}-06-30` },
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

        <div class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
            <!-- ========== HEADER CARD ========== -->
            <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                <div class="flex items-start justify-between border-b border-gray-100 bg-gray-50/30 px-6 py-6">
                    <div class="flex items-center space-x-4">
                        <div v-if="getPhotoUrl(entity.photo)" class="flex-shrink-0">
                            <img :src="getPhotoUrl(entity.photo)" class="h-14 w-14 rounded-xl border-2 border-gray-100 object-cover" />
                        </div>
                        <div
                            v-else
                            class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl bg-gray-200 text-lg font-bold text-gray-600"
                        >
                            {{ getInitials(entity.name) }}
                        </div>
                        <div>
                            <div class="flex items-center space-x-3">
                                <h1 class="text-2xl font-bold text-gray-900">{{ entity.name }}</h1>
                                <span
                                    v-if="entity.is_active"
                                    class="inline-flex items-center rounded-full border border-green-200 bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800"
                                    >Activo</span
                                >
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full border border-red-200 bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800"
                                    >Inactivo</span
                                >
                            </div>
                            <p class="text-sm capitalize text-gray-500">{{ type === 'promoter' ? 'Promotor' : 'Agente' }}</p>
                        </div>
                    </div>
                    <Link
                        :href="route(type === 'promoter' ? 'promoters.edit' : 'agents.edit', entity.id)"
                        class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                    >
                        Editar
                    </Link>
                </div>

                <!-- Información General -->
                <div class="p-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div class="space-y-4">
                            <h3 class="text-sm font-medium uppercase tracking-wider text-gray-500">Detalles</h3>
                            <div class="flex items-center text-sm text-gray-900">
                                <Calendar class="mr-2 h-4 w-4 text-gray-400" />
                                Registrado el: {{ formatDate(entity.created_at) }}
                            </div>
                            <div v-if="type === 'agent'" class="flex items-center text-sm text-gray-900">
                                <UserCircle class="mr-2 h-4 w-4 text-gray-400" />
                                Promotor:
                                <Link
                                    v-if="entity.promoter"
                                    :href="route('promoters.show', entity.promoter.id)"
                                    class="ml-1 font-medium text-black hover:underline"
                                >
                                    {{ entity.promoter.name }}
                                </Link>
                                <span v-else class="ml-1 text-gray-500">Ninguno</span>
                            </div>
                            <div v-if="type === 'promoter'" class="flex items-center text-sm text-gray-900">
                                <Users class="mr-2 h-4 w-4 text-gray-400" />
                                Agentes en red: <span class="ml-1 font-medium">{{ entity.agents?.filter((a) => a.is_active).length || 0 }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== FILTRO DE RANGO DE FECHAS ========== -->
            <div class="flex flex-col items-start gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:flex-row sm:items-center">
                <Calendar class="hidden h-5 w-5 text-gray-400 sm:block" />
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
                        class="rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors"
                        :class="
                            activeQuarter === q.label
                                ? 'border-[#4a7c59] bg-[#4a7c59] text-white shadow-sm'
                                : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50'
                        "
                    >
                        {{ q.label }}
                    </button>
                </div>

                <!-- <button
                    type="button"
                    @click="showHistorical"
                    class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition-colors"
                >
                    <Clock class="w-4 h-4 mr-1.5" />
                    Histórico
                </button> -->
            </div>

            <!-- ═══════════ AGENTE ═══════════ -->
            <template v-if="type === 'agent' && stats">
                <!-- 3 Tarjetas de Estadísticas -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="flex items-start space-x-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div class="rounded-xl bg-blue-50 p-3"><FileText class="h-6 w-6 text-blue-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Pólizas Vendidas</p>
                            <p class="text-2xl font-bold text-gray-900">{{ stats.policies_count }}</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div class="rounded-xl bg-green-50 p-3"><DollarSign class="h-6 w-6 text-green-600" /></div>
                        <div class="flex-1">
                            <p class="mb-1 text-sm text-gray-500">Comisiones Generadas</p>
                            <div class="space-y-0.5">
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-400">Comisión total generada</span>
                                    <span class="font-medium text-gray-700">{{ formatCurrency(stats.commission_breakdown?.gross || 0) }}</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-400">− Retención de ISR</span>
                                    <span class="font-medium text-red-600">−{{ formatCurrency(stats.commission_breakdown?.isr || 0) }}</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-400">− Impuesto cedular</span>
                                    <span class="font-medium text-red-600">−{{ formatCurrency(stats.commission_breakdown?.cedular || 0) }}</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-400">− Costo de facturación</span>
                                    <span class="font-medium text-red-600">−{{ formatCurrency(stats.commission_breakdown?.billing || 0) }}</span>
                                </div>
                                <div class="flex justify-between border-t border-gray-100 pt-1 text-sm font-bold">
                                    <span class="text-gray-700">Total neto</span>
                                    <span class="text-emerald-700">{{ formatCurrency(stats.commission_breakdown?.net || 0) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-start space-x-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div class="rounded-xl bg-amber-50 p-3"><Award class="h-6 w-6 text-amber-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Estatus de Bonos</p>
                            <p class="text-2xl font-bold text-gray-900">
                                {{ stats.bonuses.filter((b) => b.unlocked).length }} de {{ stats.bonuses.length }} desbloqueados
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Grid 2 Columnas -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Historial Transaccional -->
                    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-5 py-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-900">Historial Transaccional</h3>
                        </div>
                        <div v-if="entity.policies && entity.policies.length > 0" class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-gray-50">
                                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Cliente</th>
                                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Producto</th>
                                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Fecha</th>
                                        <th class="px-5 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-400">Estatus</th>
                                        <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-400">Comisión</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="policy in entity.policies" :key="policy.id" class="transition-colors hover:bg-gray-50/50">
                                        <td class="px-5 py-3 text-sm text-gray-900">{{ policy.client_name || '—' }}</td>
                                        <td class="px-5 py-3 text-sm text-gray-600">
                                            {{ productLabels[policy.product_type] || policy.product_type || '—' }}
                                        </td>
                                        <td class="px-5 py-3 text-sm text-gray-500">{{ formatDate(policy.issue_date) }}</td>
                                        <td class="px-5 py-3 text-center text-sm">
                                            <span
                                                class="rounded px-1.5 py-0.5 text-[10px] font-semibold"
                                                :class="
                                                    policy.status === 'Pagada'
                                                        ? 'bg-blue-100 text-blue-700'
                                                        : policy.status === 'No tomada'
                                                          ? 'bg-gray-100 text-gray-500'
                                                          : 'bg-green-100 text-green-700'
                                                "
                                            >
                                                {{ policy.status || '—' }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm">
                                            <span
                                                class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800"
                                            >
                                                {{ formatCurrency(policy.resolved_commission_amount ?? policy.commission_amount) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="px-5 py-10 text-center text-sm text-gray-400">No hay pólizas en este periodo.</div>
                    </div>

                    <!-- Panel de Bonos -->
                    <BonusPanel :bonuses="stats.bonuses" type="agent" :user-id="entity.id" />
                </div>
            </template>

            <!-- ═══════════ PROMOTOR ═══════════ -->
            <template v-if="type === 'promoter' && stats">
                <!-- 4 Tarjetas de Estadísticas -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <!-- 1. Agentes Activos -->
                    <div class="flex items-start space-x-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div class="rounded-xl bg-indigo-50 p-3"><Users class="h-6 w-6 text-indigo-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Agentes Activos</p>
                            <p class="text-2xl font-bold text-gray-900">{{ stats.active_agents }}</p>
                        </div>
                    </div>
                    <!-- 2. Nuevos Reclutamientos -->
                    <div class="flex items-start space-x-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div class="rounded-xl bg-teal-50 p-3"><UserPlus class="h-6 w-6 text-teal-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Nuevos Reclutamientos</p>
                            <p class="text-2xl font-bold text-gray-900">{{ stats.new_recruitments }}</p>
                        </div>
                    </div>
                    <!-- 3. Volumen de Venta -->
                    <div class="flex items-start space-x-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div class="rounded-xl bg-green-50 p-3"><TrendingUp class="h-6 w-6 text-green-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Vol. Venta del Equipo</p>
                            <p class="text-2xl font-bold text-gray-900">{{ formatCurrency(stats.team_sales_volume) }}</p>
                        </div>
                    </div>
                    <!-- 4. Bonos Alcanzados -->
                    <div class="flex items-start space-x-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div class="rounded-xl bg-amber-50 p-3"><Award class="h-6 w-6 text-amber-600" /></div>
                        <div>
                            <p class="text-sm text-gray-500">Bonos Alcanzados</p>
                            <p class="text-2xl font-bold text-gray-900">{{ stats.bonuses_achieved }} de {{ stats.bonuses_total }}</p>
                        </div>
                    </div>
                </div>

                <!-- Grid 2 Columnas -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- ▸ Columna Izquierda: Directorio Operativo (Acordeón de Agentes) -->
                    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-5 py-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-900">Directorio Operativo</h3>
                        </div>
                        <div v-if="stats.agents && stats.agents.length > 0" class="divide-y divide-gray-50">
                            <div v-for="agent in stats.agents" :key="agent.id">
                                <!-- Cabecera del Agente -->
                                <button
                                    @click="toggleAgent(agent.id)"
                                    class="flex w-full items-center justify-between px-5 py-4 text-left transition-colors hover:bg-gray-50/50"
                                >
                                    <div class="flex min-w-0 flex-1 items-center space-x-3">
                                        <div v-if="getPhotoUrl(agent.photo)" class="h-10 w-10 flex-shrink-0 overflow-hidden rounded-lg">
                                            <img :src="getPhotoUrl(agent.photo)" class="h-full w-full object-cover" />
                                        </div>
                                        <div
                                            v-else
                                            class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-gray-200 text-sm font-bold text-gray-500"
                                        >
                                            {{ getInitials(agent.name) }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-gray-900">{{ agent.name }}</p>
                                            <p class="text-xs text-gray-400">{{ agent.policies_count }} póliza(s)</p>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <Check v-if="agent.meets_requirement" class="h-5 w-5 text-green-600" />
                                            <AlertCircle v-else class="h-5 w-5 text-amber-500" />
                                        </div>
                                    </div>
                                    <ChevronDown v-if="expandedAgent !== agent.id" class="ml-3 h-4 w-4 flex-shrink-0 text-gray-400" />
                                    <ChevronUp v-else class="ml-3 h-4 w-4 flex-shrink-0 text-gray-400" />
                                </button>

                                <!-- Detalle expandido -->
                                <div v-if="expandedAgent === agent.id" class="border-t border-gray-100 bg-gray-50/50 px-5 pb-4">
                                    <div class="space-y-2 pt-3">
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-500">Pólizas en el periodo:</span>
                                            <span class="font-medium text-gray-900">{{ agent.policies_count }}</span>
                                        </div>
                                        <div v-if="agent.required_product" class="flex items-center justify-between text-sm">
                                            <span class="text-gray-500"
                                                >Prod. requerido ({{ productLabels[agent.required_product] || agent.required_product }}):</span
                                            >
                                            <span class="font-medium" :class="agent.meets_requirement ? 'text-green-700' : 'text-amber-700'">
                                                {{ agent.meets_requirement ? 'Cumplido ✓' : `Falta (mín. ${agent.min_policies_for_bonus})` }}
                                            </span>
                                        </div>
                                        <div v-else class="flex items-center justify-between text-sm">
                                            <span class="text-gray-500">Requisito de desarrollo:</span>
                                            <span class="font-medium" :class="agent.meets_requirement ? 'text-green-700' : 'text-amber-700'">
                                                {{
                                                    agent.meets_requirement ? 'Cumplido ✓' : `Falta (mín. ${agent.min_policies_for_bonus} póliza(s))`
                                                }}
                                            </span>
                                        </div>

                                        <!-- Mini historial del agente -->
                                        <div v-if="agent.policies && agent.policies.length > 0" class="pt-3">
                                            <p class="mb-2 text-xs uppercase tracking-wider text-gray-400">Últimas pólizas</p>
                                            <div class="space-y-2">
                                                <div
                                                    v-for="pol in agent.policies.slice(0, 5)"
                                                    :key="pol.id"
                                                    class="flex items-center justify-between rounded-lg border border-gray-100 bg-white px-3 py-2 text-xs"
                                                >
                                                    <div class="flex min-w-0 flex-1 items-center gap-2">
                                                        <span class="truncate font-medium text-gray-900">{{ pol.client_name || '—' }}</span>
                                                        <span class="text-gray-400">{{ productLabels[pol.product_type] || pol.product_type }}</span>
                                                        <span
                                                            class="rounded px-1.5 py-0.5 text-[10px] font-semibold"
                                                            :class="
                                                                pol.status === 'Pagada'
                                                                    ? 'bg-blue-100 text-blue-700'
                                                                    : pol.status === 'No tomada'
                                                                      ? 'bg-gray-100 text-gray-500'
                                                                      : 'bg-green-100 text-green-700'
                                                            "
                                                        >
                                                            {{ pol.status || '—' }}
                                                        </span>
                                                    </div>
                                                    <div class="ml-2 flex-shrink-0 text-right">
                                                        <span class="text-gray-600">{{ formatDate(pol.issue_date) }}</span>
                                                        <span class="ml-2 font-medium text-green-700">{{
                                                            formatCurrency(pol.resolved_commission_amount ?? pol.commission_amount)
                                                        }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="pt-2 text-xs text-gray-400">Sin pólizas en este periodo.</div>

                                        <Link
                                            :href="route('agents.show', agent.id)"
                                            class="mt-2 inline-block text-xs font-medium text-black hover:underline"
                                        >
                                            Ver perfil completo →
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="px-5 py-10 text-center text-sm text-gray-400">No hay agentes asignados.</div>
                    </div>

                    <!-- ▸ Columna Derecha: Ruta de Bonos (Timeline Stepper) -->
                    <BonusPanel :bonuses="stats.bonuses" type="promoter" :user-id="entity.id" />
                </div>

                <!-- ▸ Sección: Comisiones del Promotor por Agente -->
                <div
                    v-if="stats.commissions && stats.commissions.length > 0"
                    class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm"
                >
                    <div class="border-b border-gray-100 bg-emerald-50/30 px-5 py-4">
                        <div class="flex items-center justify-between">
                            <h3 class="flex items-center text-sm font-semibold uppercase tracking-wider text-gray-900">
                                <DollarSign class="mr-2 h-4 w-4 text-emerald-600" />
                                Comisiones por Ventas del Equipo
                            </h3>
                            <div class="flex items-center space-x-6 text-xs">
                                <span class="text-gray-500"
                                    >Total:
                                    <span class="font-medium text-gray-700">{{
                                        formatCurrency(stats.team_commission_breakdown?.gross || 0)
                                    }}</span></span
                                >
                                <span class="text-red-500">ISR: −{{ formatCurrency(stats.team_commission_breakdown?.isr || 0) }}</span>
                                <span class="text-red-500">Cedular: −{{ formatCurrency(stats.team_commission_breakdown?.cedular || 0) }}</span>
                                <span class="text-red-500">Facturación: −{{ formatCurrency(stats.team_commission_breakdown?.billing || 0) }}</span>
                                <span class="font-bold text-emerald-700">Neto: {{ formatCurrency(stats.team_commission_breakdown?.net || 0) }}</span>
                            </div>
                        </div>
                    </div>

                    <p v-if="stats.commission_scheme_name" class="mt-1 text-xs text-gray-400">Esquema: {{ stats.commission_scheme_name }}</p>

                    <div class="divide-y divide-gray-50">
                        <div v-for="(agentComm, acIdx) in stats.commissions" :key="acIdx">
                            <!-- Cabecera del Agente -->
                            <div class="bg-gray-50/30 px-5 py-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div v-if="getPhotoUrl(agentComm.photo)" class="h-8 w-8 flex-shrink-0 overflow-hidden rounded-lg">
                                            <img :src="getPhotoUrl(agentComm.photo)" class="h-full w-full object-cover" />
                                        </div>
                                        <div
                                            v-else
                                            class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-gray-200 text-xs font-bold text-gray-500"
                                        >
                                            {{ getInitials(agentComm.agent_name) }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">{{ agentComm.agent_name }}</p>
                                            <p class="text-xs text-gray-400">{{ agentComm.policies_count }} póliza(s)</p>
                                        </div>
                                    </div>
                                    <span class="text-sm font-bold text-emerald-700">{{ formatCurrency(agentComm.total_net) }}</span>
                                </div>
                                <!-- Desglose del agente -->
                                <div class="ml-11 mt-2 space-y-0.5">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400">Comisión total generada</span>
                                        <span class="font-medium text-gray-600">{{ formatCurrency(agentComm.total_gross) }}</span>
                                    </div>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400">− Retención de ISR</span>
                                        <span class="font-medium text-red-500">−{{ formatCurrency(agentComm.total_isr) }}</span>
                                    </div>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400">− Impuesto cedular</span>
                                        <span class="font-medium text-red-500">−{{ formatCurrency(agentComm.total_cedular || 0) }}</span>
                                    </div>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400">− Costo de facturación</span>
                                        <span class="font-medium text-red-500">−{{ formatCurrency(agentComm.total_billing) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Detalle de pólizas del agente -->
                            <div v-if="agentComm.policies && agentComm.policies.length > 0" class="px-5 py-3">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="border-b border-gray-100">
                                                <th class="py-2 text-left font-medium text-gray-400">Cliente</th>
                                                <th class="py-2 text-left font-medium text-gray-400">Producto</th>
                                                <th class="py-2 text-right font-medium text-gray-400">Prima</th>
                                                <th class="py-2 text-center font-medium text-gray-400">Estatus</th>
                                                <th class="py-2 text-right font-medium text-gray-400">% Com.</th>
                                                <th class="py-2 text-right font-medium text-gray-400">Comisión</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            <tr v-for="(pol, pIdx) in agentComm.policies" :key="pIdx" class="hover:bg-gray-50/50">
                                                <td class="py-2 text-gray-900">{{ pol.client_name || '—' }}</td>
                                                <td class="py-2 text-gray-600">{{ productLabels[pol.product_type] || pol.product_type }}</td>
                                                <td class="py-2 text-right text-gray-700">{{ formatCurrency(pol.premium_amount) }}</td>
                                                <td class="py-2 text-center">
                                                    <span
                                                        class="rounded px-1.5 py-0.5 text-[10px] font-semibold"
                                                        :class="
                                                            pol.status === 'Pagada'
                                                                ? 'bg-blue-100 text-blue-700'
                                                                : pol.status === 'No tomada'
                                                                  ? 'bg-gray-100 text-gray-500'
                                                                  : 'bg-green-100 text-green-700'
                                                        "
                                                    >
                                                        {{ pol.status || '—' }}
                                                    </span>
                                                </td>
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
                <div
                    v-else-if="!stats.commissions || stats.commissions.length === 0"
                    class="rounded-2xl border border-gray-100 bg-white p-5 text-center text-sm text-gray-400 shadow-sm"
                >
                    <DollarSign class="mx-auto mb-2 h-5 w-5 text-gray-300" />
                    No hay comisiones configuradas o no hay pólizas en el periodo.
                </div>
            </template>
        </div>
    </AppLayout>
</template>
