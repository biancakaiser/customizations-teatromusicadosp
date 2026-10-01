<?php
/**
 * Comandos WP-CLI da sincronização Tainacan → tabela de apresentações.
 *
 *   wp tmsp presentations status
 *   wp tmsp presentations backfill
 *   wp tmsp presentations reconcile
 *   wp tmsp presentations verify
 *   wp tmsp presentations rollback [--yes]
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Sync;

use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class PresentationsSyncCli
{
    /**
     * Mostra a fonte dos dados, contagens, filas e as últimas execuções.
     */
    public function status(): void {
        global $wpdb;

        $table = PresentationsRepository::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        \WP_CLI::log( 'Fonte:              ' . PresentationsRepository::source() );
        \WP_CLI::log( 'Sincronização:      ' . ( PresentationsReconciler::sync_active() ? 'ativa' : 'inativa' ) );
        \WP_CLI::log( 'Linhas na tabela:   ' . $rows );
        \WP_CLI::log( 'Espetáculos public.: ' . ( new EspetaculoProjector() )->published_count() );
        \WP_CLI::log( 'Fila / retry:       ' . SyncState::queue_size() . ' / ' . SyncState::retry_size() );
        $next = wp_next_scheduled( PresentationsReconciler::RECONCILE_EVENT );
        \WP_CLI::log( 'Próxima conferência: ' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : '—' ) );

        foreach ( array_slice( SyncState::recent_log(), 0, 5 ) as $entry ) {
            \WP_CLI::log( '  ' . wp_json_encode( self::without_samples( $entry ), JSON_UNESCAPED_UNICODE ) );
        }
    }

    /**
     * Carga completa a partir do Tainacan numa tabela nova, troca atômica com a
     * oficial (a anterior fica em `_prev`) e liga a sincronização.
     */
    public function backfill(): void {
        \WP_CLI::log( 'Montando a tabela a partir do Tainacan…' );
        $this->finish( PresentationsReconciler::get_instance()->backfill( 'cli' ) );
    }

    /**
     * Confere a tabela contra o Tainacan e corrige as diferenças.
     */
    public function reconcile(): void {
        $this->finish( PresentationsReconciler::get_instance()->reconcile( false, 'cli' ) );
    }

    /**
     * Confere a tabela contra o Tainacan sem alterar nada. Sai com código 1 se
     * houver divergência.
     */
    public function verify(): void {
        $report = PresentationsReconciler::get_instance()->reconcile( true, 'cli' );
        $this->print_report( $report );

        if ( ! empty( $report['error'] ) ) {
            \WP_CLI::error( $report['error'] );
        }

        $divergent = $report['inserted'] + $report['updated'] + $report['deleted'] + $report['deleted_without_id'];
        if ( $divergent > 0 ) {
            \WP_CLI::error( "{$divergent} divergência(s) entre a tabela e o Tainacan." );
        }
        \WP_CLI::success( 'Tabela idêntica ao Tainacan.' );
    }

    /**
     * Volta ao mock CSV e desliga a sincronização.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Não pede confirmação.
     */
    public function rollback( $args, $assoc_args ): void {
        \WP_CLI::confirm( 'Recriar a tabela a partir do mock CSV e desligar a sincronização?', $assoc_args );
        $this->finish( PresentationsReconciler::get_instance()->rollback() );
    }

    /**
     * @param array<string,mixed> $report
     */
    private function finish( array $report ): void {
        $this->print_report( $report );
        if ( ! empty( $report['error'] ) ) {
            \WP_CLI::error( $report['error'] );
        }
        if ( ! empty( $report['skipped'] ) ) {
            \WP_CLI::error( 'Outra conferência/carga está em andamento (trava ativa).' );
        }
        \WP_CLI::success( 'Concluído.' );
    }

    /**
     * @param array<string,mixed> $report
     */
    private function print_report( array $report ): void {
        foreach ( $report as $key => $value ) {
            \WP_CLI::log( sprintf( '%-20s %s', $key, is_scalar( $value ) ? var_export( $value, true ) : wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ) );
        }
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function without_samples( array $entry ): array {
        return array_filter(
            $entry,
            static fn( $key ): bool => ! str_ends_with( (string) $key, '_sample' ) && 'catch_up' !== $key,
            ARRAY_FILTER_USE_KEY
        );
    }
}

\WP_CLI::add_command( 'tmsp presentations', PresentationsSyncCli::class );
