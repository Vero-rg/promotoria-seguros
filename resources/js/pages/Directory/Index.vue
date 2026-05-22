<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Users, UserCircle, Plus, Search, Edit, Trash2 } from 'lucide-vue-next';
import { ElMessage, ElMessageBox } from 'element-plus';

const props = defineProps({
    directory: Array,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const type = ref(props.filters?.type || '');
const date = ref(props.filters?.date || '');

const fetchDirectory = () => {
    router.get(route('directorio'), {
        search: search.value,
        type: type.value,
        date: date.value
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true
    });
};

let timeout;
watch([search, type, date], () => {
    clearTimeout(timeout);
    timeout = setTimeout(fetchDirectory, 300);
});

const handleRowClick = (row) => {
    const routeName = row.type === 'promoter' ? 'promoters.show' : 'agents.show';
    router.get(route(routeName, row.id));
};

const handleEdit = (row) => {
    const routeName = row.type === 'promoter' ? 'promoters.edit' : 'agents.edit';
    router.get(route(routeName, row.id));
};

// Lógica para ordenar P1, A1 numéricamente y por grupos en Element Plus
const sortById = (a, b) => {
    if (a.type !== b.type) {
        return a.type.localeCompare(b.type);
    }
    return a.id - b.id;
};

const handleDelete = (row) => {
    ElMessageBox.confirm(
        `¿Estás seguro de eliminar a este ${row.type === 'promoter' ? 'promotor' : 'agente'}?`,
        'Confirmar eliminación',
        { confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', type: 'warning' }
    ).then(() => {
        const routeName = row.type === 'promoter' ? 'promoters.destroy' : 'agents.destroy';
        router.delete(route(routeName, row.id), {
            onSuccess: () => ElMessage({ type: 'success', message: 'Eliminado correctamente' })
        });
    }).catch(() => {});
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Directorio" />

        <div class="w-full p-4 sm:p-6 lg:p-8">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">Directorio</h1>
                <Link :href="route('promoters.create')" class="inline-flex items-center px-4 py-2 bg-black text-white text-sm font-medium rounded-lg hover:bg-gray-800 transition-colors">
                    <Plus class="w-4 h-4 mr-2" />
                    Nuevo Registro
                </Link>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <!-- Controles de Filtro -->
                <div class="p-4 border-b border-gray-100 bg-gray-50/50 flex flex-col sm:flex-row gap-4 items-center justify-between">
                    <div class="flex flex-1 gap-4 items-center w-full">
                        <el-input v-model="search" placeholder="Buscar por nombre o ID" clearable class="w-full sm:max-w-xs">
                            <template #prefix><Search class="w-4 h-4 text-gray-400" /></template>
                        </el-input>

                        <el-select v-model="type" placeholder="Todos los tipos" clearable class="w-full sm:max-w-[150px]">
                            <el-option label="Todos" value="" />
                            <el-option label="Promotores" value="promoter" />
                            <el-option label="Agentes" value="agent" />
                        </el-select>

                        <el-date-picker v-model="date" type="date" placeholder="Fecha de creación" format="DD/MM/YYYY" value-format="YYYY-MM-DD" clearable class="w-full sm:max-w-[180px]" />
                    </div>
                </div>

                <!-- Tabla Unificada -->
                <el-table :data="directory" style="width: 100%" row-key="uid" empty-text="No se encontraron registros." @row-click="handleRowClick" row-class-name="cursor-pointer">
                    
                    <!-- Columna de Expansión (Aparece a la izquierda por estar de primero) -->
                    <el-table-column type="expand">
                        <template #default="{ row }">
                            <div class="p-6 bg-gray-50/80 border-y border-gray-100" @click.stop>
                                <div v-if="row.type === 'promoter'">
                                    <div v-if="row.agents && row.agents.length > 0">
                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center">
                                            <Users class="w-4 h-4 mr-2 text-gray-500" />
                                            Agentes a cargo ({{ row.agents.length }})
                                        </h4>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                            <div v-for="agent in row.agents" :key="agent.id" @click.stop="handleRowClick({ type: 'agent', id: agent.id })" class="flex items-center p-3 bg-white border border-gray-200 rounded-lg shadow-sm hover:border-blue-400 hover:shadow-md transition-all cursor-pointer">
                                                <div class="flex-shrink-0 mr-3">
                                                    <div class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-blue-600 font-bold text-xs">
                                                        A{{ agent.id }}
                                                    </div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-900 truncate">{{ agent.name }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="text-center text-gray-500 text-sm py-2">
                                        Este promotor no tiene agentes asignados.
                                    </div>
                                </div>
                                <div v-else class="text-center text-gray-500 text-sm py-2 flex items-center justify-center">
                                    <UserCircle class="w-4 h-4 mr-2 text-gray-400" />
                                    <span>Información de agente. Promotor asignado: <span class="font-medium text-gray-900">{{ row.promoter ? row.promoter.name : 'Ninguno' }}</span></span>
                                </div>
                            </div>
                        </template>
                    </el-table-column>

                    <el-table-column prop="display_id" label="ID" width="100" sortable :sort-method="sortById" />
                    <el-table-column prop="name" label="Nombre" min-width="200" />
                    <el-table-column label="Tipo" width="120">
                        <template #default="{ row }">
                            <el-tag :type="row.type === 'promoter' ? 'primary' : 'success'">
                                {{ row.type === 'promoter' ? 'Promotor' : 'Agente' }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="Detalles" min-width="250">
                        <template #default="{ row }">
                            <div v-if="row.type === 'promoter'" class="flex items-center text-sm text-gray-600">
                                <UserCircle class="w-4 h-4 mr-1 text-gray-400" />
                                {{ row.agents ? row.agents.length : 0 }} agentes a cargo
                            </div>
                            <div v-else class="flex items-center text-sm text-gray-600">
                                <Users class="w-4 h-4 mr-1 text-gray-400" />
                                Promotor: <span class="font-medium ml-1">{{ row.promoter ? row.promoter.name : 'Sin asignar' }}</span>
                            </div>
                        </template>
                    </el-table-column>
                    <el-table-column label="Creación" width="150">
                        <template #default="{ row }">
                            <span class="text-sm text-gray-600">{{ new Date(row.created_at).toLocaleDateString('es-MX') }}</span>
                        </template>
                    </el-table-column>
                    <el-table-column label="Acciones" width="150" align="right" fixed="right">
                        <template #default="{ row }">
                            <div class="flex items-center justify-end gap-2">
                                <button @click.stop="handleEdit(row)" class="p-2 text-gray-500 hover:text-blue-600 transition-colors cursor-pointer" title="Editar">
                                    <Edit class="w-4 h-4" />
                                </button>
                                <button @click.stop="handleDelete(row)" class="p-2 text-gray-500 hover:text-red-600 transition-colors cursor-pointer" title="Eliminar">
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </template>
                    </el-table-column>
                </el-table>
            </div>
        </div>
    </AppLayout>    
</template>