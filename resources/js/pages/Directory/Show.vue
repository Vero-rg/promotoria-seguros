<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Calendar, UserCircle, Users } from 'lucide-vue-next';

const props = defineProps({
    entity: Object,
    type: String, // 'promoter' o 'agent'
});

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-ES', {
        year: 'numeric', month: 'long', day: 'numeric'
    });
};

const breadcrumbs = [
    {
        title: 'Directorio',
        href: route('directorio'),
    },
    {
        title: `Información`,
    },
];

</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="entity.name" />

        <div class="max-w-4xl mx-200px p-4 sm:p-6 lg:p-8">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-6 border-b border-gray-100 bg-gray-50/30 flex justify-between items-start">
                    <div class="flex items-center space-x-4">
                        <div class="p-3 bg-gray-100 rounded-xl">
                            <UserCircle v-if="type === 'promoter'" class="w-8 h-8 text-gray-700" />
                            <Users v-else class="w-8 h-8 text-gray-700" />
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">{{ entity.name }}</h1>
                            <p class="text-sm text-gray-500 capitalize">{{ type === 'promoter' ? 'Promotor' : 'Agente' }}</p>
                        </div>
                    </div>
                    <Link :href="route(type === 'promoter' ? 'promoters.edit' : 'agents.edit', entity.id)" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors">
                        Editar
                    </Link>
                </div>

                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider">Detalles</h3>
                            <div class="flex items-center text-sm text-gray-900">
                                <Calendar class="w-4 h-4 mr-2 text-gray-400" />
                                Registrado el: {{ formatDate(entity.created_at) }}
                            </div>
                            <div v-if="type === 'agent'" class="flex items-center text-sm text-gray-900">
                                <UserCircle class="w-4 h-4 mr-2 text-gray-400" />
                                Promotor: 
                                <Link v-if="entity.promoter" :href="route('promoters.show', entity.promoter.id)" class="ml-1 text-black font-medium hover:underline">
                                    {{ entity.promoter.name }}
                                </Link>
                                <span v-else class="ml-1 text-gray-500">Ninguno</span>
                            </div>
                        </div>

                        <div v-if="type === 'promoter'" class="space-y-4">
                            <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider">Agentes a cargo ({{ entity.agents.length }})</h3>
                            <ul v-if="entity.agents.length > 0" class="divide-y divide-gray-100 bg-gray-50 rounded-xl border border-gray-100">
                                <li v-for="agent in entity.agents" :key="agent.id">
                                    <Link :href="route('agents.show', agent.id)" class="block px-4 py-3 hover:bg-gray-100 transition-colors text-sm font-medium text-gray-900">
                                        {{ agent.name }}
                                    </Link>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-gray-500">Este promotor no tiene agentes asignados actualmente.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>    
</template>