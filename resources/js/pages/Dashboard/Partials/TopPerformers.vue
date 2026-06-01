<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Users, Award, ChevronRight, Medal, X, ExternalLink, DollarSign, CheckCircle2 } from 'lucide-vue-next';
import Sparkline from './Sparkline.vue';

defineProps<{
    topAgents: Array<{
        id: number;
        name: string;
        photo: string | null;
        policies_count: number;
        total_volume: number;
        total_commission: number;
        bonus_names: string[];
        bonus_details: Array<{ name: string; amount: number; progress_label: string }>;
        sparkline: number[];
    }>;
    topPromoters: Array<{
        id: number;
        name: string;
        photo: string | null;
        team_volume: number;
        bonuses_secured: number;
        bonuses_total: number;
        bonus_names: string[];
        bonus_details: Array<{ name: string; amount: number; progress_label: string }>;
    }>;
}>();

// ─── Modal State ────────────────────────────────
const selectedAgent = ref<any>(null);
const selectedPromoter = ref<any>(null);

const openAgentModal = (agent: any) => { selectedAgent.value = agent; };
const closeAgentModal = () => { selectedAgent.value = null; };
const openPromoterModal = (promoter: any) => { selectedPromoter.value = promoter; };
const closePromoterModal = () => { selectedPromoter.value = null; };

// ─── Helpers ────────────────────────────────────
const getInitials = (name: string) => {
    if (!name) return '?';
    return name.split(' ').filter(w => w.length > 0).map(w => w[0]?.toUpperCase()).slice(0, 2).join('');
};

const getPhotoUrl = (path: string | null) => path ? `/storage/${path}` : null;

const formatCurrency = (v: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v);

