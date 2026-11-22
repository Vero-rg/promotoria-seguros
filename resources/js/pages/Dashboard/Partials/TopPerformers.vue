<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Award, CheckCircle2, ChevronRight, DollarSign, ExternalLink, Medal, Users, X } from 'lucide-vue-next';
import { ref } from 'vue';
import Sparkline from './Sparkline.vue';

defineProps<{
    topAgents: Array<{
        id: number;
        name: string;
        photo: string | null;
        policies_count: number;
        total_volume: number;
        total_commission: number;
        commission_breakdown?: { gross: number; isr: number; cedular: number; billing: number; net: number };
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
        total_commission: number;
        commission_breakdown?: { gross: number; isr: number; cedular: number; billing: number; net: number };
    }>;
}>();

// ─── Modal State ────────────────────────────────
const selectedAgent = ref<any>(null);
const selectedPromoter = ref<any>(null);

const openAgentModal = (agent: any) => {
    selectedAgent.value = agent;
};
const closeAgentModal = () => {
    selectedAgent.value = null;
};
const openPromoterModal = (promoter: any) => {
    selectedPromoter.value = promoter;
};
const closePromoterModal = () => {
    selectedPromoter.value = null;
};

// ─── Helpers ────────────────────────────────────
const getInitials = (name: string) => {
    if (!name) return '?';
    return name
        .split(' ')
        .filter((w) => w.length > 0)
        .map((w) => w[0]?.toUpperCase())
        .slice(0, 2)
        .join('');
};

const getPhotoUrl = (path: string | null) => (path ? `/storage/${path}` : null);

const formatCurrency = (v: number) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v);

const getMedalClass = (idx: number) => {
    if (idx === 0) return 'text-amber-500';
    if (idx === 1) return 'text-gray-400';
    if (idx === 2) return 'text-amber-700';
    return 'text-gray-300';
};
</script>

