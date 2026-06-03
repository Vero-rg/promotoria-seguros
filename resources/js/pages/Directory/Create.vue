<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Camera, Upload, User, CalendarDays, Briefcase } from 'lucide-vue-next';

const props = defineProps({
    promoters: Array,
});

const form = useForm({
    type: 'agent', // 'promoter' o 'agent'
    name: '',
    promoter_id: '',
    photo: null,
    is_active: true,
    entry_date: new Date().toISOString().split('T')[0],
});

const photoPreview = ref(null);
const fileInput = ref(null);

const handlePhotoChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        form.photo = file;
        const reader = new FileReader();
        reader.onload = (ev) => {
            photoPreview.value = ev.target.result;
        };
        reader.readAsDataURL(file);
    }
};

const removePhoto = () => {
    form.photo = null;
    photoPreview.value = null;
};

const submit = () => {
    const routeName = form.type === 'promoter' ? 'promoters.store' : 'agents.store';
    form.post(route(routeName));
};

const breadcrumbs = [
    {
        title: 'Directorio',
        href: route('directorio'),
    },
    {
        title: `Registrar`,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Nuevo Registro" />
        <div class="max-w-3xl mx-auto p-4 sm:p-6 lg:p-8 transition-all duration-300">
            <div class="bg-white rounded-[20px] shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-gray-100 overflow-hidden">
                
                <!-- Encabezado -->
                <div class="px-8 py-6 border-b border-gray-100/80 bg-gray-50/30">
                    <h1 class="text-xl font-semibold text-gray-900 tracking-tight">Registrar en Directorio</h1>
                    <p class="text-sm text-gray-500 mt-1">Añade un nuevo miembro al equipo configurando su perfil inicial.</p>
                </div>

                <form @submit.prevent="submit" class="p-8 space-y-8">
                    
                    <!-- Tipo de Registro (Segmented Control Estilo Linear) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Rol del usuario</label>
                        <div class="inline-flex p-1 bg-gray-100/80 rounded-xl border border-gray-200/50">
                            <button 
                                type="button" 
                                @click="form.type = 'agent'" 
                                :class="form.type === 'agent' ? 'bg-white shadow-sm text-gray-900 ring-1 ring-gray-200/50' : 'text-gray-500 hover:text-gray-700'" 
                                class="flex items-center px-6 py-2 rounded-lg text-sm font-medium transition-all duration-200"
                            >
                                <User class="w-4 h-4 mr-2" /> Agente
                            </button>
                            <button 
                                type="button" 
                                @click="form.type = 'promoter'" 
                                :class="form.type === 'promoter' ? 'bg-white shadow-sm text-gray-900 ring-1 ring-gray-200/50' : 'text-gray-500 hover:text-gray-700'" 
                                class="flex items-center px-6 py-2 rounded-lg text-sm font-medium transition-all duration-200"
                            >
                                <Briefcase class="w-4 h-4 mr-2" /> Promotor
                            </button>
                        </div>
                    </div>

                    <!-- Foto de Perfil -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Foto de Perfil</label>
                        <div class="flex items-center space-x-6">
                            <div class="flex-shrink-0 relative group cursor-pointer">
                                <div v-if="photoPreview" class="relative">
                                    <img :src="photoPreview" class="w-20 h-20 rounded-full object-cover shadow-sm border border-gray-100" />
                                    <button type="button" @click="removePhoto" class="absolute -top-1 -right-1 w-6 h-6 bg-white border border-gray-200 text-gray-500 hover:text-red-500 rounded-full flex items-center justify-center text-sm shadow-sm transition-colors z-10">&times;</button>
                                </div>
                                <div v-else class="w-20 h-20 rounded-full bg-gray-50 flex items-center justify-center border border-dashed border-gray-300 group-hover:border-gray-400 group-hover:bg-gray-100 transition-colors cursor-pointer" @click="fileInput?.click()">
                                    <Camera class="w-6 h-6 text-gray-400 group-hover:text-gray-500 transition-colors" />
                                </div>
                            </div>
                            <div class="flex-1">
                                <label class="inline-flex items-center px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 hover:border-gray-300 shadow-sm transition-all cursor-pointer">
                                    <Upload class="w-4 h-4 mr-2 text-gray-500" />
                                    Seleccionar imagen
                                    <input type="file" accept="image/*" class="hidden" @change="handlePhotoChange" ref="fileInput" />
                                </label>
                                <p class="text-[13px] text-gray-400 mt-2">JPG, PNG o WebP. Tamaño máximo de 2 MB.</p>
                                <div v-if="form.errors.photo" class="text-red-500 text-xs mt-1">{{ form.errors.photo }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Cuadrícula Dinámica -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        
                        <!-- Nombre Completo -->
                        <div class="space-y-1.5">
                            <label for="name" class="block text-sm font-medium text-gray-700">Nombre Completo</label>
                            <el-input id="name" v-model="form.name" type="text" placeholder="Ej. Juan Pérez" class="w-full linear-input" required/>
                            <div v-if="form.errors.name" class="text-red-500 text-xs">{{ form.errors.name }}</div>
                        </div>

                        <!-- Fecha de Ingreso -->
                        <div class="space-y-1.5">
                            <label for="large" class="text-sm font-medium text-gray-700">Fecha de Ingreso</label>
                            <el-date-picker
                                v-model="form.entry_date"
                                type="date"
                                value-format="YYYY-MM-DD"
                                format="DD/MM/YYYY"
                                placeholder="Selecciona una fecha"
                                class="w-full linear-input"
                                style="width: 100%;"
                                :clearable="false"
                            >
                                <template #prefix>
                                    <CalendarDays class="w-4 h-4 text-gray-400" />
                                </template>
                            </el-date-picker>
                            <div v-if="form.errors.entry_date" class="text-red-500 text-xs">{{ form.errors.entry_date }}</div>
                        </div>
                    </div>

                    <!-- Promotor Asignado (Solo Agentes) -->
                    <div v-if="form.type === 'agent'" class="space-y-1.5">
                        <label for="promoter_id" class="block text-sm font-medium text-gray-700">Promotor Asignado <span class="text-gray-400 font-normal">(Opcional)</span></label>
                        <el-select id="promoter_id" v-model="form.promoter_id" placeholder="Seleccionar promotor" class="w-full linear-input">
                            <el-option value="" label="Sin promotor asignado">Ninguno</el-option>
                            <el-option v-for="promoter in promoters" :key="promoter.id" :value="promoter.id" :label="promoter.name">
                                {{ promoter.name }}
                            </el-option>
                        </el-select>
                        <div v-if="form.errors.promoter_id" class="text-red-500 text-xs">{{ form.errors.promoter_id }}</div>
                    </div>

                    <!-- Estado Activo / Inactivo -->
                    <div class="pt-2">
                        <label class="block text-sm font-medium text-gray-700 mb-3">Estado de la cuenta</label>
                        <div class="inline-flex items-center space-x-2 bg-gray-50/80 p-1.5 rounded-xl border border-gray-100">
                            <button type="button" @click="form.is_active = true" :class="form.is_active ? 'bg-white text-green-600 shadow-sm ring-1 ring-green-200/50' : 'text-gray-500 hover:text-gray-700'" class="px-5 py-1.5 rounded-lg text-sm font-medium transition-all">
                                Activo
                            </button>
                            <button type="button" @click="form.is_active = false" :class="!form.is_active ? 'bg-white text-red-500 shadow-sm ring-1 ring-red-200/50' : 'text-gray-500 hover:text-gray-700'" class="px-5 py-1.5 rounded-lg text-sm font-medium transition-all">
                                Inactivo
                            </button>
                        </div>
                    </div>

                    <!-- Acciones -->
                    <div class="flex items-center justify-end pt-6 border-t border-gray-100">
                        <button type="button" @click="() => window.history.back()" class="px-5 py-2.5 text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors mr-4">
                            Cancelar
                        </button>
                        <button type="submit" :disabled="form.processing" class="px-6 py-2.5 bg-[#0f0f0f] text-white text-sm font-medium rounded-xl hover:bg-black shadow-[0_4px_10px_rgba(0,0,0,0.1)] hover:shadow-[0_4px_14px_rgba(0,0,0,0.15)] disabled:opacity-50 transition-all">
                            Guardar Registro
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Estilos para sobreescribir Element Plus a un look "Linear" */
:deep(.el-input__wrapper), :deep(.el-select__wrapper) {
    border-radius: 0.5rem;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.07), 0 1px 3px rgba(0, 0, 0, 0.06) !important;
    padding: 0.25rem 0.75rem;
    transition: all 0.2s ease;
}
:deep(.el-input__wrapper.is-focus), :deep(.el-select__wrapper.is-focus) {
    box-shadow: 0 0 0 1px #0f0f0f !important;
}
</style>