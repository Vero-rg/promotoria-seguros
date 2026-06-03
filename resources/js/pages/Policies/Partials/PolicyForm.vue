<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch, computed } from 'vue';

const props = defineProps({
    policy: {
        type: Object,
        default: () => ({
            agent_id: '',
            policy_number: '',
            client_name: '',
            issue_date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().split('T')[0],
            premium_amount: 0,
            commission_percentage: 0,
            commission_amount: 0,
            promoter_commission_percentage: 0,
            promoter_commission_amount: 0,
            isr_retention: 10.00,
            billing_retention: 5.00,
            status: 'activa',
            product_type: '',
        }),
    },
    agents: Array,
    productCommissionMap: Object,
    isEdit: Boolean,
});

const form = useForm({
    agent_id: props.policy.agent_id,
    policy_number: props.policy.policy_number,
    client_name: props.policy.client_name || '',
    issue_date: props.policy.issue_date,
    premium_amount: props.policy.premium_amount,
    commission_percentage: props.policy.commission_percentage,
    commission_amount: props.policy.commission_amount,
    promoter_commission_percentage: props.policy.promoter_commission_percentage,
    promoter_commission_amount: props.policy.promoter_commission_amount,
    isr_retention: props.policy.isr_retention,
    billing_retention: props.policy.billing_retention,
    status: props.policy.status,
    product_type: props.policy.product_type || '',
});

// Productos disponibles ordenados alfabéticamente
const productOptions = computed(() => {
    if (!props.productCommissionMap) return [];
    return Object.keys(props.productCommissionMap).sort();
});

// Cuando se selecciona un producto, auto-asignar comisiones de agente y promotor
watch(() => form.product_type, (productType) => {
    if (!productType || !props.productCommissionMap?.[productType]) {
        form.commission_percentage = 0;
        form.commission_amount = 0;
        form.promoter_commission_percentage = 0;
        form.promoter_commission_amount = 0;
        return;
    }

    const map = props.productCommissionMap[productType];
    form.commission_percentage = Number(map.agent_percentage) || 0;
    form.promoter_commission_percentage = Number(map.promoter_percentage) || 0;

    // Recalcular montos si hay prima
    const premium = Number(form.premium_amount) || 0;
    if (premium > 0) {
        form.commission_amount = Number((premium * (Number(map.agent_percentage) / 100)).toFixed(2));
        form.promoter_commission_amount = Number((premium * (Number(map.promoter_percentage) / 100)).toFixed(2));
    }
});

// Recalcular montos al cambiar la prima o porcentajes manualmente
const recalcCommissions = () => {
    const premium = Number(form.premium_amount) || 0;
    if (premium <= 0) {
        form.commission_amount = 0;
        form.promoter_commission_amount = 0;
        return;
    }
    form.commission_amount = Number((premium * (Number(form.commission_percentage) / 100)).toFixed(2));
    form.promoter_commission_amount = Number((premium * (Number(form.promoter_commission_percentage) / 100)).toFixed(2));
};

watch(() => form.premium_amount, recalcCommissions);
watch(() => form.commission_percentage, recalcCommissions);
watch(() => form.promoter_commission_percentage, recalcCommissions);

// ---------- Resumen Financiero ----------

const summary = computed(() => {
    const premium = Number(form.premium_amount) || 0;
    const agentComm = Number(form.commission_amount) || 0;
    const promoterComm = Number(form.promoter_commission_amount) || 0;
    const isrPct = Number(form.isr_retention) || 0;
    const billingPct = Number(form.billing_retention) || 0;

    // ISR y facturación se calculan sobre cada comisión, no sobre la prima
    const isrAgentAmount = agentComm * (isrPct / 100);
    const billingAgentAmount = agentComm * (billingPct / 100);
    const agentNetComm = agentComm - isrAgentAmount - billingAgentAmount;

    const isrPromoterAmount = promoterComm * (isrPct / 100);
    const billingPromoterAmount = promoterComm * (billingPct / 100);
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

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount);
};

// ---------- Submit ----------

