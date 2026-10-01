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
 * Regras (decididas com a equipe do acervo):
 *   - só entra espetáculo `publish` cuja Peça, Companhia e Teatro também
 *     estejam `publish` — senão o espetáculo não aparece no resultado e a
 *     linha correspondente deve ser removida da tabela;
 *   - `presentationKind` guarda o valor do Tainacan como está;
 *   - campo vazio vira NULL (os metadados são obrigatórios no Tainacan);
 *   - valor múltiplo inesperado num campo simples: vale o de menor
 *     `meta_id` / `term_taxonomy_id`.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Sync;

use TeatroMusicadoSP\Customizations\References\TainacanIds;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class EspetaculoProjector
{
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

        $related = static function ( string $alias, int $meta_id, int $collection_id ) use ( $wpdb, $meta ): string {
            $post_type = TainacanIds::item_post_type( $collection_id );
            return "JOIN {$wpdb->posts} {$alias} ON {$alias}.ID = CAST({$meta( 'e', $meta_id )} AS UNSIGNED)"
                . " AND {$alias}.post_type = '{$post_type}' AND {$alias}.post_status = 'publish'";
        };

        $espetaculos = TainacanIds::item_post_type( TainacanIds::ESPETACULOS );

        // Todos os identificadores interpolados vêm de TainacanIds (inteiros) e
        // os IDs pedidos passam por clean_ids(); não há entrada de usuário aqui.
        $sql = "SELECT
                e.ID AS espetaculo_id,
                {$meta( 'e', TainacanIds::META_SLUG_ID )} AS legacy_id,
                pc.ID AS play_id,
                pc.post_title AS playName,
                {$term( 'pc', TainacanIds::TAX_GENERO )} AS playGenre,
                {$term( 'pc', TainacanIds::TAX_NACIONALIDADE )} AS playNationality,
                co.ID AS company_id,
                co.post_title AS companyName,
                {$term( 'co', TainacanIds::TAX_NACIONALIDADE )} AS companyNationality,
                te.ID AS theater_id,
                te.post_title AS presentationTheater,
                {$meta( 'e', TainacanIds::META_ESPETACULO_TIPO )} AS presentationKind,
                {$term( 'e', TainacanIds::TAX_IDIOMA )} AS presentationLanguage,
                {$meta( 'e', TainacanIds::META_ESPETACULO_DATA )} AS presentationDate,
                {$meta( 'e', TainacanIds::META_ESPETACULO_SESSOES )} AS presentationSessionsN
            FROM {$wpdb->posts} e
            {$related( 'pc', TainacanIds::META_ESPETACULO_PECA, TainacanIds::PECA )}
            {$related( 'co', TainacanIds::META_ESPETACULO_COMPANHIA, TainacanIds::COMPANHIA )}
            {$related( 'te', TainacanIds::META_ESPETACULO_TEATRO, TainacanIds::TEATRO )}
            WHERE e.ID IN ({$in})
              AND e.post_type = '{$espetaculos}'
              AND e.post_status = 'publish'";

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
            'espetaculo_id' => (int) $r['espetaculo_id'],
            'legacy_id'     => self::to_int( $r['legacy_id'] ),
            'play_id'       => (int) $r['play_id'],
            'company_id'    => (int) $r['company_id'],
            'theater_id'    => (int) $r['theater_id'],
        ];

        foreach ( PresentationsSchema::columns() as $key => $column ) {
            $value = $r[ $key ] ?? null;

            if ( $column->is_numeric() ) {
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
        $keys = array_merge(
            [ 'espetaculo_id', 'legacy_id', 'play_id', 'company_id', 'theater_id' ],
            PresentationsSchema::keys()
        );

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
     * Espetáculos (qualquer status) que apontam, pelo metadado de
     * relacionamento `$meta_id`, para algum dos itens `$related_ids`. Busca
     * no postmeta — não na tabela plana — para achar também espetáculos que
     * hoje estão fora dela (ex.: a peça acabou de ser publicada).
     *
     * @param list<int> $related_ids
     * @return list<int>
     */
    public function espetaculos_referencing( int $meta_id, array $related_ids ): array {
        global $wpdb;

        $ids = self::clean_ids( $related_ids );
        if ( ! $ids ) {
            return [];
        }

        $in          = "'" . implode( "','", $ids ) . "'";
        $espetaculos = TainacanIds::item_post_type( TainacanIds::ESPETACULOS );

        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        return array_map( 'intval', $wpdb->get_col(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} e ON e.ID = pm.post_id AND e.post_type = '{$espetaculos}'
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
