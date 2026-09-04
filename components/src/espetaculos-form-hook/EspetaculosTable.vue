<template>
    <div class="teatro-espetaculos-table-wrapper">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col">Teatro</th>
                    <th scope="col">Data</th>
                    <th scope="col">Tipo</th>
                    <th scope="col">Nº de Sessões</th>
                    <th scope="col" class="column-actions">Ações</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    :class="rowClasses(row)"
                    @focusin="onRowFocusIn(row)"
                    @focusout="onRowFocusOut(row, $event)"
                >
                    <td>
                        <select v-model.number="row.teatroId" @change="onRowEdited(row)">
                            <option
                                v-for="teatro in TEATROS_MOCK"
                                :key="teatro.id"
                                :value="teatro.id"
                            >
                                {{ teatro.nome }}
                            </option>
                        </select>
                    </td>
                    <td>
                        <input
                            type="date"
                            v-model="row.data"
                            :ref="el => setDateRef(row.id, el)"
                            @input="onRowEdited(row)"
                        />
                    </td>
                    <td>
                        <select v-model="row.tipo" @change="onRowEdited(row)">
                            <option v-for="tipo in TIPOS_ESPETACULO" :key="tipo" :value="tipo">
                                {{ tipo }}
                            </option>
                        </select>
                    </td>
                    <td>
                        <input
                            type="number"
                            min="1"
                            v-model.number="row.sessoesNumero"
                            @input="onRowEdited(row)"
                        />
                    </td>
                    <td class="column-actions">
                        <template v-if="row.confirmingRemove">
                            <span class="teatro-confirm-remove">
                                Remover esta linha?
                                <button type="button" class="button button-link-delete" @click="confirmRemove(row)">Sim</button>
                                <button type="button" class="button" @click="cancelRemove(row)">Cancelar</button>
                            </span>
                        </template>
                        <template v-else>
                            <button type="button" class="button" @click="duplicateRow(row)">Duplicar</button>
                            <button type="button" class="button button-link-delete" @click="askRemove(row)">Remover</button>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="description teatro-espetaculos-footer">
            {{ rows.length }} linha(s) —
            <span class="teatro-legend-editing">■</span> editando
            <span class="teatro-legend-duplicated">■</span> recém-duplicada
        </p>
    </div>
</template>

<script>
import { TEATROS_MOCK, TIPOS_ESPETACULO, ESPETACULOS_MOCK } from './mock-espetaculos.js';

export default {
    name: 'EspetaculosTable',
    data() {
        return {
            TEATROS_MOCK,
            TIPOS_ESPETACULO,
            rows: ESPETACULOS_MOCK.map((row) => ({
                ...row,
                justDuplicated: false,
                confirmingRemove: false,
            })),
            editingRowId: null,
            dateInputRefs: {},
        };
    },
    methods: {
        rowClasses(row) {
            return {
                'teatro-row-editing': this.editingRowId === row.id,
                'teatro-row-duplicated': row.justDuplicated && this.editingRowId !== row.id,
            };
        },
        onRowFocusIn(row) {
            this.editingRowId = row.id;
        },
        onRowFocusOut(row, event) {
            // Ao trocar de campo dentro da mesma linha (ex: Teatro -> Data),
            // o blur de um campo dispara antes do focus do próximo. Só sai do
            // estado "editando" quando o foco realmente deixa a linha.
            if (event.currentTarget.contains(event.relatedTarget)) {
                return;
            }
            if (this.editingRowId === row.id) {
                this.editingRowId = null;
            }
        },
        onRowEdited(row) {
            row.justDuplicated = false;
        },
        nextId() {
            return this.rows.reduce((max, row) => Math.max(max, row.id), 0) + 1;
        },
        duplicateRow(row) {
            const newRow = {
                id: this.nextId(),
                teatroId: row.teatroId,
                tipo: row.tipo,
                data: '',
                sessoesNumero: row.sessoesNumero,
                justDuplicated: true,
                confirmingRemove: false,
            };
            const index = this.rows.findIndex((r) => r.id === row.id);
            this.rows.splice(index + 1, 0, newRow);
            this.$nextTick(() => {
                const el = this.dateInputRefs[newRow.id];
                if (el) {
                    el.focus();
                }
            });
        },
        setDateRef(rowId, el) {
            if (el) {
                this.dateInputRefs[rowId] = el;
            } else {
                delete this.dateInputRefs[rowId];
            }
        },
        askRemove(row) {
            row.confirmingRemove = true;
        },
        cancelRemove(row) {
            row.confirmingRemove = false;
        },
        confirmRemove(row) {
            this.rows = this.rows.filter((r) => r.id !== row.id);
        },
    },
};
</script>
