<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Calendar } from 'lucide-vue-next';
import { Camera, Upload } from 'lucide-vue-next';

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
xl mx-auto
        <div class="max-w-2xl mx-200px p-4 sm:p-6 lg:p-8">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100">
                    <h1 class="text-xl font-semibold text-gray-900">Registrar en Directorio</h1>
                </div>

                <form @submit.prevent="submit" class="p-6 space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Registro</label>
                        <div class="flex space-x-4">
                            <label class="flex items-center p-3 border rounded-xl cursor-pointer hover:bg-gray-50 transition-colors" :class="{'bg-gray-50': form.type === 'agent'}">
                                <el-radio v-model="form.type" value="agent" class="text-black">Agente</el-radio>
                            </label>
                            <label class="flex items-center p-3 border rounded-xl cursor-pointer hover:bg-gray-50 transition-colors" :class="{'bg-gray-50': form.type === 'promoter'}">
                                <el-radio v-model="form.type" value="promoter" class="text-black">Promotor</el-radio>
                            </label>
                        </div>
                    </div>

                    <!-- Foto de Perfil -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Foto de Perfil</label>
                        <div class="flex items-start space-x-4">
                            <div class="flex-shrink-0">
                                <div v-if="photoPreview" class="relative">
                                    <img :src="photoPreview" class="w-24 h-24 rounded-xl object-cover border-2 border-gray-100" />
                                    <button type="button" @click="removePhoto" class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xs hover:bg-red-600 transition-colors">&times;</button>
                                </div>
                                <div v-else class="w-24 h-24 rounded-xl bg-gray-100 flex items-center justify-center border-2 border-dashed border-gray-300">
                                    <Camera class="w-8 h-8 text-gray-400" />
                                </div>
                            </div>
                            <div class="flex-1">
                                <label class="inline-flex items-center px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors cursor-pointer">
                                    <Upload class="w-4 h-4 mr-2" />
                                    Subir foto
                                    <input type="file" accept="image/*" class="hidden" @change="handlePhotoChange" />
                                </label>
                                <p class="text-xs text-gray-400 mt-1">JPG, PNG o WebP. Máx. 2 MB.</p>
                                <div v-if="form.errors.photo" class="text-red-500 text-xs mt-1">{{ form.errors.photo }}</div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo</label>
                        <el-input id="name" v-model="form.name" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm" required/>
                        <div v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</div>
                    </div>

                    <div v-if="form.type === 'agent'">
                        <label for="promoter_id" class="block text-sm font-medium text-gray-700 mb-1">Promotor Asignado (Opcional)</label>
                        <el-select id="promoter_id" v-model="form.promoter_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm">
                            <el-option value="">Ninguno</el-option>
                            <el-option v-for="promoter in promoters" :key="promoter.id" :value="promoter.id">
                                {{ promoter.name }}
                            </el-option>
                        </el-select>
                        <div v-if="form.errors.promoter_id" class="text-red-500 text-xs mt-1">{{ form.errors.promoter_id }}</div>
                    </div>

                    <div>
                        <label for="entry_date" class="block text-sm font-medium text-gray-700 mb-1">Fecha de Ingreso</label>
                        <el-date-picker
                            v-model="form.entry_date"
                            type="date"
                            value-format="YYYY-MM-DD"
                            format="DD/MM/YYYY"
                            style="width: 100%;"
                        />
                        <div v-if="form.errors.entry_date" class="text-red-500 text-xs mt-1">{{ form.errors.entry_date }}</div>
                    </div>

                    <!-- Estado Activo / Inactivo -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Estado</label>
                        <div class="flex items-center space-x-3">
                            <button type="button" @click="form.is_active = true" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors" :class="form.is_active ? 'bg-green-100 text-green-800 border border-green-300' : 'bg-white text-gray-500 border border-gray-200 hover:bg-gray-50'">
                                Activo
                            </button>
                            <button type="button" @click="form.is_active = false" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors" :class="!form.is_active ? 'bg-red-100 text-red-800 border border-red-300' : 'bg-white text-gray-500 border border-gray-200 hover:bg-gray-50'">
                                Inactivo
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-end pt-4 border-t border-gray-100">
                        <button type="submit" :disabled="form.processing" class="px-4 py-2 bg-black text-white text-sm font-medium rounded-lg hover:bg-gray-800 disabled:opacity-50 transition-colors">
                            Guardar Registro
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>