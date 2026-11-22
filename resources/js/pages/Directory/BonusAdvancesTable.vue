<script setup>
/**
 * BonusAdvancesTable.vue
 *
 * Anticipos mensuales de los bonos que los permiten. Muestra, por mes:
 * acumulado del trimestre, porcentaje alcanzado y el anticipo entregado
 * (calculado automáticamente y editable).
 */
import { router } from '@inertiajs/vue3';
import { Check, Pencil, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps({
    advances: { type: Object, required: true },
    schemeId: { type: Number, required: true },
    userType: { type: String, required: true },
    userId: { type: Number, required: true },
    /** Habilita la edición manual del monto del anticipo. */
    editable: { type: Boolean, default: false },
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 2 }).format(value ?? 0);
};

/** Formato de moneda con separador de miles: «$ 252,720.00». */
const formatAmount = (value) => {
    if (value === null || value === undefined || value === '') return '';

    const [integer, decimals] = String(value).split('.');

    return `$ ${integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${decimals ? '.' + decimals : ''}`;
};

/** Quita el símbolo y los separadores para obtener el número capturado. */
const parseAmount = (value) =>
    String(value ?? '')
        .replace(/\$\s?|(,*)/g, '')
        .replace(/[^\d.]/g, '');

const rows = ref([]);
const savingMonth = ref(null);
const savedMonth = ref(null);

const syncRows = () => {
    const editing = new Set(rows.value.filter((row) => row.editing).map((row) => row.month));

    rows.value = (props.advances?.months ?? []).map((month) => ({
        ...month,
        draft: String(month.amount ?? 0),
        editing: editing.has(month.month),
    }));
};

watch(() => props.advances, syncRows, { immediate: true, deep: true });

const startEdit = (row) => {
    if (!props.editable) return;

    row.draft = String(row.amount ?? 0);
    row.editing = true;
};

const cancelEdit = (row) => {
    row.draft = String(row.amount ?? 0);
    row.editing = false;
};

const isDirty = (row) => Number(row.draft || 0) !== Number(row.amount ?? 0);

const save = (row) => {
    row.editing = false;

    if (!isDirty(row)) {
        row.draft = String(row.amount ?? 0);
        return;
    }

    savingMonth.value = row.month;

    router.post(
        route('bonus-advances.store'),
        {
            scheme_id: props.schemeId,
            user_type: props.userType,
            user_id: props.userId,
            period_month: row.month,
            amount: Math.max(0, Number(row.draft || 0)),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                savedMonth.value = row.month;
                setTimeout(() => {
                    if (savedMonth.value === row.month) savedMonth.value = null;
                }, 2000);
            },
            onFinish: () => {
                savingMonth.value = null;
            },
        },
    );
};
</script>

<template>
    <div v-if="rows.length > 0" class="overflow-hidden rounded-xl border border-amber-100 bg-amber-50/40">
        <div class="flex items-center justify-between border-b border-amber-100 px-3 py-2">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-amber-800">Anticipos mensuales</span>
            <span class="text-xs font-semibold text-amber-900">Total: {{ formatCurrency(advances.total) }}</span>
        </div>

        <div class="divide-y divide-amber-100/70">
            <div v-for="row in rows" :key="row.month" class="space-y-1 px-3 py-2">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                        <span class="truncate text-xs font-medium text-gray-800">{{ row.label }}</span>
                        <span
                            v-if="row.percentage !== null && row.percentage !== undefined"
                            class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold"
                            :class="row.unlocked ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                        >
                            {{ row.percentage }}%
                        </span>
                        <!-- Ventas del mes (PCA del agente / PP del equipo) -->
                        <span
                            class="inline-flex items-center rounded border border-amber-200 bg-white px-1.5 py-0.5 text-[10px] font-medium text-gray-600"
                            :title="`${row.metric_label} vendida en ${row.label}`"
                        >
                            {{ row.metric_label }} {{ formatCurrency(row.month_metric) }}
                        </span>
                    </div>

                    <div class="flex flex-shrink-0 items-center gap-1.5">
                        <template v-if="row.editing">
                            <el-input
                                v-model="row.draft"
                                size="small"
                                class="w-32"
                                :formatter="formatAmount"
                                :parser="parseAmount"
                                :aria-label="`Anticipo de ${row.label}`"
                                @keyup.enter="save(row)"
                                @keyup.esc="cancelEdit(row)"
                            />
                            <button type="button" class="rounded-md p-1 text-green-600 hover:bg-green-50" title="Guardar anticipo" @click="save(row)">
                                <Check class="h-3.5 w-3.5" />
                            </button>
                            <button type="button" class="rounded-md p-1 text-gray-400 hover:bg-gray-100" title="Cancelar" @click="cancelEdit(row)">
                                <X class="h-3.5 w-3.5" />
                            </button>
                        </template>

                        <template v-else>
                            <span class="text-xs font-semibold text-gray-800">{{ formatCurrency(row.amount) }}</span>
                            <Check v-if="savedMonth === row.month" class="h-3.5 w-3.5 text-green-600" />
                            <button
                                v-if="editable"
                                type="button"
                                class="rounded-md p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                                title="Editar anticipo"
                                @click="startEdit(row)"
                            >
                                <Pencil class="h-3.5 w-3.5" />
                            </button>
                        </template>
                    </div>
                </div>

                <div class="flex items-center justify-between text-[10px] text-gray-500">
                    <span>
                        Acumulado: <span class="font-medium text-gray-700">{{ formatCurrency(row.cumulative_amount) }}</span>
                        <template v-if="row.is_override"> · Calculado {{ formatCurrency(row.calculated) }} </template>
                    </span>
                    <span v-if="row.previous_total > 0 && row.calculated > 0" class="text-amber-700">
                        Incluye {{ formatCurrency(row.previous_total) }} de meses anteriores
                    </span>
                    <span v-else-if="row.below_minimum" class="text-amber-700"> No alcanzó el mínimo ({{ formatCurrency(row.minimum) }}) </span>
                </div>
            </div>
        </div>
    </div>
</template>
