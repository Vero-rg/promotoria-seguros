<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, FileText, Search, Edit, Trash2, MoreHorizontal } from 'lucide-vue-next';
import { ElMessage, ElMessageBox } from 'element-plus';

const STATUS_OPTIONS = ['Activa', 'Cancelada', 'Pagada'];

const props = defineProps({
    policies: Array,
    promoters: Array,
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount);
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-MX');
};

const statusColors = {
    activa: 'bg-green-100 text-green-800',
    cancelada: 'bg-red-100 text-red-800',
    pagada: 'bg-blue-100 text-blue-800',
};

const search = ref('');
const agentFilter = ref('');
const promoterFilter = ref('');
const statusFilter = ref('');

const uniqueAgents = computed(() => {
    const agents = [];
    const map = new Map();
    for (const item of props.policies) {
        if (item.agent && !map.has(item.agent.id)) {
            map.set(item.agent.id, true);
            agents.push(item.agent);
        }
    }
    return agents;
});

const filteredPolicies = computed(() => {
    return props.policies.filter(p => {
        const agentName = (p.agent?.name || '').toLowerCase();
        const promoterName = (p.agent?.promoter?.name || '').toLowerCase();
        const searchLower = search.value.toLowerCase();

        const matchesSearch = p.policy_number.toString().includes(search.value)
            || agentName.includes(searchLower)
            || promoterName.includes(searchLower);

        const matchesAgent = agentFilter.value === '' ? true : p.agent?.id === agentFilter.value;
        const matchesPromoter = promoterFilter.value === '' ? true : p.agent?.promoter?.id === promoterFilter.value;
        const matchesStatus = statusFilter.value === '' ? true : p.status === statusFilter.value;
        return matchesSearch && matchesAgent && matchesPromoter && matchesStatus;
    });
});

const handleStatusChange = (row, newStatus) => {
    router.patch(route('policies.status', row.id), { status: newStatus }, {
        preserveScroll: true,
        onSuccess: () => ElMessage({ type: 'success', message: `Estatus cambiado a "${newStatus}"` }),
        onError: () => ElMessage({ type: 'error', message: 'Error al cambiar estatus.' }),
    });
};

const handleRowClick = (row) => {
    router.get(route('policies.show', row.id));
};

const handleEdit = (row) => {
    router.get(route('policies.edit', row.id));
};

