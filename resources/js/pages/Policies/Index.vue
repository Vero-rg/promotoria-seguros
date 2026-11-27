<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, FileText, Search, Edit, Trash2, MoreHorizontal, Upload, Download } from 'lucide-vue-next';
import { ElMessage, ElMessageBox } from 'element-plus';

const STATUS_OPTIONS = ['Activa', 'No tomada', 'Pagada'];

const IMPORT_COLUMNS = [
    { label: 'Número de póliza', required: true, example: '878901' },
    { label: 'Cliente', required: false, example: 'Juan Pérez' },
    { label: 'Producto', required: true, example: 'Selecciona de la lista' },
    { label: 'Agente', required: true, example: 'Selecciona de la lista' },
    { label: 'Fecha de emisión', required: true, example: '15/07/2026' },
    { label: 'PNA', required: true, example: '90000' },
    { label: '% Impuesto cedular', required: false, example: '0' },
    { label: '% Costo facturación', required: true, example: '5' },
    { label: 'Estatus', required: false, example: 'Selecciona de la lista' },
    { label: 'Conducto de pago', required: false, example: 'Selecciona de la lista' },
];

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
    'no tomada': 'bg-gray-100 text-gray-600',
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

// ---------- Importar pólizas ----------

const page = usePage();
const uploadRef = ref(null);
const importDialogVisible = ref(false);
const importResult = ref(null);
const importForm = useForm({ file: null });

const openImportDialog = () => {
    importResult.value = null;
    importForm.reset();
    importForm.clearErrors();
    importDialogVisible.value = true;
};

const handleFileChange = (uploadFile) => {
    importForm.file = uploadFile.raw ?? null;
    importResult.value = null;
    importForm.clearErrors('file');
};

const handleFileRemove = () => {
    importForm.file = null;
};

const handleFileExceed = (files) => {
    uploadRef.value?.clearFiles();
    if (files[0]) {
        uploadRef.value?.handleStart(files[0]);
    }
};

const submitImport = () => {
    importForm.post(route('policies.import'), {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            importResult.value = page.props.flash?.import_result ?? null;
            uploadRef.value?.clearFiles();
            importForm.reset();
        },
    });
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
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        @click="openImportDialog"
                        class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        <Upload class="w-4 h-4 mr-2" />
                        Importar pólizas
                    </button>
                    <Link :href="route('policies.create')" class="inline-flex items-center px-4 py-2 bg-black text-white text-sm font-medium rounded-lg hover:bg-gray-800 transition-colors">
                        <Plus class="w-4 h-4 mr-2" />
                        Nueva Póliza
                    </Link>
                </div>
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
                           <el-option label="No tomada" value="No tomada" />
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
                   <el-table-column prop="product_type" label="Producto" min-width="130" sortable>
                       <template #default="{ row }">
                           {{ row.product_type || '—' }}
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
                   <el-table-column prop="premium_amount" label="PNA" width="120" sortable>
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

        <el-dialog v-model="importDialogVisible" title="Importar pólizas desde Excel" width="760px" destroy-on-close>
            <div class="space-y-4">
                <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    Descarga la plantilla, captura una póliza por fila y súbela aquí. Las comisiones de agente y
                    promotor se calculan automáticamente con base en el PCA (PNA − $1,350) y la retención de ISR
                    se aplica siempre al 10%. En las columnas Producto, Agente, Estatus y Conducto de pago elige
                    el valor de la lista desplegable.
                </div>

                <a
                    :href="route('policies.import.template')"
                    class="inline-flex items-center gap-2 text-sm font-medium text-blue-700 hover:underline"
                >
                    <Download class="w-4 h-4" />
                    Descargar plantilla de Excel
                </a>

                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">Columna</th>
                                <th class="px-3 py-2 font-medium">Obligatoria</th>
                                <th class="px-3 py-2 font-medium">Ejemplo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="column in IMPORT_COLUMNS" :key="column.label">
                                <td class="px-3 py-1.5 text-gray-700">{{ column.label }}</td>
                                <td class="px-3 py-1.5" :class="column.required ? 'text-red-600 font-medium' : 'text-gray-400'">
                                    {{ column.required ? 'Sí' : 'No' }}
                                </td>
                                <td class="px-3 py-1.5 text-gray-500">{{ column.example }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <el-upload
                    ref="uploadRef"
                    drag
                    accept=".xlsx,.xls,.csv"
                    :auto-upload="false"
                    :limit="1"
                    :on-change="handleFileChange"
                    :on-remove="handleFileRemove"
                    :on-exceed="handleFileExceed"
                >
                    <div class="py-2 text-center">
                        <Upload class="mx-auto mb-2 w-6 h-6 text-gray-400" />
                        <p class="text-sm text-gray-600">Arrastra el archivo aquí o haz clic para seleccionarlo</p>
                        <p class="mt-1 text-xs text-gray-400">Formatos: .xlsx, .xls o .csv (máximo 10 MB)</p>
                    </div>
                </el-upload>
                <p v-if="importForm.errors.file" class="text-xs text-red-500">{{ importForm.errors.file }}</p>

                <div
                    v-if="importResult"
                    class="rounded-lg border px-4 py-3 text-sm"
                    :class="importResult.errors?.length ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-green-200 bg-green-50 text-green-800'"
                >
                    <p class="font-medium">{{ importResult.imported }} póliza(s) importada(s).</p>
                    <ul v-if="importResult.errors?.length" class="mt-2 list-disc space-y-1 pl-5">
                        <li v-for="(error, index) in importResult.errors" :key="index">{{ error }}</li>
                    </ul>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <el-button @click="importDialogVisible = false">Cerrar</el-button>
                    <el-button
                        type="primary"
                        :loading="importForm.processing"
                        :disabled="!importForm.file"
                        @click="submitImport"
                    >
                        Importar
                    </el-button>
                </div>
            </template>
        </el-dialog>
    </AppLayout>    
</template>