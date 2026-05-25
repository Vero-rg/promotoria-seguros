<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    policy: {
        type: Object,
        default: () => ({
            agent_id: '',
            policy_number: '',
            issue_date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().split('T')[0],
            premium_amount: 0,
            commission_percentage: 0,
            commission_amount: 0,
            isr_retention: 10.00,
            billing_retention: 5.00,
            status: 'activa',
        }),
    },
    agents: Array,
    isEdit: Boolean,
});

const form = useForm({
    agent_id: props.policy.agent_id,
    policy_number: props.policy.policy_number,
    issue_date: props.policy.issue_date,
    premium_amount: props.policy.premium_amount,
    commission_percentage: props.policy.commission_percentage,
    commission_amount: props.policy.commission_amount,
    isr_retention: props.policy.isr_retention,
    billing_retention: props.policy.billing_retention,
    status: props.policy.status,
});

// Autocalcular monto de comisión si cambia la prima o el porcentaje
watch([() => form.premium_amount, () => form.commission_percentage], ([premium, percentage]) => {
    if (premium && percentage) {
        form.commission_amount = (premium * (percentage / 100)).toFixed(2);
    }
});

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
            <!-- Información Básica -->
            <div class="space-y-4 md:col-span-2 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 border-b pb-2">Información de la Póliza</h3>
                
                <div>
                    <label for="policy_number" class="block text-sm font-medium text-gray-700 mb-1">Número de Póliza</label>
                    <el-input id="policy_number" v-model="form.policy_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm" required/>
                    <div v-if="form.errors.policy_number" class="text-red-500 text-xs mt-1">{{ form.errors.policy_number }}</div>
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
                                <el-option value="Cancelada">Cancelada</el-option>
                                <el-option value="Pagada">Pagada</el-option>  
                        </el-select>
                        <div v-if="form.errors.status" class="text-red-500 text-xs mt-1">{{ form.errors.status }}</div>
                    </div>
                </div>
            </div>

            <!-- Valores Financieros -->
            <div class="space-y-4 md:col-span-2 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 border-b pb-2">Valores y Comisiones</h3>
                
                <div>
                    <label for="premium_amount" class="block text-sm font-medium text-gray-700 mb-1">Prima Total ($)</label>
                    <el-input-number id="premium_amount" v-model="form.premium_amount" step="0.01" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm" required/>
                    <div v-if="form.errors.premium_amount" class="text-red-500 text-xs mt-1">{{ form.errors.premium_amount }}</div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="commission_percentage" class="block text-sm font-medium text-gray-700 mb-1">% Comisión</label>
                        <el-input-number id="commission_percentage" v-model="form.commission_percentage" step="0.01" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm"/>
                        <div v-if="form.errors.commission_percentage" class="text-red-500 text-xs mt-1">{{ form.errors.commission_percentage }}</div>
                    </div>
                    <div>
                        <label for="commission_amount" class="block text-sm font-medium text-gray-700 mb-1">Monto Comisión ($)</label>
                        <el-input-number id="commission_amount" v-model="form.commission_amount" step="0.01" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm bg-gray-50" readonly/>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 pt-2">
                    <div>
                        <label for="isr_retention" class="block text-sm font-medium text-gray-700 mb-1">% Retención ISR</label>
                        <el-input-number id="isr_retention" v-model="form.isr_retention" step="0.01" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm"/>
                    </div>
                    <div>
                        <label for="billing_retention" class="block text-sm font-medium text-gray-700 mb-1">% Costo Facturación</label>
                        <el-input-number id="billing_retention" v-model="form.billing_retention" step="0.01" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm"/>
                    </div>
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