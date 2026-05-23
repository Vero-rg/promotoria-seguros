<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    code: '',
    type: 'commission',
    target: 'both',
    is_active: true,
    version_name: 'Tabulador Inicial',
    starts_at: new Date().toISOString().split('T')[0],
    ends_at: '', // Nueva propiedad opcional
    tiers: [
        { product_type: 'METLIFE', agent_percentage: undefined, promoter_percentage: undefined },
        { product_type: 'PERFECTLIFE', agent_percentage: undefined, promoter_percentage: undefined },
        { product_type: 'PRIMORDIAL', agent_percentage: undefined, promoter_percentage: undefined }
    ]
});

const addTier = () => {
    form.tiers.push({ product_type: '', agent_percentage: undefined, promoter_percentage: undefined });
};

const removeTier = (index: number) => {
    form.tiers.splice(index, 1);
};

const submit = () => {
    form.post('/schemes');
};
</script>

<template>
    <AppLayout>
        <Head title="Nueva Comisión" />

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm sm:rounded-lg border border-gray-100 p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-semibold">Crear Esquema de Comisión</h2>
                        <Link href="/esquemas/partials/comissions" class="text-gray-500 hover:text-gray-700 text-sm">
                            &larr; Volver
                        </Link>
                    </div>

                    <form @submit.prevent="submit" class="space-y-8">
                        <!-- Datos del Esquema -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">1. Datos Generales</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Esquema</label>
                                    <el-input v-model="form.name" placeholder="Ej. Comisiones Venta Directa" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Código Interno</label>
                                    <el-input v-model="form.code" placeholder="Ej. sales_commission" required />
                                </div>
                                <div class="flex items-center mt-6">
                                    <el-checkbox v-model="form.is_active" label="Activar inmediatamente" size="large" />
                                </div>
                            </div>
                        </div>

                        <!-- Datos de la Versión -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 border-b pb-2 mb-4">2. Vigencia</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de la Versión</label>
                                    <el-input v-model="form.version_name" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de Inicio</label>
                                    <el-date-picker 
                                        v-model="form.starts_at" 
                                        type="date" 
                                        value-format="YYYY-MM-DD" 
                                        style="width: 100%;" 
                                        required 
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de Término (Opcional)</label>
                                    <el-date-picker 
                                        v-model="form.ends_at" 
                                        type="date" 
                                        value-format="YYYY-MM-DD" 
                                        style="width: 100%;" 
                                        placeholder="Sin fecha límite"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Condiciones y Porcentajes -->
                        <div>
                            <div class="flex justify-between items-center border-b pb-2 mb-4">
                                <h3 class="text-lg font-medium text-gray-900">3. Porcentajes por Producto</h3>
                                <el-button type="primary" plain size="small" @click="addTier">
                                    + Agregar Producto
                                </el-button>
                            </div>

                            <div class="space-y-4">
                                <div v-for="(tier, index) in form.tiers" :key="index" class="flex flex-wrap md:flex-nowrap items-end gap-4 p-4 border rounded-md bg-gray-50">
                                    <div class="flex-1 min-w-[200px]">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Producto</label>
                                        <el-input v-model="tier.product_type" placeholder="Ej. METLIFE" required />
                                    </div>
                                    <div class="flex-1 min-w-[150px]">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Comisión Agente (%)</label>
                                        <el-input-number 
                                            v-model="tier.agent_percentage" 
                                            :min="0" 
                                            :max="100" 
                                            :step="0.01" 
                                            :precision="2" 
                                            style="width: 100%;" 
                                            placeholder="Ej. 56" 
                                            required 
                                        />
                                    </div>
                                    <div class="flex-1 min-w-[150px]">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Comisión Promotor (%)</label>
                                        <el-input-number 
                                            v-model="tier.promoter_percentage" 
                                            :min="0" 
                                            :max="100" 
                                            :step="0.01" 
                                            :precision="2" 
                                            style="width: 100%;" 
                                            placeholder="Ej. 14" 
                                            required 
                                        />
                                    </div>
                                    <el-button type="danger" plain @click="removeTier(index)" class="mt-2 md:mt-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                        </svg>
                                    </el-button>
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="flex justify-end pt-4 border-t">
                            <el-button native-type="submit" color="#000" :loading="form.processing" size="large">
                                Guardar Comisión
                            </el-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>