<template>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- ═══════════ TOP 5 AGENTES ═══════════ -->
        <div class="flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50/30 px-5 py-4">
                <h3 class="flex items-center text-sm font-semibold text-gray-900">
                    <Users class="mr-2 h-4 w-4 text-gray-400" />
                    Top Agentes
                </h3>
                <Link
                    :href="route('directorio', { type: 'agent' })"
                    class="flex items-center text-xs font-medium text-gray-500 transition-colors hover:text-gray-900"
                >
                    Ver todos <ChevronRight class="ml-0.5 h-3.5 w-3.5" />
                </Link>
            </div>
            <div v-if="topAgents && topAgents.length > 0" class="flex-1 divide-y divide-gray-50">
                <div
                    v-for="(agent, idx) in topAgents"
                    :key="agent.id"
                    class="group flex cursor-pointer items-center justify-between px-5 py-3.5 transition-colors hover:bg-gray-50/50"
                    @click="openAgentModal(agent)"
                >
                    <div class="flex min-w-0 flex-1 items-center space-x-3">
                        <Medal v-if="idx < 3" :class="getMedalClass(idx)" class="h-5 w-5 flex-shrink-0" stroke-width="1.5" />
                        <span v-else class="w-5 flex-shrink-0 text-center text-xs font-semibold text-gray-300">{{ idx + 1 }}</span>

                        <div v-if="getPhotoUrl(agent.photo)" class="h-9 w-9 flex-shrink-0 overflow-hidden rounded-lg border border-gray-100">
                            <img :src="getPhotoUrl(agent.photo)" class="h-full w-full object-cover" />
                        </div>
                        <div
                            v-else
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg border border-gray-100 bg-gray-100 text-xs font-bold text-gray-500"
                        >
                            {{ getInitials(agent.name) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 transition-colors group-hover:text-black">{{ agent.name }}</p>
                            <p class="text-xs text-gray-400">
                                {{ agent.policies_count }} póliza(s) <span class="mx-1 text-gray-200">·</span>
                                {{ formatCurrency(agent.total_volume) }}
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-shrink-0 items-center space-x-2">
                        <Sparkline :data="agent.sparkline" :width="60" :height="26" color="#10b981" />
                        <Link
                            :href="route('agents.show', agent.id)"
                            class="rounded-md p-1 text-gray-300 opacity-0 transition-colors hover:bg-gray-100 hover:text-gray-600 group-hover:opacity-100"
                            @click.stop
                        >
                            <ExternalLink class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                </div>
            </div>
            <div v-else class="px-5 py-12 text-center text-sm text-gray-400">Sin datos en este periodo.</div>
        </div>

        <!-- ═══════════ TOP 5 PROMOTORES ═══════════ -->
        <div class="flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50/30 px-5 py-4">
                <h3 class="flex items-center text-sm font-semibold text-gray-900">
                    <Award class="mr-2 h-4 w-4 text-gray-400" />
                    Top Promotores
                </h3>
                <Link
                    :href="route('directorio', { type: 'promoter' })"
                    class="flex items-center text-xs font-medium text-gray-500 transition-colors hover:text-gray-900"
                >
                    Ver todos <ChevronRight class="ml-0.5 h-3.5 w-3.5" />
                </Link>
            </div>
            <div v-if="topPromoters && topPromoters.length > 0" class="flex-1 divide-y divide-gray-50">
                <div
                    v-for="(promoter, idx) in topPromoters"
                    :key="promoter.id"
                    class="group flex cursor-pointer items-center justify-between px-5 py-3.5 transition-colors hover:bg-gray-50/50"
                    @click="openPromoterModal(promoter)"
                >
                    <div class="flex min-w-0 flex-1 items-center space-x-3">
                        <Medal v-if="idx < 3" :class="getMedalClass(idx)" class="h-5 w-5 flex-shrink-0" stroke-width="1.5" />
                        <span v-else class="w-5 flex-shrink-0 text-center text-xs font-semibold text-gray-300">{{ idx + 1 }}</span>

                        <div v-if="getPhotoUrl(promoter.photo)" class="h-9 w-9 flex-shrink-0 overflow-hidden rounded-lg border border-gray-100">
                            <img :src="getPhotoUrl(promoter.photo)" class="h-full w-full object-cover" />
                        </div>
                        <div
                            v-else
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg border border-gray-100 bg-gray-100 text-xs font-bold text-gray-500"
                        >
                            {{ getInitials(promoter.name) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 transition-colors group-hover:text-black">{{ promoter.name }}</p>
                            <p class="text-xs text-gray-400">{{ formatCurrency(promoter.team_volume) }}</p>
                        </div>
                    </div>
                    <div class="flex flex-shrink-0 items-center space-x-2">
                        <!-- Indicador de Bonos -->
                        <div class="flex items-center space-x-1">
                            <span
                                v-for="i in promoter.bonuses_total"
                                :key="i"
                                class="h-5 w-1.5 rounded-full transition-colors"
                                :class="i <= promoter.bonuses_secured ? 'bg-gray-800' : 'bg-gray-200'"
                            />
                        </div>
                        <Link
                            :href="route('promoters.show', promoter.id)"
                            class="rounded-md p-1 text-gray-300 opacity-0 transition-colors hover:bg-gray-100 hover:text-gray-600 group-hover:opacity-100"
                            @click.stop
                        >
                            <ExternalLink class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                </div>
            </div>
            <div v-else class="px-5 py-12 text-center text-sm text-gray-400">Sin datos en este periodo.</div>
        </div>
    </div>

    <!-- ═══════════ MODAL: AGENTE ═══════════ -->
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="selectedAgent" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="closeAgentModal">
                <div class="absolute inset-0 bg-black/30 backdrop-blur-sm"></div>
                <div class="relative max-h-[85vh] w-full max-w-md overflow-y-auto rounded-2xl border border-gray-100 bg-white shadow-xl">
                    <!-- Header -->
                    <div class="sticky top-0 z-10 flex items-center justify-between rounded-t-2xl border-b border-gray-100 bg-white px-6 py-5">
                        <div class="flex items-center space-x-3">
                            <div v-if="getPhotoUrl(selectedAgent.photo)" class="h-10 w-10 overflow-hidden rounded-lg border border-gray-100">
                                <img :src="getPhotoUrl(selectedAgent.photo)" class="h-full w-full object-cover" />
                            </div>
                            <div v-else class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-sm font-bold text-gray-500">
                                {{ getInitials(selectedAgent.name) }}
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ selectedAgent.name }}</h3>
                                <p class="text-xs text-gray-400">
                                    {{ selectedAgent.policies_count }} póliza(s) · {{ formatCurrency(selectedAgent.total_volume) }}
                                </p>
                            </div>
                        </div>
                        <button
                            @click="closeAgentModal"
                            class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <!-- Content -->
                    <div class="space-y-5 px-6 py-5">
                        <!-- Comisiones con desglose -->
                        <div class="rounded-xl border border-emerald-100/50 bg-emerald-50/50 p-4">
                            <div class="mb-3 flex items-center space-x-2">
                                <DollarSign class="h-4 w-4 text-emerald-600" />
                                <span class="text-sm font-medium text-emerald-800">Comisiones Generadas</span>
                            </div>
                            <div class="ml-6 space-y-1">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Comisión total generada</span>
                                    <span class="font-medium text-gray-700">{{
                                        formatCurrency(selectedAgent.commission_breakdown?.gross || selectedAgent.total_commission)
                                    }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-400">− Retención de ISR</span>
                                    <span class="font-medium text-red-500">−{{ formatCurrency(selectedAgent.commission_breakdown?.isr || 0) }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-400">− Impuesto cedular</span>
                                    <span class="font-medium text-red-500"
                                        >−{{ formatCurrency(selectedAgent.commission_breakdown?.cedular || 0) }}</span
                                    >
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-400">− Costo de facturación</span>
                                    <span class="font-medium text-red-500"
                                        >−{{ formatCurrency(selectedAgent.commission_breakdown?.billing || 0) }}</span
                                    >
                                </div>
                                <div class="mt-1 flex justify-between border-t border-emerald-200 pt-2 text-base font-bold">
                                    <span class="text-emerald-800">Total neto</span>
                                    <span class="text-xl text-emerald-700">{{
                                        formatCurrency(selectedAgent.commission_breakdown?.net || selectedAgent.total_commission)
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Bonos Ganados -->
                        <div>
                            <div class="mb-3 flex items-center space-x-2">
                                <Award class="h-4 w-4 text-amber-500" />
                                <span class="text-sm font-semibold text-gray-800">Bonos Desbloqueados</span>
                                <span class="text-xs text-gray-400">({{ selectedAgent.bonus_details?.length || 0 }})</span>
                            </div>

                            <div v-if="selectedAgent.bonus_details && selectedAgent.bonus_details.length > 0" class="space-y-2">
                                <div
                                    v-for="(b, bi) in selectedAgent.bonus_details"
                                    :key="bi"
                                    class="flex items-center justify-between rounded-lg border border-gray-100 bg-gray-50 px-4 py-3"
                                >
                                    <div class="flex items-center space-x-2">
                                        <CheckCircle2 class="h-4 w-4 flex-shrink-0 text-emerald-500" />
                                        <span class="text-sm font-medium text-gray-800">{{ b.name }}</span>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-semibold text-gray-900">{{ formatCurrency(b.amount) }}</p>
                                        <p v-if="b.progress_label" class="text-xs text-gray-400">{{ b.progress_label }}</p>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="rounded-lg border border-gray-100 bg-gray-50 py-4 text-center text-sm text-gray-400">
                                Sin bonos desbloqueados en este periodo.
                            </div>
                        </div>

                        <!-- Link a perfil -->
                        <Link
                            :href="route('agents.show', selectedAgent.id)"
                            class="flex w-full items-center justify-center rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-black"
                        >
                            Ver perfil completo <ExternalLink class="ml-1.5 h-3.5 w-3.5" />
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
                <div class="relative max-h-[85vh] w-full max-w-md overflow-y-auto rounded-2xl border border-gray-100 bg-white shadow-xl">
                    <!-- Header -->
                    <div class="sticky top-0 z-10 flex items-center justify-between rounded-t-2xl border-b border-gray-100 bg-white px-6 py-5">
                        <div class="flex items-center space-x-3">
                            <div v-if="getPhotoUrl(selectedPromoter.photo)" class="h-10 w-10 overflow-hidden rounded-lg border border-gray-100">
                                <img :src="getPhotoUrl(selectedPromoter.photo)" class="h-full w-full object-cover" />
                            </div>
                            <div v-else class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-sm font-bold text-gray-500">
                                {{ getInitials(selectedPromoter.name) }}
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ selectedPromoter.name }}</h3>
                                <p class="text-xs text-gray-400">Vol. equipo: {{ formatCurrency(selectedPromoter.team_volume) }}</p>
                            </div>
                        </div>
                        <button
                            @click="closePromoterModal"
                            class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <!-- Content -->
                    <div class="space-y-5 px-6 py-5">
                        <!-- Bonos Ganados -->
                        <div>
                            <div class="mb-3 flex items-center space-x-2">
                                <Award class="h-4 w-4 text-amber-500" />
                                <span class="text-sm font-semibold text-gray-800">Bonos Desbloqueados</span>
                                <span class="text-xs text-gray-400"
                                    >({{ selectedPromoter.bonuses_secured }} de {{ selectedPromoter.bonuses_total }})</span
                                >
                            </div>

                            <div v-if="selectedPromoter.bonus_details && selectedPromoter.bonus_details.length > 0" class="space-y-2">
                                <div
                                    v-for="(b, bi) in selectedPromoter.bonus_details"
                                    :key="bi"
                                    class="flex items-center justify-between rounded-lg border border-gray-100 bg-gray-50 px-4 py-3"
                                >
                                    <div class="flex items-center space-x-2">
                                        <CheckCircle2 class="h-4 w-4 flex-shrink-0 text-emerald-500" />
                                        <span class="text-sm font-medium text-gray-800">{{ b.name }}</span>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-semibold text-gray-900">{{ formatCurrency(b.amount) }}</p>
                                        <p v-if="b.progress_label" class="text-xs text-gray-400">{{ b.progress_label }}</p>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="rounded-lg border border-gray-100 bg-gray-50 py-4 text-center text-sm text-gray-400">
                                Sin bonos desbloqueados en este periodo.
                            </div>
                        </div>

                        <!-- Comisiones del Promotor con desglose -->
                        <div class="rounded-xl border border-emerald-100/50 bg-emerald-50/50 p-4">
                            <div class="mb-3 flex items-center space-x-2">
                                <DollarSign class="h-4 w-4 text-emerald-600" />
                                <span class="text-sm font-medium text-emerald-800">Comisiones por Ventas del Equipo</span>
                            </div>
                            <div class="ml-6 space-y-1">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Comisión total generada</span>
                                    <span class="font-medium text-gray-700">{{
                                        formatCurrency(selectedPromoter.commission_breakdown?.gross || selectedPromoter.total_commission || 0)
                                    }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-400">− Retención de ISR</span>
                                    <span class="font-medium text-red-500"
                                        >−{{ formatCurrency(selectedPromoter.commission_breakdown?.isr || 0) }}</span
                                    >
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-400">− Impuesto cedular</span>
                                    <span class="font-medium text-red-500"
                                        >−{{ formatCurrency(selectedPromoter.commission_breakdown?.cedular || 0) }}</span
                                    >
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-400">− Costo de facturación</span>
                                    <span class="font-medium text-red-500"
                                        >−{{ formatCurrency(selectedPromoter.commission_breakdown?.billing || 0) }}</span
                                    >
                                </div>
                                <div class="mt-1 flex justify-between border-t border-emerald-200 pt-2 text-base font-bold">
                                    <span class="text-emerald-800">Total neto</span>
                                    <span class="text-xl text-emerald-700">{{
                                        formatCurrency(selectedPromoter.commission_breakdown?.net || selectedPromoter.total_commission || 0)
                                    }}</span>
                                </div>
                            </div>
                            <p class="ml-6 mt-2 text-xs text-emerald-600/70">Basado en el esquema de comisiones activo</p>
                        </div>

                        <!-- Link a perfil -->
                        <Link
                            :href="route('promoters.show', selectedPromoter.id)"
                            class="flex w-full items-center justify-center rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-black"
                        >
                            Ver perfil completo <ExternalLink class="ml-1.5 h-3.5 w-3.5" />
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
    transition:
        transform 0.2s ease,
        opacity 0.2s ease;
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
