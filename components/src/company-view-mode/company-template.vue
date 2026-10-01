<template>
    <div class="cast-history-wrapper">

        <!-- SKELETON LOADING -->
        <div
            v-if="isLoading"
            class="cast-history cast-history--loading">
            <span
                class="sr-only"
                role="status">
                Carregando conteúdo…
            </span>
            <div class="cast-history__header">
                <div class="skeleton-bar skeleton-bar--eyebrow" />
                <div class="skeleton-bar skeleton-bar--title" />
            </div>
            <div class="period-accordion">
                <div
                    v-for="skeletonIndex in 4"
                    :key="skeletonIndex"
                    class="period-accordion__item period-accordion__item--skeleton">
                    <div class="skeleton-bar skeleton-bar--trigger" />
                </div>
            </div>
        </div>

        <p
            v-else-if="recordCount == 0"
            class="cast-history__empty has-text-grey is-italic">
            {{ $i18n.get('label_no_items_found') }}
        </p>

        <!-- ACCORDION VIEW MODE: período > função > pessoas -->
        <section
            v-else
            class="cast-history">
            <header class="cast-history__header">
                <h2 class="cast-history__title">Membros da Companhia</h2>
                <p class="cast-history__summary">{{ recordCountLabel }}</p>
            </header>

            <div class="period-accordion">
                <section
                    v-for="record in recordAccordionItems"
                    :key="record.key"
                    class="period-accordion__item">
                    <h3 class="period-accordion__heading">
                        <button
                            :id="record.headerId"
                            type="button"
                            class="period-accordion__trigger"
                            :aria-expanded="isOpen(record.key)"
                            :aria-controls="record.panelId"
                            @click="toggleOpen(record.key)">
                            <span class="period-accordion__label">{{ record.dateRangeLabel }}</span>
                            <span class="period-accordion__summary">
                                <span class="period-accordion__count period-accordion__count--people"> | {{ getPersonCountLabel(record.personCount) }}</span>
                                <span class="period-accordion__count period-accordion__count--roles"> | {{ getRoleCountLabel(record.roleGroups.length) }}</span>
                            </span>
                            <span
                                class="period-accordion__icon"
                                aria-hidden="true" />
                        </button>
                    </h3>
                    <div
                        v-show="isOpen(record.key)"
                        :id="record.panelId"
                        class="period-accordion__panel"
                        role="region"
                        :aria-expanded="isOpen(record.key)"
                        :aria-labelledby="record.headerId">

                        <div
                            v-if="record.roleGroups.length"
                            class="role-accordion">
                            <section
                                v-for="role in record.roleGroups"
                                :key="role.key"
                                class="role-accordion__item">
                                <h4 class="role-accordion__heading">
                                    <button
                                        :id="role.headerId"
                                        type="button"
                                        class="role-accordion__trigger"
                                        :aria-expanded="isOpen(role.key)"
                                        :aria-controls="role.panelId"
                                        @click="toggleOpen(role.key)">
                                        <span class="role-accordion__label">{{ role.label }}</span>
                                        <span class="role-accordion__count">{{ getPersonCountLabel(role.members.length) }}</span>
                                        <span
                                            class="role-accordion__icon"
                                            aria-hidden="true" />
                                    </button>
                                </h4>
                                <div
                                    v-show="isOpen(role.key)"
                                    :id="role.panelId"
                                    class="role-accordion__panel"
                                    role="region"
                                    :aria-expanded="isOpen(role.key)"
                                    :aria-labelledby="role.headerId">
                                    <ul class="person-list">
                                        <li
                                            v-for="person in role.members"
                                            :key="person.key"
                                            class="person-list__item">
                                            <span
                                                v-if="person.personHtml"
                                                class="person-list__name"
                                                v-html="person.personHtml" />
                                            <span
                                                v-else
                                                class="person-list__name person-list__name--placeholder">{{ person.personText || 'Pessoa não informada' }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </section>
                        </div>
                        <p
                            v-else
                            class="role-accordion__empty">Nenhum elenco registrado</p>
                    </div>
                </section>
            </div>
        </section>
    </div>
</template>

<script>
import { metadatumId } from '../tainacan-ids.js';

/*
 * Metadados filhos do campo composto "Elenco" (slug: elenco) da coleção
 * Membros: Pessoa e Função (IDs na referência única do plugin,
 * classes/references/tainacan-ids.php). Usados para localizar cada subcampo
 * dentro de uma repetição por id em vez de por posição, já que a ordem só é
 * garantida pela configuração atual do composto no Tainacan.
 */

export default {
    name: 'CompanyViewMode',
    data() {
        return {
            noComponent: "Nenhum componente encontrado!",
            openKeys: {}
        }
    },
    props: {
        collectionId: [String, Number],
        termId: [String, Number],
        displayedMetadata: Array,
        shouldHideItemsThumbnail: Boolean,
        items: {
            type: Array,
            default: () => [],
            required: true
        },
        isLoading: Boolean,
        totalItems: Number,
        enabledViewModes: Array,
        containerId: String,
    },
    computed: {
        metadataColumns() {
            return this.displayedMetadata || [];
        },
        dateColumns() {
            return this.metadataColumns.filter(column => column.metadata_type_object && column.metadata_type_object.primitive_type == 'date');
        },
        initialDateColumn() {
            return this.findColumnByPattern(this.dateColumns, /inicial/i) || this.dateColumns[0] || false;
        },
        finalDateColumn() {
            return this.findColumnByPattern(this.dateColumns, /final/i) || this.dateColumns[0] || false;
        },
        castColumn() {
            const compoundColumns = this.metadataColumns.filter(column => column.metadata_type_object && column.metadata_type_object.primitive_type == 'compound');
            return this.findColumnByPattern(compoundColumns, /elenco/i) || compoundColumns[0] || false;
        },
        recordCount() {
            return (this.items || []).length;
        },
        recordCountLabel() {
            return this.recordCount == 1 ? '1 vínculo registrado' : this.recordCount + ' vínculos registrados';
        },
        /*
         * Cada registro de this.items já corresponde a um único período (uma
         * companhia + um intervalo de datas), então o 1º nível do accordion é
         * montado diretamente a partir dos itens, sem agrupamento.
         */
        recordAccordionItems() {
            return (this.items || [])
                .map((item, index) => {
                    const key = 'record-' + (item.id != undefined ? item.id : index);
                    const roleGroups = this.getRoleGroups(item, key);
                    return {
                        key,
                        headerId: this.getAccordionHeaderId(key),
                        panelId: this.getAccordionPanelId(key),
                        dateRangeLabel: this.getItemDateRangeLabel(item),
                        finalDateSortValue: this.getFinalDateSortValue(item),
                        roleGroups,
                        personCount: this.getPersonCount(roleGroups)
                    };
                })
                .sort((firstRecord, secondRecord) => firstRecord.finalDateSortValue - secondRecord.finalDateSortValue);
        }
    },
    methods: {
        isOpen(key) {
            return !!this.openKeys[key];
        },
        toggleOpen(key) {
            this.openKeys[key] = !this.openKeys[key];
        },
        getAccordionHeaderId(key) {
            return (this.containerId || 'cast-history') + '-header-' + key;
        },
        getAccordionPanelId(key) {
            return (this.containerId || 'cast-history') + '-panel-' + key;
        },
        getRoleCountLabel(count) {
            return count == 1 ? '1 função' : count + ' funções';
        },
        getPersonCountLabel(count) {
            return count == 1 ? '1 pessoa' : count + ' pessoas';
        },
        /*
         * Soma as pessoas de todas as Funções de um período, usada no 1º
         * nível do accordion (member.length de cada role já filtra
         * repetições sem pessoa/função em getRoleGroups).
         */
        getPersonCount(roleGroups) {
            return (roleGroups || []).reduce((total, role) => total + role.members.length, 0);
        },
        findColumnByPattern(columns, pattern) {
            return (columns || []).find(column => {
                const name = column && column.name ? this.stripHtml(column.name).trim() : '';
                const slug = column && column.slug ? column.slug.toString().trim() : '';
                return pattern.test(name) || pattern.test(slug);
            }) || false;
        },
        getColumnMetadata(item, column) {
            if (!item || !item.metadata || !column)
                return false;

            const possibleKeys = [
                column.slug,
                column.metadatum,
                column.id
            ].filter(possibleKey => possibleKey != undefined && possibleKey !== '');

            for (let index = 0; index < possibleKeys.length; index++) {
                const possibleKey = possibleKeys[index];

                if (item.metadata[possibleKey] != undefined)
                    return item.metadata[possibleKey];
            }

            return false;
        },
        getDateValue(metadata) {
            return (metadata && metadata.value_as_string) ? metadata.value_as_string : '';
        },
        /*
         * Usa o valor bruto (não formatado) da metadata de data final para
         * ordenar cronologicamente, já que value_as_string segue o formato de
         * exibição configurado na coleção e pode não ser ordenável como texto.
         * Itens sem data final válida vão para o fim da lista.
         */
        getFinalDateSortValue(item) {
            const metadata = this.getColumnMetadata(item, this.finalDateColumn);
            const rawValue = metadata ? (metadata.value || metadata.value_as_string || '') : '';
            const timestamp = Date.parse(rawValue);

            return isNaN(timestamp) ? Number.POSITIVE_INFINITY : timestamp;
        },
        getItemDateRangeLabel(item) {
            const start = this.getDateValue(this.getColumnMetadata(item, this.initialDateColumn));
            const end = this.getDateValue(this.getColumnMetadata(item, this.finalDateColumn));

            if (start && end && start != end)
                return start + ' á ' + end;

            return start || end || 'Data não informada';
        },
        /*
         * Localiza o subcampo (Pessoa/Função) de uma repetição do composto
         * "Elenco" pelo metadatum_id, para não depender da posição em que
         * cada campo aparece na resposta da API. fallbackIndex cobre apenas
         * o caso raro de a API não trazer metadatum_id no subcampo.
         */
        getCastSubfield(repetitionFields, metadatumId, fallbackIndex) {
            const fields = repetitionFields || [];
            return fields.find(field => field && Number(field.metadatum_id) === metadatumId) || fields[fallbackIndex] || false;
        },
        /*
         * Agrupa as repetições do composto "Elenco" por Função, na ordem em
         * que aparecem nos dados. Dentro de cada Função, lista as Pessoas
         * também na ordem de aparição, sem remover eventuais duplicatas.
         */
        getRoleGroups(item, itemKey) {
            const metadata = this.getColumnMetadata(item, this.castColumn);
            const repetitions = (metadata && Array.isArray(metadata.value)) ? metadata.value : [];

            const groups = [];
            const groupsByKey = {};

            repetitions.forEach((repetitionFields, repetitionIndex) => {
                const personField = this.getCastSubfield(repetitionFields, metadatumId('membrosElencoPessoa'), 0);
                const roleField = this.getCastSubfield(repetitionFields, metadatumId('membrosElencoFuncao'), 1);

                const personHtml = personField ? (personField.value_as_html || '') : '';
                const personText = personField ? (personField.value_as_string || '') : '';
                const roleText = roleField ? (roleField.value_as_string || '') : '';

                if (!personText && !personHtml && !roleText)
                    return;

                const roleGroupKey = (roleField && roleField.value != undefined && roleField.value !== '')
                    ? 'role-' + roleField.value
                    : 'role-label-' + roleText.toLowerCase();

                if (!groupsByKey[roleGroupKey]) {
                    const groupKey = itemKey + '-' + roleGroupKey;
                    groupsByKey[roleGroupKey] = {
                        key: groupKey,
                        label: roleText || 'Função não informada',
                        headerId: this.getAccordionHeaderId(groupKey),
                        panelId: this.getAccordionPanelId(groupKey),
                        members: []
                    };
                    groups.push(groupsByKey[roleGroupKey]);
                }

                groupsByKey[roleGroupKey].members.push({
                    key: itemKey + '-' + roleGroupKey + '-person-' + repetitionIndex,
                    personHtml,
                    personText
                });
            });

            groups.forEach(group => {
                group.members.sort((firstMember, secondMember) => this.compareAlphabetically(
                    firstMember.personText || this.stripHtml(firstMember.personHtml),
                    secondMember.personText || this.stripHtml(secondMember.personHtml)
                ));
            });

            groups.sort((firstGroup, secondGroup) => this.compareAlphabetically(firstGroup.label, secondGroup.label));

            return groups;
        },
        compareAlphabetically(firstText, secondText) {
            return (firstText || '').localeCompare(secondText || '', 'pt-BR', { sensitivity: 'base' });
        },
        stripHtml(html) {
            if (!html)
                return '';

            if (typeof document == 'undefined')
                return html.toString().replace(/<[^>]*>/g, '').trim();

            const temporaryElement = document.createElement('div');
            temporaryElement.innerHTML = html;

            return (temporaryElement.textContent || temporaryElement.innerText || '').trim();
        }
    }
}
</script>
