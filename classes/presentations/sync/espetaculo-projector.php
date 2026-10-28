<?php
/**
 * Projeção "item de Espetáculos do Tainacan → linha da tabela plana".
 *
 * Lê direto das tabelas do WordPress, numa única consulta por lote, os valores
 * que o Tainacan grava para os metadados mapeados (ver
 * `References\TainacanIds`): relacionamentos e escalares em `postmeta` (chave
 * = ID do metadado), taxonomias em `term_relationships`. A API do Tainacan
 * (`get_metadata()` item a item) daria o mesmo resultado, mas é ordens de
 * grandeza mais lenta para dezenas de milhares de itens.
 *
 * Normalização em 3 níveis: o Espetáculo aponta para uma Montagem e para o
 * Teatro, e é a Montagem que aponta para Peça e Companhia e guarda Idioma e
 * Tipo (Espetáculo → Montagem → Peça/Companhia).
 *
 * Regras (decididas com a equipe do acervo):
 *   - só entra espetáculo `publish` cuja Montagem, Peça, Companhia e Teatro
 *     também estejam `publish` — senão o espetáculo não aparece no resultado e
 *     a linha correspondente deve ser removida da tabela;
 *   - `presentationKind` guarda o valor do Tainacan como está;
 *   - campo vazio vira NULL (os metadados são obrigatórios no Tainacan);
 *   - valor múltiplo inesperado num campo simples: vale o de menor
 *     `meta_id` / `term_taxonomy_id`;
 *   - pessoas (autores/arranjadores da Peça, tradutores/adaptadores da
 *     Montagem): Nome Artístico (título) das Pessoas `publish`, em ordem
 *     alfabética — Pessoa não publicada é omitida, sem tirar o espetáculo.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Sync;

use TeatroMusicadoSP\Customizations\References\TainacanIds;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class EspetaculoProjector
{
    /**
     * Colunas técnicas de origem gravadas em cada linha (ver
     * `PresentationsSchema::technical_column_definitions()`); entram no hash.
     */
    const SOURCE_KEYS = [ 'espetaculo_id', 'legacy_id', 'montagem_id', 'montagem_legacy_id', 'play_id', 'company_id', 'theater_id' ];

    /**
     * Separador dos nomes no `GROUP_CONCAT` das colunas de pessoas (caractere de
     * controle que não ocorre em títulos); vira "; " no texto exibido.
     */
    const LIST_SEPARATOR = "\x1F";

    /**
     * Linhas projetadas dos espetáculos pedidos, indexadas por `espetaculo_id`.
     * IDs ausentes do resultado não devem estar na tabela.
     *
     * @param list<int> $espetaculo_ids
     * @return array<int,array<string,mixed>>
     */
    public function project( array $espetaculo_ids ): array {
        global $wpdb;

        $ids = self::clean_ids( $espetaculo_ids );
        if ( ! $ids ) {
            return [];
        }

        $in = implode( ',', $ids );

        $meta = static function ( string $post_alias, int $meta_id ) use ( $wpdb ): string {
            return "(SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$post_alias}.ID"
                . " AND pm.meta_key = '{$meta_id}' ORDER BY pm.meta_id ASC LIMIT 1)";
        };

        $term = static function ( string $post_alias, int $taxonomy_id ) use ( $wpdb ): string {
            $taxonomy = TainacanIds::taxonomy( $taxonomy_id );
            return "(SELECT t.name FROM {$wpdb->term_relationships} tr"
                . " JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                . " JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
                . " WHERE tr.object_id = {$post_alias}.ID AND tt.taxonomy = '{$taxonomy}'"
                . " ORDER BY tt.term_taxonomy_id ASC LIMIT 1)";
        };

        // Nomes das Pessoas publicadas que `$post_alias` referencia pelo metadado
        // (múltiplo) `$meta_id`, em ordem alfabética, separados por LIST_SEPARATOR.
        $people = static function ( string $post_alias, int $meta_id ) use ( $wpdb ): string {
            $pessoas = TainacanIds::item_post_type( TainacanIds::PESSOA );
            $separator = self::LIST_SEPARATOR;
            return "(SELECT GROUP_CONCAT(DISTINCT pe.post_title ORDER BY pe.post_title ASC SEPARATOR '{$separator}')"
                . " FROM {$wpdb->postmeta} pm"
                . " JOIN {$wpdb->posts} pe ON pe.ID = CAST(pm.meta_value AS UNSIGNED)"
                . " AND pe.post_type = '{$pessoas}' AND pe.post_status = 'publish'"
                . " WHERE pm.post_id = {$post_alias}.ID AND pm.meta_key = '{$meta_id}')";
        };

        // JOIN do item publicado que `$from` referencia pelo metadado `$meta_id`.
        $related = static function ( string $alias, string $from, int $meta_id, int $collection_id ) use ( $wpdb, $meta ): string {
            $post_type = TainacanIds::item_post_type( $collection_id );
            return "JOIN {$wpdb->posts} {$alias} ON {$alias}.ID = CAST({$meta( $from, $meta_id )} AS UNSIGNED)"
                . " AND {$alias}.post_type = '{$post_type}' AND {$alias}.post_status = 'publish'";
        };

        $espetaculos = TainacanIds::item_post_type( TainacanIds::ESPETACULOS );

        // Todos os identificadores interpolados vêm de TainacanIds (inteiros) e
        // os IDs pedidos passam por clean_ids(); não há entrada de usuário aqui.
        $sql = "SELECT
                e.ID AS espetaculo_id,
                {$meta( 'e', TainacanIds::META_SLUG_ID )} AS legacy_id,
                mo.ID AS montagem_id,
                {$meta( 'mo', TainacanIds::META_SLUG_ID )} AS montagem_legacy_id,
                pc.ID AS play_id,
                pc.post_title AS playName,
                {$term( 'pc', TainacanIds::TAX_GENERO )} AS playGenre,
                {$term( 'pc', TainacanIds::TAX_NACIONALIDADE )} AS playNationality,
                {$people( 'pc', TainacanIds::META_PECA_AUTORES_TEXTO )} AS playTextAuthors,
                {$people( 'pc', TainacanIds::META_PECA_AUTORES_MUSICA )} AS playMusicAuthors,
                {$people( 'pc', TainacanIds::META_PECA_ARRANJADORES )} AS playArrangers,
                co.ID AS company_id,
                co.post_title AS companyName,
                {$term( 'co', TainacanIds::TAX_NACIONALIDADE )} AS companyNationality,
                te.ID AS theater_id,
                te.post_title AS presentationTheater,
                {$meta( 'mo', TainacanIds::META_MONTAGEM_TIPO )} AS presentationKind,
                {$term( 'mo', TainacanIds::TAX_IDIOMA )} AS presentationLanguage,
                {$meta( 'e', TainacanIds::META_ESPETACULO_DATA )} AS presentationDate,
                {$meta( 'e', TainacanIds::META_ESPETACULO_SESSOES )} AS presentationSessionsN,
                {$people( 'mo', TainacanIds::META_MONTAGEM_TRADUTORES )} AS presentationTranslators,
                {$people( 'mo', TainacanIds::META_MONTAGEM_ADAPTADORES )} AS presentationAdapters
            FROM {$wpdb->posts} e
            {$related( 'mo', 'e', TainacanIds::META_ESPETACULO_MONTAGEM, TainacanIds::MONTAGEM )}
            {$related( 'pc', 'mo', TainacanIds::META_MONTAGEM_PECA, TainacanIds::PECA )}
            {$related( 'co', 'mo', TainacanIds::META_MONTAGEM_COMPANHIA, TainacanIds::COMPANHIA )}
            {$related( 'te', 'e', TainacanIds::META_ESPETACULO_TEATRO, TainacanIds::TEATRO )}
            WHERE e.ID IN ({$in})
              AND e.post_type = '{$espetaculos}'
              AND e.post_status = 'publish'";

        // O padrão (1024 bytes) truncaria em silêncio uma lista longa de pessoas.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( 'SET SESSION group_concat_max_len = 65535' );

        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $raw = $wpdb->get_results( $sql, ARRAY_A );
        if ( '' !== $wpdb->last_error ) {
            throw new \RuntimeException( 'Projeção de espetáculos falhou: ' . $wpdb->last_error );
        }

        $rows = [];
        foreach ( $raw ?: [] as $r ) {
            $row = self::normalize( $r );
            $rows[ $row['espetaculo_id'] ] = $row;
        }

        return $rows;
    }

    /**
     * Converte a linha crua do SQL nos tipos da tabela e calcula o hash.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function normalize( array $r ): array {
        $row = [
            'espetaculo_id'      => (int) $r['espetaculo_id'],
            'legacy_id'          => self::to_int( $r['legacy_id'] ),
            'montagem_id'        => (int) $r['montagem_id'],
            'montagem_legacy_id' => self::to_int( $r['montagem_legacy_id'] ),
            'play_id'            => (int) $r['play_id'],
            'company_id'         => (int) $r['company_id'],
            'theater_id'         => (int) $r['theater_id'],
        ];

        foreach ( PresentationsSchema::columns() as $key => $column ) {
            $value = $r[ $key ] ?? null;

            if ( $column->multi_value ) {
                $names                      = self::to_list( $value );
                $row[ $key ]                = $names ? implode( '; ', $names ) : null;
                $row[ $column->list_key() ] = $names ? (string) wp_json_encode( $names, JSON_UNESCAPED_UNICODE ) : null;
            } elseif ( $column->is_numeric() ) {
                $row[ $key ] = self::to_int( $value );
            } elseif ( $column->is_date() ) {
                $row[ $key ] = self::to_datetime( $value );
            } else {
                $row[ $key ] = self::to_text( $value );
            }
        }

        $row['source_hash'] = self::hash( $row );

        return $row;
    }

    /**
     * Hash do conteúdo (colunas exibidas + IDs de origem), usado pela
     * conferência para detectar linhas divergentes.
     *
     * @param array<string,mixed> $row
     */
    public static function hash( array $row ): string {
        $keys = array_merge( self::SOURCE_KEYS, PresentationsSchema::keys() );

        $values = [];
        foreach ( $keys as $key ) {
            $values[ $key ] = isset( $row[ $key ] ) ? (string) $row[ $key ] : null;
        }

        return md5( (string) wp_json_encode( $values ) );
    }

    private static function to_text( $value ): ?string {
        if ( null === $value ) {
            return null;
        }
        $value = trim( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        return '' === $value ? null : $value;
    }

    /**
     * Lista do `GROUP_CONCAT` de pessoas → nomes limpos, sem vazios.
     *
     * @return list<string>
     */
    private static function to_list( $value ): array {
        if ( null === $value ) {
            return [];
        }
        return array_values( array_filter(
            array_map( [ self::class, 'to_text' ], explode( self::LIST_SEPARATOR, (string) $value ) ),
            static fn( ?string $name ): bool => null !== $name
        ) );
    }

    private static function to_int( $value ): ?int {
        if ( null === $value || '' === trim( (string) $value ) || ! is_numeric( trim( (string) $value ) ) ) {
            return null;
        }
        return (int) trim( (string) $value );
    }

    /**
     * Data do Tainacan (`Y-m-d`) → `Y-m-d 00:00:00`; inválida → NULL.
     */
    private static function to_datetime( $value ): ?string {
        if ( null === $value || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', trim( (string) $value ), $m ) ) {
            return null;
        }
        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return null;
        }
        return "{$m[1]}-{$m[2]}-{$m[3]} 00:00:00";
    }

    /**
     * Itens da coleção `$collection_id` (qualquer status) que apontam, pelo
     * metadado de relacionamento `$meta_id`, para algum dos itens
     * `$related_ids` — ex.: montagens de uma peça, espetáculos de uma montagem.
     * Busca no postmeta — não na tabela plana — para achar também espetáculos
     * que hoje estão fora dela (ex.: a peça acabou de ser publicada).
     *
     * @param list<int> $related_ids
     * @return list<int>
     */
    public function items_referencing( int $collection_id, int $meta_id, array $related_ids ): array {
        global $wpdb;

        $ids = self::clean_ids( $related_ids );
        if ( ! $ids ) {
            return [];
        }

        $in        = "'" . implode( "','", $ids ) . "'";
        $post_type = TainacanIds::item_post_type( $collection_id );

        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        return array_map( 'intval', $wpdb->get_col(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = '{$post_type}'
             WHERE pm.meta_key = '{$meta_id}' AND pm.meta_value IN ({$in})"
        ) );
    }

    /**
     * Itens (qualquer coleção) vinculados aos termos dados, agrupados por post type.
     *
     * @param list<int> $term_taxonomy_ids
     * @return array<string,list<int>> post_type => IDs
     */
    public function objects_with_terms( array $term_taxonomy_ids ): array {
        global $wpdb;

        $ids = self::clean_ids( $term_taxonomy_ids );
        if ( ! $ids ) {
            return [];
        }

        $in = implode( ',', $ids );

        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            "SELECT DISTINCT p.ID, p.post_type FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tr.term_taxonomy_id IN ({$in})",
            ARRAY_A
        );

        $by_type = [];
        foreach ( $rows ?: [] as $row ) {
            $by_type[ $row['post_type'] ][] = (int) $row['ID'];
        }
        return $by_type;
    }

    /**
     * Próximo lote de IDs de espetáculos publicados, em ordem crescente.
     *
     * @return list<int>
     */
    public function published_ids_after( int $after_id, int $limit ): array {
        global $wpdb;

        $espetaculos = TainacanIds::item_post_type( TainacanIds::ESPETACULOS );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
            $espetaculos,
            $after_id,
            $limit
        ) ) );
    }

    public function published_count(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            TainacanIds::item_post_type( TainacanIds::ESPETACULOS )
        ) );
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    public static function clean_ids( array $ids ): array {
        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }
}
