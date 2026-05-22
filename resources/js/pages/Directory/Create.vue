<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    promoters: Array,
});

const form = useForm({
    type: 'agent', // 'promoter' o 'agent'
    name: '',
    promoter_id: '',
});

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