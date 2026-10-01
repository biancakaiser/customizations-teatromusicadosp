<template>
    <div class="conception-wrapper">

        <!-- SKELETON LOADING -->
        <div
            v-if="isLoading"
            class="conception-list conception-list--loading">
            <span
                class="sr-only"
                role="status">
                Carregando conteúdo…
            </span>
            <div
                v-for="skeletonIndex in 4"
                :key="skeletonIndex"
                class="conception-list__item conception-list__item--skeleton">
                <div class="skeleton-bar skeleton-bar--title" />
            </div>
        </div>

        <p
            v-else-if="!items || !items.length"
            class="conception-list__empty has-text-grey is-italic">
            {{ $i18n.get('label_no_items_found') }}
        </p>

        <!-- ACCORDION VIEW MODE -->
        <section
            v-else
            class="conception-accordion">
            <h3 class="conception-accordion__heading">
                <button
                    :id="accordionHeaderId"
                    type="button"
                    class="conception-accordion__trigger"
                    :aria-expanded="isExpanded"
                    :aria-controls="accordionPanelId"
                    @click="toggleGroup">
                    <span
                        v-if="relatedMetadataLabel"
                        class="conception-accordion__label"
                        v-html="relatedMetadataLabel" />
                    <span class="conception-accordion__count">  |  {{ itemsCountLabel }}</span>
                    <span
                        class="conception-accordion__icon"
                        aria-hidden="true" />
                </button>
            </h3>
            <div
                v-show="isExpanded"
                :id="accordionPanelId"
                class="conception-accordion__panel"
                role="region"
                :aria-expanded="isExpanded"
                :aria-labelledby="accordionHeaderId">
                <ul
                    class="conception-list"
                    role="list">
                    <li
                        v-for="(item, index) in sortedItems"
                        :key="item.id != undefined ? item.id : index"
                        class="conception-list__item"
                        :data-tainacan-item-id="item.id">
                        <a
                            v-if="item.url"
                            :href="item.url"
                            class="conception-list__title"
                            v-html="getItemTitleHtml(item)" />
                        <span
                            v-else
                            class="conception-list__title"
                            v-html="getItemTitleHtml(item)" />
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>

<script>
import { collectionId as tainacanCollectionId } from '../tainacan-ids.js';

/*
 * Peça e Espetáculos: mesmas coleções usadas em person-template.vue
 * para decidir que ambas as coleções renderizam ConceptionTemplate. O Tainacan
 * (Theme_Helper::get_tainacan_related_items_list()) gera um .wp-block-group e
 * um <h3> por coleção relacionada, sem nenhum filtro para unir grupos de
 * coleções diferentes sob um título comum. Por isso o título é inserido aqui,
 * uma única vez, antes do primeiro desses grupos a montar (ver
 * insertSharedConceptionTitle) — sem mexer nos grupos/headings originais.
 */
const SHARED_GROUP_TITLE = 'Peças de cujas concepção participou';

