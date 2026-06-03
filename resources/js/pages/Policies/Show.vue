<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Calendar, DollarSign, User, Percent, Receipt, Calculator } from 'lucide-vue-next';
import { computed } from 'vue';
import { ElMessage } from 'element-plus';

const props = defineProps({
    policy: Object,
});

const STATUS_OPTIONS = ['Activa', 'No tomada', 'Pagada'];

const handleStatusChange = (newStatus) => {
    router.patch(route('policies.status', props.policy.id), { status: newStatus }, {
        preserveScroll: true,
        onSuccess: () => ElMessage({ type: 'success', message: `Estatus cambiado a "${newStatus}"` }),
    });
};

const breadcrumbs = [
    {
        title: 'Pólizas',
        href: route('policies.index'),
    },
    {
        title: `Póliza ${props.policy.policy_number}`,
        href: '#',
    },
];

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount);
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-MX', {
        year: 'numeric', month: 'long', day: 'numeric'
    });
};

const statusColors = {
    activa: 'bg-green-100 text-green-800 border-green-200',
    'no tomada': 'bg-gray-100 text-gray-600 border-gray-200',
    pagada: 'bg-blue-100 text-blue-800 border-blue-200',
};

// Cálculo del resumen financiero
const summary = computed(() => {
    const premium = Number(props.policy.premium_amount) || 0;
    const agentComm = Number(props.policy.commission_amount) || 0;
    const promoterComm = Number(props.policy.promoter_commission_amount) || 0;
    const isrPct = Number(props.policy.isr_retention) || 0;
    const billingPct = Number(props.policy.billing_retention) || 0;

    // ISR y facturación ahora se deducen de las comisiones, no de la prima
    const isrAgentAmount = agentComm * (isrPct / 100);
    const billingAgentAmount = agentComm * (billingPct / 100);
    const isrPromoterAmount = promoterComm * (isrPct / 100);
    const billingPromoterAmount = promoterComm * (billingPct / 100);

    const agentNetComm = agentComm - isrAgentAmount - billingAgentAmount;
    const promoterNetComm = promoterComm - isrPromoterAmount - billingPromoterAmount;
    const totalDeductions = isrAgentAmount + billingAgentAmount + isrPromoterAmount + billingPromoterAmount;
    const netAmount = agentNetComm + promoterNetComm;

    return {
        premium,
        agentComm,
        promoterComm,
        isrPct,
        billingPct,
        isrAgentAmount,
        billingAgentAmount,
        isrPromoterAmount,
        billingPromoterAmount,
        agentNetComm,
        promoterNetComm,
        totalDeductions,
        netAmount,
    };
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Póliza ${policy.policy_number}`" />

        <div class="max-w-5xl mx-auto p-4 sm:p-6 lg:p-8">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <!-- Cabecera -->
                <div class="px-6 py-6 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-gray-50/30">
                    <div class="flex items-center space-x-4">
                        <div class="p-3 bg-blue-50 text-blue-600 rounded-xl">
                            <FileText class="w-8 h-8" />
                        </div>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-bold text-gray-900">Póliza {{ policy.policy_number }}</h1>
                                <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full border capitalize" 
                                      :class="statusColors[policy.status?.toLowerCase()] || 'bg-gray-100 text-gray-800 border-gray-200'">
                                    {{ policy.status }}
                                </span>
                            </div>
                            <p class="text-sm text-gray-500 mt-1 flex items-center">
                                <Calendar class="w-4 h-4 mr-1" />
                                Emitida el {{ formatDate(policy.issue_date) }}
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <el-dropdown trigger="click" @command="handleStatusChange">
                            <button class="px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors">
                                Cambiar Estatus
                            </button>
                            <template #dropdown>
                                <el-dropdown-menu>
                                    <el-dropdown-item 
                                        v-for="s in STATUS_OPTIONS.filter(s => s !== policy.status)" 
                                        :key="s" 
                                        :command="s"
                                    >
                                        Cambiar a {{ s }}
                                    </el-dropdown-item>
                                </el-dropdown-menu>
                            </template>
                        </el-dropdown>
                        <Link :href="route('policies.edit', policy.id)" class="px-4 py-2 bg-black text-white text-sm font-medium rounded-lg hover:bg-gray-800 transition-colors">
                            Editar Póliza
                        </Link>
                    </div>
                </div>

                <!-- Contenido -->
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-8">
                    
                    <!-- Columna Izquierda: Detalles Operativos -->
                    <div class="space-y-6">
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4 flex items-center">
                                <User class="w-4 h-4 mr-2" /> Agente y Promotor
                            </h3>
                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                                <div class="mb-3">
                                    <p class="text-xs text-gray-500 mb-1">Agente Asignado</p>
                                    <p class="text-base font-medium text-gray-900">{{ policy.agent?.name || 'No asignado' }}</p>
                                </div>
                                <div v-if="policy.agent?.promoter">
                                    <p class="text-xs text-gray-500 mb-1">Promotor a Cargo</p>
                                    <Link :href="route('promoters.show', policy.agent.promoter.id)" class="text-sm font-medium text-blue-600 hover:underline">
                                        {{ policy.agent.promoter.name }}
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <!-- Tipo de Producto -->
                        <div v-if="policy.product_type">
                            <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4 flex items-center">
                                <Percent class="w-4 h-4 mr-2" /> Producto
                            </h3>
                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                                <p class="text-base font-medium text-gray-900">{{ policy.product_type }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Columna Derecha: Detalles Financieros -->
                    <div class="space-y-6">
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4 flex items-center">
                                <DollarSign class="w-4 h-4 mr-2" /> Información Financiera
                            </h3>
                            
                            <div class="grid grid-cols-2 gap-2">
                                <div class="bg-blue-50/50 rounded-xl p-4 border border-blue-100">
                                    <p class="text-xs text-blue-600/80 font-medium mb-1 uppercase">Prima Total</p>
                                    <p class="text-2xl font-bold text-blue-900">{{ formatCurrency(policy.premium_amount) }}</p>
                                </div>
                                <div class="bg-green-50/50 rounded-xl p-4 border border-green-100">
                                    <p class="text-xs text-green-600/80 font-medium mb-1 uppercase">Comisión Agente</p>
                                    <p class="text-2xl font-bold text-green-900">{{ formatCurrency(policy.commission_amount) }}</p>
                                    <p class="text-xs text-green-700 mt-1 font-medium">{{ policy.commission_percentage }}% de la prima</p>
                                </div>
                                <div class="bg-purple-50/50 rounded-xl p-4 border border-purple-100">
                                    <p class="text-xs text-purple-600/80 font-medium mb-1 uppercase">Comisión Promotor</p>
                                    <p class="text-2xl font-bold text-purple-900">{{ formatCurrency(policy.promoter_commission_amount) }}</p>
                                    <p class="text-xs text-purple-700 mt-1 font-medium">{{ policy.promoter_commission_percentage }}% de la prima</p>
                                </div>
                            </div>

                            <div class="mt-4 bg-gray-50 rounded-xl p-4 border border-gray-100">
                                <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-3 flex items-center">
                                    <Receipt class="w-4 h-4 mr-2" /> Deducciones y Retenciones
                                </h4>
                                <div class="flex justify-between items-center py-2 border-b border-gray-200">
                                    <span class="text-sm text-gray-600">Retención ISR</span>
                                    <span class="text-sm font-medium text-gray-900">{{ policy.isr_retention }}%</span>
                                </div>
                                <div class="flex justify-between items-center py-2">
                                    <span class="text-sm text-gray-600">Costo de Facturación</span>
                                    <span class="text-sm font-medium text-gray-900">{{ policy.billing_retention }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Resumen Financiero -->
                <div class="border-t border-gray-100 bg-gradient-to-r from-gray-50 to-white">
                    <div class="p-6">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4 flex items-center">
                            <Calculator class="w-4 h-4 mr-2" /> Resumen Financiero
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Desglose -->
                            <div class="space-y-0">
                                <div class="flex justify-between items-center py-1">
                                    <span class="text-sm text-gray-600">Prima Total</span>
                                    <span class="text-sm font-semibold text-gray-900">{{ formatCurrency(summary.premium) }}</span>
                                </div>

                                <!-- ── Agente ─────────────────────────────── -->
                                <div class="flex justify-between items-center py-1 border-t border-gray-200">
                                    <span class="text-sm text-gray-600">− Comisión Agente ({{ policy.commission_percentage }}%)</span>
                                    <span class="text-sm font-medium text-red-600">− {{ formatCurrency(summary.agentComm) }}</span>
                                </div>
                                <div class="flex justify-between items-center py-0.5 pl-4">
                                    <span class="text-xs text-gray-400">− ISR Agente ({{ summary.isrPct }}%)</span>
                                    <span class="text-xs font-medium text-red-500">− {{ formatCurrency(summary.isrAgentAmount) }}</span>
                                </div>
                                <div class="flex justify-between items-center py-0.5 pl-4">
                                    <span class="text-xs text-gray-400">− Facturación Agente ({{ summary.billingPct }}%)</span>
                                    <span class="text-xs font-medium text-red-500">− {{ formatCurrency(summary.billingAgentAmount) }}</span>
                                </div>
                                <div class="flex justify-between items-center py-0.5 pl-4 border-b border-gray-100">
                                    <span class="text-xs font-medium text-green-700">Subtotal Neto Agente</span>
                                    <span class="text-xs font-bold text-green-700">{{ formatCurrency(summary.agentNetComm) }}</span>
                                </div>

                                <!-- ── Promotor ──────────────────────────── -->
                                <div class="flex justify-between items-center py-1">
                                    <span class="text-sm text-gray-600">− Comisión Promotor ({{ policy.promoter_commission_percentage }}%)</span>
                                    <span class="text-sm font-medium text-red-600">− {{ formatCurrency(summary.promoterComm) }}</span>
                                </div>
                                <div class="flex justify-between items-center py-0.5 pl-4">
                                    <span class="text-xs text-gray-400">− ISR Promotor ({{ summary.isrPct }}%)</span>
                                    <span class="text-xs font-medium text-red-500">− {{ formatCurrency(summary.isrPromoterAmount) }}</span>
                                </div>
                                <div class="flex justify-between items-center py-0.5 pl-4">
                                    <span class="text-xs text-gray-400">− Facturación Promotor ({{ summary.billingPct }}%)</span>
                                    <span class="text-xs font-medium text-red-500">− {{ formatCurrency(summary.billingPromoterAmount) }}</span>
                                </div>
                                <div class="flex justify-between items-center py-0.5 pl-4 border-b border-gray-100">
                                    <span class="text-xs font-medium text-green-700">Subtotal Neto Promotor</span>
                                    <span class="text-xs font-bold text-green-700">{{ formatCurrency(summary.promoterNetComm) }}</span>
                                </div>

                                <!-- Totales -->
                                <div class="flex justify-between items-center py-1 border-t border-gray-200 mt-1">
                                    <span class="text-sm font-bold text-gray-800">Total Deducciones</span>
                                    <span class="text-sm font-bold text-orange-600">{{ formatCurrency(summary.totalDeductions) }}</span>
                                </div>
                            </div>
                            <!-- Resultado Neto -->
                            <div class="flex flex-col items-center justify-center bg-green-50/50 rounded-xl border border-green-100 p-6">
                                <p class="text-xs text-green-600/80 font-medium mb-1 uppercase">Monto Neto</p>
                                <p class="text-3xl font-bold text-green-700">{{ formatCurrency(summary.netAmount) }}</p>
                                <p class="text-xs text-green-600/70 mt-2">Suma de comisiones netas (agente + promotor)</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>