const submit = () => {
    if (props.isEdit) {
        form.put(route('policies.update', props.policy.id));
    } else {
        form.post(route('policies.store'));
    }
};
</script>

<template>
    <form @submit.prevent="submit" class="p-6 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Columna Izquierda: Información Básica -->
            <div class="space-y-4 md:col-span-2 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 border-b pb-2">Información de la Póliza</h3>
                
                <div>
                        <label for="product_type" class="block text-sm font-medium text-gray-700 mb-1">Selecciona el producto</label>
                        <el-select id="product_type" v-model="form.product_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm" placeholder="Elige un producto..." clearable>
                            <el-option value="" disabled>Selecciona un producto</el-option>
                            <el-option v-for="product in productOptions" :key="product" :value="product" :label="product" />
                        </el-select>
                        <div v-if="form.errors.product_type" class="text-red-500 text-xs mt-1">{{ form.errors.product_type }}</div>
                        <p v-if="!productOptions.length" class="text-xs text-amber-600 mt-1">
                            No hay productos configurados. Crea primero un esquema de comisión.
                        </p>
                    </div>

                    <!-- Badge informativo al seleccionar producto -->
                    <div v-if="form.product_type && props.productCommissionMap?.[form.product_type]" class="mt-3 p-3 bg-blue-50 border border-blue-100 rounded-lg">
                        <div class="flex items-center gap-3 text-sm">
                            <span class="font-medium text-blue-800">{{ form.product_type }}</span>
                            <span class="text-blue-600">·</span>
                            <span class="text-blue-700">Agente: <strong>{{ props.productCommissionMap[form.product_type].agent_percentage }}%</strong></span>
                            <span class="text-blue-600">·</span>
                            <span class="text-blue-700">Promotor: <strong>{{ props.productCommissionMap[form.product_type].promoter_percentage }}%</strong></span>
                        </div>
                    </div>

                <div>
                    <label for="policy_number" class="block text-sm font-medium text-gray-700 mb-1">Número de Póliza</label>
                    <el-input id="policy_number" v-model="form.policy_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm" required/>
                    <div v-if="form.errors.policy_number" class="text-red-500 text-xs mt-1">{{ form.errors.policy_number }}</div>
                </div>

                <div>
                    <label for="client_name" class="block text-sm font-medium text-gray-700 mb-1">Cliente</label>
                    <el-input id="client_name" v-model="form.client_name" type="text" placeholder="Nombre del cliente" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm"/>
                    <div v-if="form.errors.client_name" class="text-red-500 text-xs mt-1">{{ form.errors.client_name }}</div>
                </div>

                <div>
                    <label for="agent_id" class="block text-sm font-medium text-gray-700 mb-1">Agente Asignado</label>
                    <el-select id="agent_id" v-model="form.agent_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm" required>
                        <el-option value="" disabled>Selecciona un agente</el-option>
                        <el-option v-for="agent in agents" :key="agent.id" :value="agent.id" :label="agent.name" />
                    </el-select>
                    <div v-if="form.errors.agent_id" class="text-red-500 text-xs mt-1">{{ form.errors.agent_id }}</div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="issue_date" class="block text-sm font-medium text-gray-700 mb-1">Fecha de Emisión</label>
                        <el-date-picker id="issue_date" v-model="form.issue_date" type="date" value-format="YYYY-MM-DD" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm" required/>
                        <div v-if="form.errors.issue_date" class="text-red-500 text-xs mt-1">{{ form.errors.issue_date }}</div>
                    </div>
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Estatus</label>
                        <el-select id="status" v-model="form.status" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm">
                            <el-option value="Activa">Activa</el-option>
                            <el-option value="No tomada">No tomada</el-option>
                            <el-option value="Pagada">Pagada</el-option>  
                        </el-select>
                        <div v-if="form.errors.status" class="text-red-500 text-xs mt-1">{{ form.errors.status }}</div>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha: Valores Financieros + Resumen -->
            <div class="space-y-4 md:col-span-2 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 border-b pb-2">Valores y Comisiones</h3>
                
                <div>
                    <label for="premium_amount" class="block text-sm font-medium text-gray-700 mb-1">Prima Total ($)</label>
                    <el-input
                    :formatter="(value) => `$ ${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')"
                    :parser="(value) => value.replace(/\$\s?|(,*)/g, '')"
                    id="premium_amount" v-model="form.premium_amount" :min="0" :step="100" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm" required/>
                    <div v-if="form.errors.premium_amount" class="text-red-500 text-xs mt-1">{{ form.errors.premium_amount }}</div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="commission_percentage" class="block text-sm font-medium text-gray-700 mb-1">% Comisión Agente</label>
                        <el-input id="commission_percentage" v-model="form.commission_percentage" :min="0" :max="100" :step="0.1" :precision="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm"/>
                        <div v-if="form.errors.commission_percentage" class="text-red-500 text-xs mt-1">{{ form.errors.commission_percentage }}</div>
                    </div>
                    <div>
                        <label for="promoter_commission_percentage" class="block text-sm font-medium text-gray-700 mb-1">% Comisión Promotor</label>
                        <el-input id="promoter_commission_percentage" v-model="form.promoter_commission_percentage" :min="0" :max="100" :step="0.1" :precision="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm"/>
                        <div v-if="form.errors.promoter_commission_percentage" class="text-red-500 text-xs mt-1">{{ form.errors.promoter_commission_percentage }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 pt-2">
                    <div>
                        <label for="isr_retention" class="block text-sm font-medium text-gray-700 mb-1">% Retención ISR</label>
                        <el-input id="isr_retention" v-model="form.isr_retention" :min="0" :max="100" :step="0.5" :precision="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm"/>
                    </div>
                    <div>
                        <label for="billing_retention" class="block text-sm font-medium text-gray-700 mb-1">% Costo Facturación</label>
                        <el-input id="billing_retention" v-model="form.billing_retention" :min="0" :max="100" :step="0.5" :precision="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm"/>
                    </div>
                </div>

                <!-- Resumen Financiero -->
                <div v-if="summary.premium > 0" class="mt-6 bg-gray-50 rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-5 py-3 bg-gray-100 border-b border-gray-200">
                        <h4 class="text-sm font-semibold text-gray-800">Resumen Financiero</h4>
                    </div>
                    <div class="p-5 space-y-2">
                        <!-- Prima -->
                        <div class="flex justify-between items-center py-1">
                            <span class="text-sm text-gray-600">Prima Total</span>
                            <span class="text-sm font-semibold text-gray-900">{{ formatCurrency(summary.premium) }}</span>
                        </div>

                        <!-- ── Agente ─────────────────────────────── -->
                        <div class="flex justify-between items-center py-1 border-t border-gray-200">
                            <span class="text-sm text-gray-600">− Comisión Agente ({{ form.commission_percentage }}%)</span>
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
                            <span class="text-sm text-gray-600">− Comisión Promotor ({{ form.promoter_commission_percentage }}%)</span>
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
                        <div class="border-t border-gray-200 pt-3 mt-2">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-bold text-gray-800">Total Deducciones</span>
                                <span class="text-sm font-bold text-orange-600">{{ formatCurrency(summary.totalDeductions) }}</span>
                            </div>
                            <div class="flex justify-between items-center mt-2 pt-2 border-t-2 border-gray-300">
                                <span class="text-base font-bold text-gray-900">Monto Neto</span>
                                <span class="text-base font-bold text-green-600">{{ formatCurrency(summary.netAmount) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="mt-6 bg-gray-50 rounded-xl border border-gray-200 p-5 text-center">
                    <p class="text-sm text-gray-400">Ingresa la prima total para ver el resumen financiero.</p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end pt-6 border-t border-gray-100">
            <button type="submit" :disabled="form.processing" class="px-6 py-2 bg-black text-white text-sm font-medium rounded-lg hover:bg-gray-800 disabled:opacity-50 transition-colors">
                {{ isEdit ? 'Actualizar Póliza' : 'Guardar Póliza' }}
            </button>
        </div>
    </form>
</template>