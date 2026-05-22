<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    entity: Object,
    type: String, // 'promoter' o 'agent'
    promoters: Array,
});

const form = useForm({
    name: props.entity.name,
    promoter_id: props.entity.promoter_id || '',
});

const submit = () => {
    const routeName = props.type === 'promoter' ? 'promoters.update' : 'agents.update';
    form.put(route(routeName, props.entity.id));
};

const breadcrumbs = [
    {
        title: 'Directorio',
        href: route('directorio'),
    },
    {
        title: `Editar`,
    },
];

</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Editar ${type === 'promoter' ? 'Promotor' : 'Agente'}`" />

        <div class="max-w-2xl mx-200px p-4 sm:p-6 lg:p-8">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100">
                    <h1 class="text-xl font-semibold text-gray-900">Editar {{ type === 'promoter' ? 'Promotor' : 'Agente' }}</h1>
                </div>

                <form @submit.prevent="submit" class="p-6 space-y-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo</label>
                        <el-input id="name" v-model="form.name" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm" required/>
                        <div v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</div>
                    </div>

                    <div v-if="type === 'agent'">
                        <label for="promoter_id" class="block text-sm font-medium text-gray-700 mb-1">Promotor Asignado</label>
                        <el-select id="promoter_id" v-model="form.promoter_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-black focus:ring-black sm:text-sm">
                            <el-option value="">Ninguno</el-option>
                            <el-option v-for="promoter in promoters" :key="promoter.id" :value="promoter.id">
                                {{ promoter.name }}
                            </el-option>
                        </el-select>
                        <div v-if="form.errors.promoter_id" class="text-red-500 text-xs mt-1">{{ form.errors.promoter_id }}</div>
                    </div>

                    <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-100">
                        <button type="submit" :disabled="form.processing" class="px-4 py-2 bg-black text-white text-sm font-medium rounded-lg hover:bg-gray-800 transition-colors">
                            Actualizar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>    
</template>