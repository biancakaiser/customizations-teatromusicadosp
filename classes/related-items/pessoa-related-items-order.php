<?php

namespace TeatroMusicadoSP\Customizations\RelatedItems;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\References\TainacanIds;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * O Tainacan não oferece um argumento nativo para ordenar os GRUPOS de itens
 * relacionados exibidos em um item (ex: "Membros", "Peças", "Espetáculos"),
 * apenas os itens dentro de cada grupo (via orderby/order de
 * tainacan_the_related_items). A ordem dos grupos vem de quais metadados de
 * Relacionamento (apontando para a coleção Pessoa) o Tainacan encontra,
 * na ordem em que a query em Repositories\Metadata::fetch() os devolve — sem
 * hook de ordenação disponível, e sem nenhum dado nativo que represente
 * "ordem entre coleções diferentes".
 *
 * Em vez de fixar IDs de metadados individuais (frágil: quebra se o campo for
 * recriado), ordenamos apenas pela COLEÇÃO dona de cada metadado de
 * Relacionamento (Metadatum::get_collection_id()), pelo ID da coleção — uma
 * estrutura muito mais estável que um campo isolado. Qualquer campo de
 * Relacionamento novo, criado numa das coleções listadas e apontando para
 * Pessoas, entra automaticamente no grupo da sua coleção, sem editar este
 * arquivo. Coleções fora da lista aparecem depois, na ordem que a busca
 * padrão do Tainacan devolver.
 *
 * A busca por esses metadados passa pelo filtro genérico 'tainacan-fetch-args'
 * (Repositories\Metadata::fetch(), com o 2º parâmetro 'metadata'), o mesmo
 * filtro usado por toda fetch() de qualquer repositório Tainacan. Por isso
 * identificamos aqui, pelo formato do meta_query, a query específica de
 * "quais campos de relacionamento apontam para a coleção Pessoas e devem
 * aparecer em itens relacionados" (a mesma montada em
 * Repositories\Items::fetch_related_items()), marcamos essa query e
 * reordenamos o resultado pelo filtro 'posts_results' — não há como ordenar
 * por um mapa arbitrário (collection_id -> posição) só com os argumentos do
 * WP_Query, então o reordenamos em PHP depois que os posts voltam do banco.
 *
 * Essa abordagem funciona independente de COMO a página do item é renderizada
 * (bloco tainacan/related-items-list, tainacan_the_related_items(), ou o
 * template do plugin tainacan-blocksy, que é quem efetivamente desenha a
 * seção "Itens relacionados a este" neste site — ver
 * wp-content/plugins/tainacan-blocksy/template-parts/tainacan-item-single-items-related-to-this.php),
 * porque todos esses caminhos passam por
 * Repositories\Items::fetch_related_items() -> Repositories\Metadata::fetch().
 */
class PessoaRelatedItemsOrder implements Module
{
    use Singleton;

    const PESSOA_COLLECTION_ID = TainacanIds::PESSOA;

    /**
     * Nome da query var usada só para marcar, dentro do próprio WP_Query, que
     * esta é a busca de "campos de relacionamento para Pessoas exibidos em
     * itens relacionados" — para o filtro posts_results reconhecê-la sem
     * afetar nenhuma outra query do site.
     */
    const QUERY_MARKER = 'tainacan_pessoa_related_items_group_order';

    /**
     * IDs das coleções donas dos metadados de Relacionamento, na ordem
     * desejada de exibição dos grupos.
     */
    const COLLECTIONS_ORDER = [
        TainacanIds::MEMBROS,
        TainacanIds::PECA,
        TainacanIds::ESPETACULOS,
    ];

    public function register(): void {
        add_filter( 'tainacan-fetch-args', array( $this, 'tag_pessoa_related_items_query' ), 10, 2 );
        add_filter( 'posts_results', array( $this, 'order_results_by_collection' ), 10, 2 );
    }

    public function tag_pessoa_related_items_query( $args, $context ) {

        if ( $context !== 'metadata' || empty( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
            return $args;
        }

        $is_relationship_type     = false;
        $display_in_related_items = false;
        $target_collection_id     = null;

        foreach ( $args['meta_query'] as $clause ) {

            if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
                continue;
            }

            if ( $clause['key'] === 'metadata_type' && ( $clause['value'] ?? null ) === 'Tainacan\Metadata_Types\Relationship' ) {
                $is_relationship_type = true;
            } elseif ( $clause['key'] === '_option_collection_id' ) {
                $target_collection_id = (int) ( $clause['value'] ?? 0 );
            } elseif ( $clause['key'] === '_option_display_in_related_items' && ( $clause['value'] ?? null ) === 'yes' ) {
                $display_in_related_items = true;
            }
        }

        $is_pessoa_related_items_query = $is_relationship_type
            && $display_in_related_items
            && $target_collection_id === self::PESSOA_COLLECTION_ID;

        if ( $is_pessoa_related_items_query ) {
            // Não é um argumento real do WP_Query: fica disponível como
            // query_var, só para o posts_results reconhecer esta query.
            $args[ self::QUERY_MARKER ] = true;
        }

        return $args;
    }

    public function order_results_by_collection( $posts, $query ) {

        if ( empty( $posts ) || ! $query->get( self::QUERY_MARKER ) ) {
            return $posts;
        }

        $priority_by_collection_id = array_flip( self::COLLECTIONS_ORDER );

        usort( $posts, function ( $a, $b ) use ( $priority_by_collection_id ) {
            $collection_a = (int) get_post_meta( $a->ID, 'collection_id', true );
            $collection_b = (int) get_post_meta( $b->ID, 'collection_id', true );

            $priority_a = $priority_by_collection_id[ $collection_a ] ?? PHP_INT_MAX;
            $priority_b = $priority_by_collection_id[ $collection_b ] ?? PHP_INT_MAX;

            return $priority_a <=> $priority_b;
        } );

        return $posts;
    }
}