const handleDelete = (row) => {
    ElMessageBox.confirm(
        '¿Estás seguro de eliminar esta póliza?',
        'Confirmar eliminación',
        { confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', type: 'warning' }
    ).then(() => {
        router.delete(route('policies.destroy', row.id), {
            onSuccess: () => ElMessage({ type: 'success', message: 'Eliminada correctamente' })
        });
    }).catch(() => {});
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Pólizas" />

        <div class="max-w-7xl mx-200px p-4 sm:p-6 lg:p-8">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-semibold text-gray-900 tracking-tight flex items-center">
                    <FileText class="w-6 h-6 mr-2 text-gray-500" />
                    Pólizas
                </h1>
                <Link :href="route('policies.create')" class="inline-flex items-center px-4 py-2 bg-black text-white text-sm font-medium rounded-lg hover:bg-gray-800 transition-colors">
                    <Plus class="w-4 h-4 mr-2" />
                    Nueva Póliza
                </Link>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
               <div class="p-4 border-b border-gray-100 bg-gray-50/50 flex flex-col sm:flex-row gap-4 items-center justify-between">
                   <div class="flex flex-1 gap-4 items-center w-full">
                       <el-input v-model="search" placeholder="Buscar por número o agente" clearable class="w-full sm:max-w-xs">
                           <template #prefix><Search class="w-4 h-4 text-gray-400" /></template>
                       </el-input>

                       <el-select v-model="agentFilter" placeholder="Todos los agentes" clearable class="w-full sm:max-w-[200px]">
                           <el-option label="Todos" value="" />
                           <el-option v-for="agent in uniqueAgents" :key="agent.id" :label="agent.name" :value="agent.id" />
                       </el-select>

                       <el-select v-model="promoterFilter" placeholder="Todos los promotores" clearable class="w-full sm:max-w-[200px]">
                           <el-option label="Todos" value="" />
                           <el-option v-for="promoter in props.promoters" :key="promoter.id" :label="promoter.name" :value="promoter.id" />
                       </el-select>

                       <el-select v-model="statusFilter" placeholder="Todos los estatus" clearable class="w-full sm:max-w-[150px]">
                           <el-option label="Todos" value="" />
                           <el-option label="Activa" value="Activa" />
                           <el-option label="Cancelada" value="Cancelada" />
                           <el-option label="Pagada" value="Pagada" />
                       </el-select>
                   </div>
               </div>

               <el-table :data="filteredPolicies" style="width: 100%" empty-text="No hay pólizas registradas." @row-click="handleRowClick" row-class-name="cursor-pointer">
                   <el-table-column prop="policy_number" label="No. Póliza" min-width="120" sortable />
                   <el-table-column prop="client_name" label="Cliente" min-width="140" sortable>
                       <template #default="{ row }">
                           {{ row.client_name || '—' }}
                       </template>
                   </el-table-column>
                   <el-table-column prop="agent.name" label="Agente" min-width="160" sortable>
                       <template #default="{ row }">
                           {{ row.agent?.name || 'N/A' }}
                       </template>
                   </el-table-column>
                   <el-table-column prop="agent.promoter.name" label="Promotor" min-width="180" sortable>
                       <template #default="{ row }">
                           {{ row.agent?.promoter?.name || 'Sin asignar' }}
                       </template>
                   </el-table-column>
                   <el-table-column prop="issue_date" label="Emisión" width="120" sortable>
                       <template #default="{ row }">
                           {{ formatDate(row.issue_date) }}
                       </template>
                   </el-table-column>
                   <el-table-column prop="premium_amount" label="Prima" width="120" sortable>
                       <template #default="{ row }">
                           {{ formatCurrency(row.premium_amount) }}
                       </template>
                   </el-table-column>
                   <el-table-column prop="status" label="Estatus" width="120" sortable>
                       <template #default="{ row }">
                           <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full capitalize" :class="statusColors[row.status?.toLowerCase()] || 'bg-gray-100 text-gray-800'">
                               {{ row.status }}
                           </span>
                       </template>
                   </el-table-column>
                   <el-table-column label="Acciones" width="140" align="right" fixed="right">
                       <template #default="{ row }">
                           <div class="flex items-center justify-end gap-1">
                               <button @click.stop="handleEdit(row)" class="p-2 text-gray-500 hover:text-blue-600 transition-colors cursor-pointer" title="Editar">
                                   <Edit class="w-4 h-4" />
                               </button>
                               <button @click.stop="handleDelete(row)" class="p-2 text-gray-500 hover:text-red-600 transition-colors cursor-pointer" title="Eliminar">
                                   <Trash2 class="w-4 h-4" />
                               </button>
                               <el-dropdown trigger="click" @command="(cmd) => handleStatusChange(row, cmd)">
                                   <button @click.stop class="p-2 text-gray-500 hover:text-gray-700 transition-colors cursor-pointer" title="Más opciones">
                                       <MoreHorizontal class="w-4 h-4" />
                                   </button>
                                   <template #dropdown>
                                       <el-dropdown-menu>
                                           <el-dropdown-item 
                                               v-for="s in STATUS_OPTIONS.filter(s => s !== row.status)" 
                                               :key="s" 
                                               :command="s"
                                           >
                                               Cambiar a {{ s }}
                                           </el-dropdown-item>
                                       </el-dropdown-menu>
                                   </template>
                               </el-dropdown>
                           </div>
                       </template>
                   </el-table-column>
               </el-table>
           </div>
        </div>
    </AppLayout>    
</template>