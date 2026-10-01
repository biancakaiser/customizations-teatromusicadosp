<?php
/**
 * Operações "em massa" da sincronização Tainacan → tabela plana:
 *
 *   - `reconcile()`  conferência completa (cron a cada 3 dias, botão "Conferir
 *                    agora", `wp tmsp presentations reconcile|verify`);
 *   - `backfill()`   carga inicial: monta `{tabela}_next`, troca atomicamente
 *                    com a oficial (a anterior vira `{tabela}_prev`) e passa a
 *                    fonte para o Tainacan;
 *   - `rollback()`   volta ao mock CSV e desliga a sincronização;
 *   - `process_queue()` processa em lotes a fila deixada pelos hooks quando
 *                    uma requisição sujou muitos itens (importação, edição em massa).
 *
 * Registrado com a funcionalidade "Apresentações" (não depende da sub-opção
 * de sincronização) para que o evento de backfill agendado por
 * `PresentationsRepository::maybe_install_table()` sempre tenha quem o execute.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Sync;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\Settings\Features;
use TeatroMusicadoSP\Customizations\References\TainacanIds;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class PresentationsReconciler implements Module
{
    use Singleton;

    const SYNC_KEY        = Features::SYNC_KEY;
    const SCHEDULE        = 'tmsp_every_three_days';
    const RECONCILE_EVENT = 'tmsp_presentations_reconcile';
    const QUEUE_EVENT     = 'tmsp_presentations_process_queue';
    const MANUAL_ACTION   = 'tmsp_presentations_reconcile_now';

    /** Quantos IDs de exemplo guardar por categoria no log/relatório. */
    const SAMPLE_SIZE = 20;

    public function register(): void {
        add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
        add_action( self::RECONCILE_EVENT, [ $this, 'run_cron_reconcile' ] );
        add_action( self::QUEUE_EVENT, [ $this, 'process_queue' ] );
        add_action( PresentationsRepository::BACKFILL_EVENT, [ $this, 'run_scheduled_backfill' ] );
        add_action( 'admin_init', [ self::class, 'ensure_schedule' ] );
        add_action( 'admin_post_' . self::MANUAL_ACTION, [ $this, 'handle_manual_reconcile' ] );
    }

    /**
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public function add_schedule( $schedules ): array {
        $schedules = is_array( $schedules ) ? $schedules : [];
        $schedules[ self::SCHEDULE ] = [
            'interval' => 3 * DAY_IN_SECONDS,
            'display'  => 'A cada 3 dias (Teatro Musicado SP)',
        ];
        return $schedules;
    }

    public static function sync_active(): bool {
        return Features::get_instance()->is_enabled( self::SYNC_KEY )
            && PresentationsRepository::SOURCE_TAINACAN === PresentationsRepository::source();
    }

    /**
     * Agenda a conferência quando a sincronização está ativa e a remove quando não.
     */
    public static function ensure_schedule(): void {
        $next = wp_next_scheduled( self::RECONCILE_EVENT );

        if ( self::sync_active() ) {
            if ( ! $next ) {
                wp_schedule_event( time() + 3 * DAY_IN_SECONDS, self::SCHEDULE, self::RECONCILE_EVENT );
            }
        } elseif ( $next ) {
            wp_clear_scheduled_hook( self::RECONCILE_EVENT );
        }
    }

    public function run_cron_reconcile(): void {
        if ( self::sync_active() ) {
            $this->reconcile( false, 'cron' );
        }
    }

    public function run_scheduled_backfill(): void {
        if ( PresentationsRepository::SOURCE_TAINACAN === PresentationsRepository::source() ) {
            $this->backfill( 'schema' );
        }
    }

    /**
     * Botão "Conferir agora" da página de configurações.
     */
    public function handle_manual_reconcile(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sem permissão.', 'customizations-teatromusicadosp' ), 403 );
        }
        check_admin_referer( self::MANUAL_ACTION );

        if ( PresentationsRepository::SOURCE_TAINACAN === PresentationsRepository::source() ) {
            $this->reconcile( false, 'manual' );
        } else {
            $this->backfill( 'manual' );
        }

        wp_safe_redirect( wp_get_referer() ?: admin_url( 'options-general.php' ) );
        exit;
    }

    /**
     * Conferência completa da tabela oficial contra o Tainacan.
     *
     * @return array<string,mixed> relatório (contagens + amostras de IDs)
     */
    public function reconcile( bool $dry_run = false, string $origin = 'manual' ): array {
        if ( ! $dry_run && ! SyncState::acquire_lock() ) {
            return [ 'skipped' => 'lock' ];
        }

        $started = microtime( true );
        $table   = PresentationsRepository::table_name();
        $writer  = new PresentationsSyncWriter();
        $totals  = [ 'inserted' => [], 'updated' => [], 'deleted' => [], 'unchanged' => 0 ];

        try {
            // 1. Todos os espetáculos publicados (insere, atualiza ou remove
            //    os que têm relacionado não publicado).
            $after = 0;
            while ( $ids = $writer->projector()->published_ids_after( $after, PresentationsSyncWriter::BATCH ) ) {
                $totals = self::merge( $totals, $writer->sync( $ids, $table, $dry_run ) );
                $after  = end( $ids );
            }

            // 2. Linhas cujo espetáculo não está mais publicado (ou linhas mock).
            $orphans = $this->orphan_rows( $table );
            if ( $orphans['ids'] && ! $dry_run ) {
                $writer->delete( $table, $orphans['ids'] );
            }
            if ( $orphans['without_id'] && ! $dry_run ) {
                $this->delete_rows_without_id( $table );
            }
            $totals['deleted'] = array_merge( $totals['deleted'], $orphans['ids'] );

            if ( ! $dry_run ) {
                if ( $orphans['ids'] || $orphans['without_id'] ) {
                    PresentationsRepository::bump_data_version();
                }
                // Tudo foi recalculado: a fila de retry está resolvida.
                SyncState::resolve_retry( SyncState::retry_ids() );
            }

            $report = self::report( $totals ) + [
                'deleted_without_id' => $orphans['without_id'],
                'duplicate_legacy'   => $this->duplicate_legacy_ids( $table ),
                'published'          => $writer->projector()->published_count(),
                'table_rows'         => $this->count_rows( $table ),
                'dry_run'            => $dry_run,
                'seconds'            => round( microtime( true ) - $started, 2 ),
            ];
        } catch ( \Throwable $e ) {
            $report = [ 'error' => $e->getMessage() ];
        } finally {
            if ( ! $dry_run ) {
                SyncState::release_lock();
            }
        }

        if ( ! $dry_run ) {
            SyncState::log( $origin, $report );
        }

        return $report;
    }

    /**
     * Carga inicial (ou recarga após mudança de schema): monta a tabela nova
     * inteira a partir do Tainacan e só então a troca pela oficial, num único
     * `RENAME TABLE`. Em caso de erro a tabela oficial fica intacta.
     *
     * @return array<string,mixed>
     */
    public function backfill( string $origin = 'cli' ): array {
        global $wpdb;

        if ( ! SyncState::acquire_lock( 2 * HOUR_IN_SECONDS ) ) {
            return [ 'skipped' => 'lock' ];
        }

        $started = microtime( true );
        $table   = PresentationsRepository::table_name();
        $next    = $table . '_next';
        $prev    = $table . '_prev';

        try {
            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "DROP TABLE IF EXISTS {$next}" );
            $wpdb->query( PresentationsRepository::create_table_sql( $next ) );
            if ( '' !== $wpdb->last_error ) {
                throw new \RuntimeException( $wpdb->last_error );
            }

            $writer = new PresentationsSyncWriter();
            $totals = [ 'inserted' => [], 'updated' => [], 'deleted' => [], 'unchanged' => 0 ];
            $after  = 0;
            while ( $ids = $writer->projector()->published_ids_after( $after, PresentationsSyncWriter::BATCH ) ) {
                $totals = self::merge( $totals, $writer->sync( $ids, $next ) );
                $after  = end( $ids );
            }

            // Troca atômica. A tabela anterior (mock ou carga antiga) fica em
            // `_prev` para inspeção; a `_prev` mais antiga é descartada.
            $wpdb->query( "DROP TABLE IF EXISTS {$prev}" );
            $exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            $wpdb->query(
                $exists
                    ? "RENAME TABLE {$table} TO {$prev}, {$next} TO {$table}"
                    : "RENAME TABLE {$next} TO {$table}"
            );
            if ( '' !== $wpdb->last_error ) {
                throw new \RuntimeException( $wpdb->last_error );
            }
            // phpcs:enable

            update_option( PresentationsRepository::SCHEMA_OPTION, PresentationsRepository::SCHEMA_VERSION );
            PresentationsRepository::set_source( PresentationsRepository::SOURCE_TAINACAN );
            PresentationsRepository::bump_data_version();
            self::set_sync_enabled( true );

            $report = self::report( $totals ) + [
                'table_rows' => $this->count_rows( $table ),
                'seconds'    => round( microtime( true ) - $started, 2 ),
            ];
        } catch ( \Throwable $e ) {
            $report = [ 'error' => $e->getMessage() ];
        } finally {
            SyncState::release_lock();
        }

        SyncState::log( 'backfill', $report + [ 'trigger' => $origin ] );

        // Pega edições feitas no Tainacan enquanto a tabela nova era montada.
        if ( empty( $report['error'] ) ) {
            $report['catch_up'] = $this->reconcile( false, 'backfill' );
        }

        return $report;
    }

    /**
     * Volta ao mock CSV: desliga a sincronização e o cron e recria a tabela
     * oficial a partir de `data/flat-table.csv`. A tabela `_prev` (se houver)
     * não é tocada.
     *
     * @return array<string,mixed>
     */
    public function rollback(): array {
        self::set_sync_enabled( false );
        wp_clear_scheduled_hook( self::RECONCILE_EVENT );
        wp_clear_scheduled_hook( self::QUEUE_EVENT );
        wp_clear_scheduled_hook( PresentationsRepository::BACKFILL_EVENT );

        PresentationsRepository::get_instance()->reinstall_from_csv();

        $report = [ 'source' => PresentationsRepository::source(), 'table_rows' => $this->count_rows( PresentationsRepository::table_name() ) ];
        SyncState::log( 'rollback', $report );

        return $report;
    }

    /**
     * Processa em lotes a fila deixada pelos hooks; reagenda enquanto houver itens.
     */
    public function process_queue(): void {
        if ( ! self::sync_active() ) {
            return;
        }

        $ids = SyncState::dequeue( PresentationsSyncWriter::BATCH * 4 );
        if ( $ids ) {
            try {
                $result = ( new PresentationsSyncWriter() )->sync( $ids );
                SyncState::log( 'queue', self::report( $result ) );
            } catch ( \Throwable $e ) {
                SyncState::add_retry( $ids, $e->getMessage() );
                SyncState::log( 'queue', [ 'error' => $e->getMessage(), 'ids' => count( $ids ) ] );
            }
        }

        if ( SyncState::queue_size() > 0 && ! wp_next_scheduled( self::QUEUE_EVENT ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::QUEUE_EVENT );
        }
    }

    /**
     * Liga/desliga a sub-opção "Sincronização com o Tainacan" (dispara
     * `SettingsPage::on_features_updated()`, que (des)agenda o cron).
     */
    public static function set_sync_enabled( bool $enabled ): void {
        $features = Features::get_instance();
        $state    = $features->saved();

        $state[ self::SYNC_KEY ] = $enabled ? 1 : 0;
        if ( $enabled ) {
            $state['presentations']       = 1;
            $state[ Features::TABLE_KEY ] = 1;
        }

        update_option( Features::OPTION, $features->sanitize( $state ) );
        $features->flush();
        self::ensure_schedule();
    }

    /**
     * Linhas da tabela sem espetáculo publicado correspondente.
     *
     * @return array{ids:list<int>,without_id:int}
     */
    private function orphan_rows( string $table ): array {
        global $wpdb;

        $espetaculos = TainacanIds::item_post_type( TainacanIds::ESPETACULOS );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT t.espetaculo_id FROM {$table} t
             LEFT JOIN {$wpdb->posts} e ON e.ID = t.espetaculo_id AND e.post_type = %s AND e.post_status = 'publish'
             WHERE t.espetaculo_id IS NOT NULL AND e.ID IS NULL",
            $espetaculos
        ) );
        $without_id = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE espetaculo_id IS NULL" );
        // phpcs:enable

        return [ 'ids' => array_map( 'intval', $ids ?: [] ), 'without_id' => $without_id ];
    }

    private function delete_rows_without_id( string $table ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DELETE FROM {$table} WHERE espetaculo_id IS NULL" );
    }

    /**
     * Slug IDs (ID legado) repetidos na tabela.
     *
     * @return array<int,int> legacy_id => quantidade
     */
    private function duplicate_legacy_ids( string $table ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( "SELECT legacy_id, COUNT(*) AS c FROM {$table} WHERE legacy_id IS NOT NULL GROUP BY legacy_id HAVING c > 1 LIMIT " . self::SAMPLE_SIZE, ARRAY_A );
        $map  = [];
        foreach ( $rows ?: [] as $row ) {
            $map[ (int) $row['legacy_id'] ] = (int) $row['c'];
        }
        return $map;
    }

    private function count_rows( string $table ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private static function merge( array $a, array $b ): array {
        return [
            'inserted'  => array_merge( $a['inserted'], $b['inserted'] ),
            'updated'   => array_merge( $a['updated'], $b['updated'] ),
            'deleted'   => array_merge( $a['deleted'], $b['deleted'] ),
            'unchanged' => $a['unchanged'] + $b['unchanged'],
        ];
    }

    /**
     * Contagens + amostras de IDs de um resultado do writer.
     *
     * @param array<string,mixed> $totals
     * @return array<string,mixed>
     */
    public static function report( array $totals ): array {
        $report = [ 'unchanged' => (int) $totals['unchanged'] ];
        foreach ( [ 'inserted', 'updated', 'deleted' ] as $key ) {
            $report[ $key ]             = count( $totals[ $key ] );
            $report[ $key . '_sample' ] = array_slice( $totals[ $key ], 0, self::SAMPLE_SIZE );
        }
        return $report;
    }
}
