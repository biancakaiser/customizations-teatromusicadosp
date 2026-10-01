<?php
/**
 * Estado persistente da sincronização Tainacan → tabela plana: log das
 * execuções, fila de processamento em lote, fila de nova tentativa e trava.
 *
 * Tudo em options sem autoload. As filas usam leitura-modificação-escrita sem
 * trava: uma perda por concorrência é coberta pela conferência periódica
 * (`PresentationsReconciler`), que recalcula tudo a partir do Tainacan.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Sync;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class SyncState
{
    const LOG_OPTION   = 'tmsp_pres_sync_log';
    const QUEUE_OPTION = 'tmsp_pres_sync_queue';
    const RETRY_OPTION = 'tmsp_pres_sync_retry';
    const LOCK         = 'tmsp_pres_sync_lock';

    /** Entradas mantidas no log. */
    const LOG_SIZE = 50;

    /** Tentativas antes de um ID sair da fila de retry (a conferência ainda o pega). */
    const MAX_ATTEMPTS = 5;

    /**
     * Registra uma execução no log (mais recente primeiro).
     *
     * @param string              $origin  backfill | hook | queue | cron | manual | cli | rollback
     * @param array<string,mixed> $data    contagens, amostras de IDs, erro…
     */
    public static function log( string $origin, array $data ): void {
        $log = get_option( self::LOG_OPTION, [] );
        $log = is_array( $log ) ? $log : [];

        array_unshift( $log, array_merge( [ 'time' => current_time( 'mysql', true ), 'origin' => $origin ], $data ) );

        update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_SIZE ), false );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function recent_log(): array {
        $log = get_option( self::LOG_OPTION, [] );
        return is_array( $log ) ? $log : [];
    }

    /**
     * @param list<int> $ids
     */
    public static function enqueue( array $ids ): void {
        $queue = self::ids_option( self::QUEUE_OPTION );
        update_option( self::QUEUE_OPTION, EspetaculoProjector::clean_ids( array_merge( $queue, $ids ) ), false );
    }

    /**
     * Retira até `$limit` IDs da fila.
     *
     * @return list<int>
     */
    public static function dequeue( int $limit ): array {
        $queue = self::ids_option( self::QUEUE_OPTION );
        $batch = array_slice( $queue, 0, $limit );
        update_option( self::QUEUE_OPTION, array_slice( $queue, $limit ), false );
        return $batch;
    }

    public static function queue_size(): int {
        return count( self::ids_option( self::QUEUE_OPTION ) );
    }

    /**
     * Guarda IDs que falharam para nova tentativa.
     *
     * @param list<int> $ids
     */
    public static function add_retry( array $ids, string $error ): void {
        $retry = get_option( self::RETRY_OPTION, [] );
        $retry = is_array( $retry ) ? $retry : [];

        foreach ( EspetaculoProjector::clean_ids( $ids ) as $id ) {
            $attempts = (int) ( $retry[ $id ]['attempts'] ?? 0 ) + 1;
            if ( $attempts > self::MAX_ATTEMPTS ) {
                unset( $retry[ $id ] );
                continue;
            }
            $retry[ $id ] = [ 'attempts' => $attempts, 'error' => $error ];
        }

        update_option( self::RETRY_OPTION, $retry, false );
    }

    /**
     * IDs aguardando nova tentativa (sem removê-los: quem der certo sai via
     * `resolve_retry()`, quem falhar de novo incrementa via `add_retry()`).
     *
     * @return list<int>
     */
    public static function retry_ids(): array {
        $retry = get_option( self::RETRY_OPTION, [] );
        return is_array( $retry ) ? array_map( 'intval', array_keys( $retry ) ) : [];
    }

    /**
     * @param list<int> $ids
     */
    public static function resolve_retry( array $ids ): void {
        $retry = get_option( self::RETRY_OPTION, [] );
        if ( ! is_array( $retry ) || ! $retry ) {
            return;
        }
        foreach ( $ids as $id ) {
            unset( $retry[ (int) $id ] );
        }
        update_option( self::RETRY_OPTION, $retry, false );
    }

    public static function retry_size(): int {
        $retry = get_option( self::RETRY_OPTION, [] );
        return is_array( $retry ) ? count( $retry ) : 0;
    }

    /**
     * Trava exclusiva para backfill/conferência (não usada pelo incremental,
     * que faz upsert linha a linha e é idempotente).
     */
    public static function acquire_lock( int $ttl = 30 * MINUTE_IN_SECONDS ): bool {
        if ( get_transient( self::LOCK ) ) {
            return false;
        }
        set_transient( self::LOCK, time(), $ttl );
        return true;
    }

    public static function release_lock(): void {
        delete_transient( self::LOCK );
    }

    /**
     * @return list<int>
     */
    private static function ids_option( string $option ): array {
        $value = get_option( $option, [] );
        return is_array( $value ) ? array_map( 'intval', $value ) : [];
    }
}
