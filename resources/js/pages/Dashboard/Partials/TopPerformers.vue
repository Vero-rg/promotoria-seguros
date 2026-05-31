<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Users, Award } from 'lucide-vue-next';
import Sparkline from './Sparkline.vue';

defineProps<{
    topAgents: Array<{
        id: number;
        name: string;
        photo: string | null;
        policies_count: number;
        total_volume: number;
        sparkline: number[];
    }>;
    topPromoters: Array<{
        id: number;
        name: string;
        photo: string | null;
        team_volume: number;
        bonuses_secured: number;
        bonuses_total: number;
    }>;
}>();

const getInitials = (name: string) => {
    if (!name) return '?';
    return name.split(' ').filter(w => w.length > 0).map(w => w[0]?.toUpperCase()).slice(0, 2).join('');
};

const getPhotoUrl = (path: string | null) => path ? `/storage/${path}` : null;

const formatCurrency = (v: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v);
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top 5 Agentes -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider flex items-center">
                    <Users class="w-4 h-4 mr-2 text-indigo-500" />
                    Top 5 Agentes
                </h3>
                <Link :href="route('directorio', { type: 'agent' })" class="text-xs text-gray-500 hover:text-black transition-colors">
                    Ver todos →
                </Link>
            </div>
            <div v-if="topAgents && topAgents.length > 0" class="divide-y divide-gray-50">
                <div v-for="(agent, idx) in topAgents" :key="agent.id" class="px-5 py-3 flex items-center justify-between hover:bg-gray-50/50 transition-colors">
                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                        <span class="text-xs font-bold text-gray-300 w-5">{{ idx + 1 }}</span>
                        <div v-if="getPhotoUrl(agent.photo)" class="w-8 h-8 rounded-lg overflow-hidden flex-shrink-0">
                            <img :src="getPhotoUrl(agent.photo)" class="w-full h-full object-cover" />
                        </div>
                        <div v-else class="w-8 h-8 rounded-lg bg-gray-200 flex items-center justify-center text-gray-500 font-bold text-xs flex-shrink-0">
                            {{ getInitials(agent.name) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ agent.name }}</p>
                            <p class="text-xs text-gray-400">{{ agent.policies_count }} póliza(s) · {{ formatCurrency(agent.total_volume) }}</p>
                        </div>
                    </div>
                    <Sparkline :data="agent.sparkline" :width="80" :height="28" color="#4a7c59" />
                </div>
            </div>
            <div v-else class="px-5 py-10 text-center text-gray-400 text-sm">Sin datos en este periodo.</div>
        </div>

        <!-- Top 5 Promotores -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider flex items-center">
                    <Award class="w-4 h-4 mr-2 text-amber-500" />
                    Top 5 Promotores
                </h3>
                <Link :href="route('directorio', { type: 'promoter' })" class="text-xs text-gray-500 hover:text-black transition-colors">
                    Ver todos →
                </Link>
            </div>
            <div v-if="topPromoters && topPromoters.length > 0" class="divide-y divide-gray-50">
                <div v-for="(promoter, idx) in topPromoters" :key="promoter.id" class="px-5 py-3 flex items-center justify-between hover:bg-gray-50/50 transition-colors">
                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                        <span class="text-xs font-bold text-gray-300 w-5">{{ idx + 1 }}</span>
                        <div v-if="getPhotoUrl(promoter.photo)" class="w-8 h-8 rounded-lg overflow-hidden flex-shrink-0">
                            <img :src="getPhotoUrl(promoter.photo)" class="w-full h-full object-cover" />
                        </div>
                        <div v-else class="w-8 h-8 rounded-lg bg-gray-200 flex items-center justify-center text-gray-500 font-bold text-xs flex-shrink-0">
                            {{ getInitials(promoter.name) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ promoter.name }}</p>
                            <p class="text-xs text-gray-400">{{ formatCurrency(promoter.team_volume) }}</p>
                        </div>
                    </div>
                    <!-- Indicador de Bonos -->
                    <div class="flex items-center space-x-1 flex-shrink-0">
                        <span
                            v-for="i in promoter.bonuses_total"
                            :key="i"
                            class="w-2.5 h-2.5 rounded-full"
                            :class="i <= promoter.bonuses_secured ? 'bg-[#4a7c59]' : 'bg-gray-200'"
                        />
                    </div>
                </div>
            </div>
            <div v-else class="px-5 py-10 text-center text-gray-400 text-sm">Sin datos en este periodo.</div>
        </div>
    </div>
</template>