export default {
    name: 'ConceptionTemplate',
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
    data() {
        return {
            /*
             * Tainacan (Theme_Helper::get_tainacan_related_items_list())
             * escreve o id do metadado de Relacionamento que originou este
             * grupo no atributo data-related-metadata-id do wp-block-group
             * que envolve o ponto de montagem deste componente. É a fonte
             * confiável de "qual campo relacionou este grupo de itens" —
             * não chega como prop, então é lida direto do DOM depois do
             * mount. Repositories\Items::fetch_related_items() monta um
             * grupo por metadado de Relacionamento, então todo o conjunto de
             * items desta instância pertence sempre ao mesmo campo.
             */
            relatedMetadataId: null,
            isExpanded: false
        }
    },
    mounted() {
        const relatedItemsGroupElement = this.$el && typeof this.$el.closest == 'function'
            ? this.$el.closest('[data-related-metadata-id]')
            : null;

        if (relatedItemsGroupElement)
            this.relatedMetadataId = relatedItemsGroupElement.getAttribute('data-related-metadata-id');

        this.insertSharedConceptionTitle(relatedItemsGroupElement);
    },
    computed: {
        /*
         * Na página de uma Pessoa, a URL carrega o slug dela
         * (.../pessoas/{slug}/). Usado apenas como fallback, caso
         * relatedMetadataId não esteja disponível, para identificar qual das
         * colunas de relacionamento da peça de fato liga os items desta
         * lista a esta pessoa (ex: "Tradutor" ou "Arranjador").
         */
        currentPersonSlug() {
            if (typeof window == 'undefined' || !window.location || !window.location.pathname)
                return '';

            const match = window.location.pathname.match(/\/pessoas\/([^/]+)\/?/i);
            return match ? decodeURIComponent(match[1]).toLowerCase() : '';
        },
        relationshipColumns() {
            return (this.displayedMetadata || []).filter(column => column && column.metadata_type_object && column.metadata_type_object.primitive_type == 'item');
        },
        titleColumn() {
            return this.findColumnByPattern(this.displayedMetadata, /titulo.*pec/i);
        },
        /*
         * Campo de relacionamento único desta lista (mesmo campo para todos
         * os items, ver nota em relatedMetadataId).
         */
        relationshipColumn() {
            const relationshipColumns = this.relationshipColumns;

            if (!relationshipColumns.length)
                return false;

            if (this.relatedMetadataId != undefined && this.relatedMetadataId !== '') {
                const groupColumn = relationshipColumns.find(column => column && column.id != undefined && String(column.id) == String(this.relatedMetadataId));

                if (groupColumn)
                    return groupColumn;
            }

            if (this.currentPersonSlug) {
                const matchingColumn = relationshipColumns.find(column => (this.items || []).some(item => {
                    const metadata = this.getColumnMetadata(item, column);
                    return metadata && metadata.value_as_html && this.getPersonHtmlSlugs(metadata.value_as_html).includes(this.currentPersonSlug);
                }));

                if (matchingColumn)
                    return matchingColumn;
            }

            return relationshipColumns[0];
        },
        relatedMetadataLabel() {
            return this.relationshipColumn ? this.relationshipColumn.name : '';
        },
        accordionHeaderId() {
            return (this.containerId || 'conception') + '-accordion-header';
        },
        accordionPanelId() {
            return (this.containerId || 'conception') + '-accordion-panel';
        },
        itemsCountLabel() {
            const count = (this.items || []).length;
            return count == 1 ? '1 peça' : count + ' peças';
        },
        sortedItems() {
            return (this.items || []).slice().sort((firstItem, secondItem) => this.compareAlphabetically(
                this.stripHtml(this.getItemTitleHtml(firstItem)),
                this.stripHtml(this.getItemTitleHtml(secondItem))
            ));
        }
    },
    methods: {
        toggleGroup() {
            this.isExpanded = !this.isExpanded;
        },
        /*
         * Peças e Espetáculos chegam como dois .wp-block-group irmãos,
         * renderizados pelo Tainacan (ver comentário no topo do arquivo).
         * Em vez de reagrupá-los, apenas insere um único título comum antes
         * do primeiro desses grupos a montar. Cada instância de
         * ConceptionTemplate chama isto no seu próprio mount, em qualquer
         * ordem, mas só a primeira encontra a lista sem o título e o insere.
         */
        insertSharedConceptionTitle(groupElement) {
            const collectionId = Number(this.collectionId);

            if (!groupElement || (collectionId !== tainacanCollectionId('peca') && collectionId !== tainacanCollectionId('espetaculos')))
                return;

            const relatedItemsList = groupElement.closest('[data-module="related-items-list"]');

            if (!relatedItemsList || relatedItemsList.querySelector('.conception-shared-group-title'))
                return;

            const heading = document.createElement('h2');
            heading.className = 'related-items-collection-name conception-shared-group-title';
            heading.textContent = SHARED_GROUP_TITLE;

            relatedItemsList.insertBefore(heading, groupElement);
        },
        normalizeForMatch(value) {
            let normalized = (value || '').toString().trim().toLowerCase();

            if (typeof normalized.normalize == 'function')
                normalized = normalized.normalize('NFD').replace(new RegExp('[\\u0300-\\u036f]', 'g'), '');

            return normalized;
        },
        findColumnByPattern(columns, pattern) {
            return (columns || []).find(column => {
                const name = column && column.name ? this.normalizeForMatch(column.name) : '';
                const slug = column && column.slug ? this.normalizeForMatch(column.slug) : '';
                return pattern.test(name) || pattern.test(slug);
            }) || false;
        },
        /*
         * Extrai, dos links dentro do value_as_html de um metadado de
         * Relacionamento, os slugs de Pessoa referenciados (mesma lógica de
         * members-template.vue), para achar qual campo de relacionamento da
         * peça aponta para a pessoa da página atual.
         */
        getPersonHtmlSlugs(personHtml) {
            if (!personHtml || typeof document == 'undefined')
                return [];

            const container = document.createElement('div');
            container.innerHTML = personHtml;

            return Array.from(container.querySelectorAll('a[href]'))
                .map(anchor => {
                    const match = (anchor.getAttribute('href') || '').match(/\/pessoas\/([^/]+)\/?/i);
                    return match ? decodeURIComponent(match[1]).toLowerCase() : '';
                })
                .filter(slug => slug !== '');
        },
        getItemTitleHtml(item) {
            return this.getMetadataValueAsHtml(item, this.titleColumn) || (item && item.title ? item.title : '');
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
        getMetadataValueAsHtml(item, column) {
            const metadata = this.getColumnMetadata(item, column);

            if (!metadata)
                return '';

            if (metadata.value_as_html)
                return metadata.value_as_html;

            if (metadata.value_as_string)
                return metadata.value_as_string;

            return '';
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
