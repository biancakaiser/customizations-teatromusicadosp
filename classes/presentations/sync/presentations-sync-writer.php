<?php
/**
 * Aplica a projeção do Tainacan numa tabela plana (a oficial ou a temporária
 * do backfill): upsert por `espetaculo_id` das linhas que mudaram e remoção
 * das que não devem mais estar lá.
 *
 * Sempre recalcula a partir da fonte — nunca aplica diferenças recebidas de
 * um hook —, então eventos repetidos ou fora de ordem convergem para o mesmo
 * resultado.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Sync;

use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationsSyncWriter
{
    /** Tamanho do lote de projeção / INSERT. */
    const BATCH = 500;

    /** @var EspetaculoProjector */
    private $projector;

    public function __construct( ?EspetaculoProjector $projector = null ) {
        $this->projector = $projector ?? new EspetaculoProjector();
    }

    public function projector(): EspetaculoProjector {
        return $this->projector;
    }

    /**
     * Sincroniza os espetáculos pedidos: insere/atualiza os que a projeção
     * devolve com hash diferente do gravado, e remove os pedidos que a
     * projeção não devolve (despublicados, excluídos ou com Peça/Companhia/
     * Teatro não publicado).
     *
     * @param list<int> $espetaculo_ids
     * @return array{inserted:list<int>,updated:list<int>,deleted:list<int>,unchanged:int}
     */
    public function sync( array $espetaculo_ids, ?string $table = null, bool $dry_run = false ): array {
        $table  = $table ?? PresentationsRepository::table_name();
        $result = [ 'inserted' => [], 'updated' => [], 'deleted' => [], 'unchanged' => 0 ];

        foreach ( array_chunk( EspetaculoProjector::clean_ids( $espetaculo_ids ), self::BATCH ) as $batch ) {
            $rows     = $this->projector->project( $batch );
            $existing = $this->existing_hashes( $table, $batch );

            $upsert = [];
            foreach ( $rows as $id => $row ) {
                if ( ! isset( $existing[ $id ] ) ) {
                    $result['inserted'][] = $id;
                    $upsert[]             = $row;
                } elseif ( $existing[ $id ] !== $row['source_hash'] ) {
                    $result['updated'][] = $id;
                    $upsert[]            = $row;
                } else {
                    $result['unchanged']++;
                }
            }

            $delete = array_values( array_diff( array_keys( $existing ), array_keys( $rows ) ) );
            $result['deleted'] = array_merge( $result['deleted'], $delete );

            if ( ! $dry_run ) {
                $this->upsert( $table, $upsert );
                $this->delete( $table, $delete );
            }
        }

        if ( ! $dry_run && $table === PresentationsRepository::table_name() && self::changes( $result ) > 0 ) {
            PresentationsRepository::bump_data_version();
        }

        return $result;
    }

    /**
     * Remove linhas pelo `espetaculo_id`, sem consultar o Tainacan.
     *
     * @param list<int> $espetaculo_ids
     */
    public function delete( string $table, array $espetaculo_ids ): void {
        global $wpdb;

        foreach ( array_chunk( EspetaculoProjector::clean_ids( $espetaculo_ids ), self::BATCH ) as $batch ) {
            $in = implode( ',', $batch );
            // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "DELETE FROM {$table} WHERE espetaculo_id IN ({$in})" );
            self::assert_no_error();
        }
    }

    /**
     * @param array{inserted:list<int>,updated:list<int>,deleted:list<int>,unchanged:int} $result
     */
    public static function changes( array $result ): int {
        return count( $result['inserted'] ) + count( $result['updated'] ) + count( $result['deleted'] );
    }

    /**
     * `espetaculo_id => source_hash` das linhas já gravadas para estes IDs.
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function existing_hashes( string $table, array $ids ): array {
        global $wpdb;

        if ( ! $ids ) {
            return [];
        }

        $in = implode( ',', $ids );
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( "SELECT espetaculo_id, source_hash FROM {$table} WHERE espetaculo_id IN ({$in})", ARRAY_A );
        self::assert_no_error();

        $map = [];
        foreach ( $rows ?: [] as $row ) {
            $map[ (int) $row['espetaculo_id'] ] = (string) $row['source_hash'];
        }
        return $map;
    }

    /**
     * `INSERT … ON DUPLICATE KEY UPDATE` em lote, chaveado pelo UNIQUE de
     * `espetaculo_id`.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function upsert( string $table, array $rows ): void {
        global $wpdb;

        if ( ! $rows ) {
            return;
        }

        $columns = array_merge(
            PresentationsSchema::keys(),
            [ 'espetaculo_id', 'legacy_id', 'play_id', 'company_id', 'theater_id', 'source_hash', 'synced_at' ]
        );
        $now = current_time( 'mysql', true );

        $values = [];
        foreach ( $rows as $row ) {
            $row['synced_at'] = $now;

            $cells = [];
            foreach ( $columns as $column ) {
                $value = $row[ $column ] ?? null;
                if ( null === $value ) {
                    $cells[] = 'NULL';
                } elseif ( is_int( $value ) ) {
                    $cells[] = (string) $value;
                } else {
                    $cells[] = $wpdb->prepare( '%s', $value );
                }
            }
            $values[] = '(' . implode( ',', $cells ) . ')';
        }

        $column_list = '`' . implode( '`,`', $columns ) . '`';
        $updates     = implode(
            ',',
            array_map(
                static fn( string $c ): string => "`{$c}` = VALUES(`{$c}`)",
                array_diff( $columns, [ 'espetaculo_id' ] )
            )
        );

        // Colunas vêm do schema; valores já escapados acima.
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "INSERT INTO {$table} ({$column_list}) VALUES " . implode( ',', $values ) . " ON DUPLICATE KEY UPDATE {$updates}" );
        self::assert_no_error();
    }

    private static function assert_no_error(): void {
        global $wpdb;
        if ( '' !== $wpdb->last_error ) {
            throw new \RuntimeException( 'Escrita na tabela de apresentações falhou: ' . $wpdb->last_error );
        }
    }
}
