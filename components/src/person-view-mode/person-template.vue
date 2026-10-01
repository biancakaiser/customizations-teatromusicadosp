<template>
    <component
        :is="resolvedTemplate"
        v-bind="$props" />
</template>

<script>
import MembersTemplate from './members-template.vue';
import ConceptionTemplate from './conception-template.vue';
import { collectionId as tainacanCollectionId } from '../tainacan-ids.js';

/*
 * Coleções cujos metadados de Relacionamento apontam para Pessoa e aparecem
 * agrupados na seção "Itens relacionados a este" do item de Pessoa (Membros,
 * Peça, Espetáculos). IDs vêm da referência única do plugin
 * (classes/references/tainacan-ids.php), a mesma usada em
 * classes/related-items/pessoa-related-items-order.php, que ordena esses
 * mesmos grupos por coleção.
 */

export default {
    name: 'PersonViewMode',
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
            noComponent: "Nenhum componente encontrado!",
        }
    },
    computed: {
        /*
         * Tainacan monta uma instância Vue independente por coleção
         * relacionada (cada grupo "Membros"/"Peças"/"Espetáculos" tem seu
         * próprio data-collection-id), então a prop collectionId já chega
         * aqui identificando de qual coleção relacionada este grupo é.
         */
        resolvedTemplate() {
            const collectionId = Number(this.collectionId);

            if (collectionId === tainacanCollectionId('membros'))
                return MembersTemplate;

            if (collectionId === tainacanCollectionId('peca') || collectionId === tainacanCollectionId('espetaculos'))
                return ConceptionTemplate;

            return this.noComponent;
        }
    }
}
</script>