const getMedalClass = (idx: number) => {
    if (idx === 0) return 'text-amber-500';
    if (idx === 1) return 'text-gray-400';
    if (idx === 2) return 'text-amber-700';
    return 'text-gray-300';
};
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- ═══════════ TOP 5 AGENTES ═══════════ -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/30">
                <h3 class="text-sm font-semibold text-gray-900 flex items-center">
                    <Users class="w-4 h-4 mr-2 text-gray-400" />
                    Top Agentes
                </h3>
                <Link :href="route('directorio', { type: 'agent' })" class="text-xs text-gray-500 hover:text-gray-900 transition-colors flex items-center font-medium">
                    Ver todos <ChevronRight class="w-3.5 h-3.5 ml-0.5" />
                </Link>
            </div>
            <div v-if="topAgents && topAgents.length > 0" class="divide-y divide-gray-50 flex-1">
                <div
                    v-for="(agent, idx) in topAgents"
                    :key="agent.id"
                    class="px-5 py-3.5 flex items-center justify-between hover:bg-gray-50/50 transition-colors group cursor-pointer"
                    @click="openAgentModal(agent)"
                >
                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                        <Medal v-if="idx < 3" :class="getMedalClass(idx)" class="w-5 h-5 flex-shrink-0" stroke-width="1.5" />
                        <span v-else class="text-xs font-semibold text-gray-300 w-5 text-center flex-shrink-0">{{ idx + 1 }}</span>

                        <div v-if="getPhotoUrl(agent.photo)" class="w-9 h-9 rounded-lg overflow-hidden flex-shrink-0 border border-gray-100">
                            <img :src="getPhotoUrl(agent.photo)" class="w-full h-full object-cover" />
                        </div>
                        <div v-else class="w-9 h-9 rounded-lg bg-gray-100 border border-gray-100 flex items-center justify-center text-gray-500 font-bold text-xs flex-shrink-0">
                            {{ getInitials(agent.name) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate group-hover:text-black transition-colors">{{ agent.name }}</p>
                            <p class="text-xs text-gray-400">{{ agent.policies_count }} póliza(s) <span class="mx-1 text-gray-200">·</span> {{ formatCurrency(agent.total_volume) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2 flex-shrink-0">
                        <Sparkline :data="agent.sparkline" :width="60" :height="26" color="#10b981" />
                        <Link
                            :href="route('agents.show', agent.id)"
                            class="p-1 rounded-md text-gray-300 hover:text-gray-600 hover:bg-gray-100 transition-colors opacity-0 group-hover:opacity-100"
                            @click.stop
                        >
                            <ExternalLink class="w-3.5 h-3.5" />
                        </Link>
                    </div>
                </div>
            </div>
            <div v-else class="px-5 py-12 text-center text-gray-400 text-sm">Sin datos en este periodo.</div>
        </div>

        <!-- ═══════════ TOP 5 PROMOTORES ═══════════ -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/30">
                <h3 class="text-sm font-semibold text-gray-900 flex items-center">
                    <Award class="w-4 h-4 mr-2 text-gray-400" />
                    Top Promotores
                </h3>
                <Link :href="route('directorio', { type: 'promoter' })" class="text-xs text-gray-500 hover:text-gray-900 transition-colors flex items-center font-medium">
                    Ver todos <ChevronRight class="w-3.5 h-3.5 ml-0.5" />
                </Link>
            </div>
            <div v-if="topPromoters && topPromoters.length > 0" class="divide-y divide-gray-50 flex-1">
                <div
                    v-for="(promoter, idx) in topPromoters"
                    :key="promoter.id"
                    class="px-5 py-3.5 flex items-center justify-between hover:bg-gray-50/50 transition-colors group cursor-pointer"
                    @click="openPromoterModal(promoter)"
                >
                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                        <Medal v-if="idx < 3" :class="getMedalClass(idx)" class="w-5 h-5 flex-shrink-0" stroke-width="1.5" />
                        <span v-else class="text-xs font-semibold text-gray-300 w-5 text-center flex-shrink-0">{{ idx + 1 }}</span>

                        <div v-if="getPhotoUrl(promoter.photo)" class="w-9 h-9 rounded-lg overflow-hidden flex-shrink-0 border border-gray-100">
                            <img :src="getPhotoUrl(promoter.photo)" class="w-full h-full object-cover" />
                        </div>
                        <div v-else class="w-9 h-9 rounded-lg bg-gray-100 border border-gray-100 flex items-center justify-center text-gray-500 font-bold text-xs flex-shrink-0">
                            {{ getInitials(promoter.name) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate group-hover:text-black transition-colors">{{ promoter.name }}</p>
                            <p class="text-xs text-gray-400">{{ formatCurrency(promoter.team_volume) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2 flex-shrink-0">
                        <!-- Indicador de Bonos -->
                        <div class="flex items-center space-x-1">
                            <span
                                v-for="i in promoter.bonuses_total"
                                :key="i"
                                class="w-1.5 h-5 rounded-full transition-colors"
                                :class="i <= promoter.bonuses_secured ? 'bg-gray-800' : 'bg-gray-200'"
                            />
                        </div>
                        <Link
                            :href="route('promoters.show', promoter.id)"
                            class="p-1 rounded-md text-gray-300 hover:text-gray-600 hover:bg-gray-100 transition-colors opacity-0 group-hover:opacity-100"
                            @click.stop
                        >
                            <ExternalLink class="w-3.5 h-3.5" />
                        </Link>
                    </div>
                </div>
            </div>
            <div v-else class="px-5 py-12 text-center text-gray-400 text-sm">Sin datos en este periodo.</div>
        </div>
    </div>

    <!-- ═══════════ MODAL: AGENTE ═══════════ -->
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="selectedAgent" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="closeAgentModal">
                <div class="absolute inset-0 bg-black/30 backdrop-blur-sm"></div>
                <div class="relative bg-white rounded-2xl shadow-xl border border-gray-100 w-full max-w-md max-h-[85vh] overflow-y-auto">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100 sticky top-0 bg-white rounded-t-2xl z-10">
                        <div class="flex items-center space-x-3">
                            <div v-if="getPhotoUrl(selectedAgent.photo)" class="w-10 h-10 rounded-lg overflow-hidden border border-gray-100">
                                <img :src="getPhotoUrl(selectedAgent.photo)" class="w-full h-full object-cover" />
                            </div>
                            <div v-else class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-500 font-bold text-sm">
                                {{ getInitials(selectedAgent.name) }}
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ selectedAgent.name }}</h3>
                                <p class="text-xs text-gray-400">{{ selectedAgent.policies_count }} póliza(s) · {{ formatCurrency(selectedAgent.total_volume) }}</p>
                            </div>
                        </div>
                        <button @click="closeAgentModal" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Content -->
                    <div class="px-6 py-5 space-y-5">
                        <!-- Comisiones -->
                        <div class="bg-emerald-50/50 rounded-xl p-4 border border-emerald-100/50">
                            <div class="flex items-center space-x-2 mb-1">
                                <DollarSign class="w-4 h-4 text-emerald-600" />
                                <span class="text-sm font-medium text-emerald-800">Comisiones Generadas</span>
                            </div>
                            <p class="text-2xl font-bold text-emerald-700 ml-6">{{ formatCurrency(selectedAgent.total_commission) }}</p>
                        </div>

                        <!-- Bonos Ganados -->
                        <div>
                            <div class="flex items-center space-x-2 mb-3">
                                <Award class="w-4 h-4 text-amber-500" />
                                <span class="text-sm font-semibold text-gray-800">Bonos Desbloqueados</span>
                                <span class="text-xs text-gray-400">({{ selectedAgent.bonus_details?.length || 0 }})</span>
                            </div>

                            <div v-if="selectedAgent.bonus_details && selectedAgent.bonus_details.length > 0" class="space-y-2">
                                <div
                                    v-for="(b, bi) in selectedAgent.bonus_details"
                                    :key="bi"
                                    class="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-3 border border-gray-100"
                                >
                                    <div class="flex items-center space-x-2">
                                        <CheckCircle2 class="w-4 h-4 text-emerald-500 flex-shrink-0" />
                                        <span class="text-sm font-medium text-gray-800">{{ b.name }}</span>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-semibold text-gray-900">{{ formatCurrency(b.amount) }}</p>
                                        <p v-if="b.progress_label" class="text-xs text-gray-400">{{ b.progress_label }}</p>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="text-center py-4 text-sm text-gray-400 bg-gray-50 rounded-lg border border-gray-100">
                                Sin bonos desbloqueados en este periodo.
                            </div>
                        </div>

                        <!-- Link a perfil -->
                        <Link
                            :href="route('agents.show', selectedAgent.id)"
                            class="flex items-center justify-center w-full px-4 py-2.5 bg-gray-900 text-white text-sm font-medium rounded-xl hover:bg-black transition-colors"
                        >
                            Ver perfil completo <ExternalLink class="w-3.5 h-3.5 ml-1.5" />
                        </Link>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ═══════════ MODAL: PROMOTOR ═══════════ -->
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="selectedPromoter" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="closePromoterModal">
                <div class="absolute inset-0 bg-black/30 backdrop-blur-sm"></div>
                <div class="relative bg-white rounded-2xl shadow-xl border border-gray-100 w-full max-w-md max-h-[85vh] overflow-y-auto">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100 sticky top-0 bg-white rounded-t-2xl z-10">
                        <div class="flex items-center space-x-3">
                            <div v-if="getPhotoUrl(selectedPromoter.photo)" class="w-10 h-10 rounded-lg overflow-hidden border border-gray-100">
                                <img :src="getPhotoUrl(selectedPromoter.photo)" class="w-full h-full object-cover" />
                            </div>
                            <div v-else class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-500 font-bold text-sm">
                                {{ getInitials(selectedPromoter.name) }}
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ selectedPromoter.name }}</h3>
                                <p class="text-xs text-gray-400">Vol. equipo: {{ formatCurrency(selectedPromoter.team_volume) }}</p>
                            </div>
                        </div>
                        <button @click="closePromoterModal" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Content -->
                    <div class="px-6 py-5 space-y-5">
                        <!-- Bonos Ganados -->
                        <div>
                            <div class="flex items-center space-x-2 mb-3">
                                <Award class="w-4 h-4 text-amber-500" />
                                <span class="text-sm font-semibold text-gray-800">Bonos Desbloqueados</span>
                                <span class="text-xs text-gray-400">({{ selectedPromoter.bonuses_secured }} de {{ selectedPromoter.bonuses_total }})</span>
                            </div>

                            <div v-if="selectedPromoter.bonus_details && selectedPromoter.bonus_details.length > 0" class="space-y-2">
                                <div
                                    v-for="(b, bi) in selectedPromoter.bonus_details"
                                    :key="bi"
                                    class="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-3 border border-gray-100"
                                >
                                    <div class="flex items-center space-x-2">
                                        <CheckCircle2 class="w-4 h-4 text-emerald-500 flex-shrink-0" />
                                        <span class="text-sm font-medium text-gray-800">{{ b.name }}</span>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-semibold text-gray-900">{{ formatCurrency(b.amount) }}</p>
                                        <p v-if="b.progress_label" class="text-xs text-gray-400">{{ b.progress_label }}</p>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="text-center py-4 text-sm text-gray-400 bg-gray-50 rounded-lg border border-gray-100">
                                Sin bonos desbloqueados en este periodo.
                            </div>
                        </div>

                        <!-- Link a perfil -->
                        <Link
                            :href="route('promoters.show', selectedPromoter.id)"
                            class="flex items-center justify-center w-full px-4 py-2.5 bg-gray-900 text-white text-sm font-medium rounded-xl hover:bg-black transition-colors"
                        >
                            Ver perfil completo <ExternalLink class="w-3.5 h-3.5 ml-1.5" />
                        </Link>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.2s ease;
}
.modal-enter-active > div:last-child,
.modal-leave-active > div:last-child {
    transition: transform 0.2s ease, opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-from > div:last-child,
.modal-leave-to > div:last-child {
    transform: scale(0.95) translateY(10px);
    opacity: 0;
}
</style>