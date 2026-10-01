<?php
/**
 * Sincronização incremental Tainacan → tabela plana, por hooks.
 *
 * Os hooks só anotam IDs "sujos" durante a requisição; no `shutdown` eles são
 * resolvidos para IDs de espetáculos e recalculados uma única vez cada
 * (`PresentationsSyncWriter`). Assim, salvar um item — que no Tainacan
 * dispara um evento por metadado — custa uma projeção, e a ordem entre a
 * gravação do item e a dos seus metadados não importa.
 *
 * Hooks do Tainacan cobrem a interface e a API dele; os do WordPress cobrem o
 * que o Tainacan não anuncia (restauração da lixeira pela edição em massa,
 * exclusão definitiva pelo cron do WP, termos editados fora do Tainacan).
 *
 * Nada aqui pode interromper o salvamento no Tainacan: erros viram fila de
 * nova tentativa e entrada no log, e a conferência periódica corrige o resto.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Sync;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\References\TainacanIds;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class PresentationsSync implements Module
{
    use Singleton;

    /** Acima disto, os IDs vão para a fila processada em segundo plano. */
    const INLINE_LIMIT = 200;

    /** Resumo da última execução por hook (option sem autoload). */
    const LAST_RUN_OPTION = 'tmsp_pres_sync_last_hook';

    /** @var array<string,array<int,true>> bucket => [ID => true] */
    private $dirty = [ 'espetaculos' => [], 'peca' => [], 'companhia' => [], 'teatro' => [], 'terms' => [] ];

    /** @var bool */
    private $shutdown_registered = false;

    public function register(): void {
        if ( PresentationsRepository::SOURCE_TAINACAN !== PresentationsRepository::source() ) {
            return;
        }

        // Tainacan.
        add_action( 'tainacan-insert', [ $this, 'on_tainacan_entity' ] );
        add_action( 'tainacan-deleted', [ $this, 'on_tainacan_entity' ] );
        add_action( 'tainacan-deleted-Item_Metadata_Entity', [ $this, 'on_tainacan_entity' ] );

        // WordPress (caminhos que não passam pelos repositórios do Tainacan).
        add_action( 'transition_post_status', [ $this, 'on_transition_post_status' ], 10, 3 );
        add_action( 'untrashed_post', [ $this, 'on_post_id' ] );
        add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 10, 2 );
        add_action( 'set_object_terms', [ $this, 'on_set_object_terms' ], 10, 4 );
        add_action( 'edited_term', [ $this, 'on_edited_term' ], 10, 3 );
        add_action( 'pre_delete_term', [ $this, 'on_pre_delete_term' ], 10, 2 );
    }

    /**
     * `tainacan-insert` / `tainacan-deleted` recebem qualquer entidade
     * (coleção, metadado, termo, log…); só itens e valores de metadado importam.
     *
     * @param mixed $entity
     */
    public function on_tainacan_entity( $entity ): void {
        if ( $entity instanceof \Tainacan\Entities\Item_Metadata_Entity ) {
            $item = $entity->get_item();
            if ( $item instanceof \Tainacan\Entities\Item ) {
                $this->mark_post( (int) $item->get_id() );
            }
        } elseif ( $entity instanceof \Tainacan\Entities\Item ) {
            $post_type = isset( $entity->WP_Post->post_type ) ? (string) $entity->WP_Post->post_type : null;
            $this->mark_post( (int) $entity->get_id(), $post_type );
        }
    }

    /**
     * @param string   $new_status
     * @param string   $old_status
     * @param \WP_Post $post
     */
    public function on_transition_post_status( $new_status, $old_status, $post ): void {
        if ( $new_status !== $old_status && $post instanceof \WP_Post ) {
            $this->mark_post( (int) $post->ID, $post->post_type );
        }
    }

    /**
     * @param int $post_id
     */
    public function on_post_id( $post_id ): void {
        $this->mark_post( (int) $post_id );
    }

    /**
     * Depois da exclusão o post não existe mais: o post type vem do 2º argumento.
     *
     * @param int           $post_id
     * @param \WP_Post|null $post
     */
    public function on_deleted_post( $post_id, $post = null ): void {
        $this->mark_post( (int) $post_id, $post instanceof \WP_Post ? $post->post_type : null );
    }

    /**
     * @param int    $object_id
     * @param array  $terms
     * @param array  $tt_ids
     * @param string $taxonomy
     */
    public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
        if ( self::is_watched_taxonomy( (string) $taxonomy ) ) {
            $this->mark_post( (int) $object_id );
        }
    }

    /**
     * Termo renomeado: os itens vinculados são resolvidos no shutdown.
     *
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     */
    public function on_edited_term( $term_id, $tt_id, $taxonomy ): void {
        if ( self::is_watched_taxonomy( (string) $taxonomy ) ) {
            $this->dirty['terms'][ (int) $tt_id ] = true;
            $this->schedule_flush();
        }
    }

    /**
     * Termo prestes a ser excluído: depois da exclusão os vínculos somem,
     * então os itens afetados são anotados agora.
     *
     * @param int    $term_id
     * @param string $taxonomy
     */
    public function on_pre_delete_term( $term_id, $taxonomy ): void {
        if ( ! self::is_watched_taxonomy( (string) $taxonomy ) ) {
            return;
        }
        $term = get_term( (int) $term_id, (string) $taxonomy );
        if ( ! $term instanceof \WP_Term ) {
            return;
        }
        $objects = ( new EspetaculoProjector() )->objects_with_terms( [ (int) $term->term_taxonomy_id ] );
        foreach ( $objects as $post_type => $ids ) {
            foreach ( $ids as $id ) {
                $this->mark_post( $id, $post_type );
            }
        }
    }

    /**
     * Anota um post se ele for item de Espetáculos, Peça, Companhia ou Teatro.
     */
    private function mark_post( int $post_id, ?string $post_type = null ): void {
        if ( $post_id <= 0 ) {
            return;
        }

        $post_type = $post_type ?? (string) get_post_type( $post_id );
        $bucket    = self::watched_post_types()[ $post_type ] ?? null;
        if ( null === $bucket ) {
            return;
        }

        $this->dirty[ $bucket ][ $post_id ] = true;
        $this->schedule_flush();
    }

    private function schedule_flush(): void {
        if ( ! $this->shutdown_registered ) {
            add_action( 'shutdown', [ $this, 'flush' ] );
            $this->shutdown_registered = true;
        }
    }

    /**
     * Resolve os IDs anotados para espetáculos e sincroniza (ou enfileira).
     */
    public function flush(): void {
        $this->shutdown_registered = false;

        try {
            $ids = $this->resolve_dirty();
        } catch ( \Throwable $e ) {
            SyncState::log( 'hook', [ 'error' => $e->getMessage() ] );
            return;
        }

        $retry = SyncState::retry_ids();
        $ids   = EspetaculoProjector::clean_ids( array_merge( $ids, $retry ) );
        if ( ! $ids ) {
            return;
        }

        if ( count( $ids ) > self::INLINE_LIMIT ) {
            SyncState::enqueue( $ids );
            SyncState::resolve_retry( $retry );
            if ( ! wp_next_scheduled( PresentationsReconciler::QUEUE_EVENT ) ) {
                wp_schedule_single_event( time(), PresentationsReconciler::QUEUE_EVENT );
            }
            update_option( self::LAST_RUN_OPTION, [ 'time' => current_time( 'mysql', true ), 'queued' => count( $ids ) ], false );
            return;
        }

        try {
            $result = ( new PresentationsSyncWriter() )->sync( $ids );
            SyncState::resolve_retry( $retry );
            update_option(
                self::LAST_RUN_OPTION,
                [ 'time' => current_time( 'mysql', true ) ] + PresentationsReconciler::report( $result ),
                false
            );
        } catch ( \Throwable $e ) {
            SyncState::add_retry( $ids, $e->getMessage() );
            SyncState::log( 'hook', [ 'error' => $e->getMessage(), 'ids' => array_slice( $ids, 0, PresentationsReconciler::SAMPLE_SIZE ) ] );
        }
    }

    /**
     * @return list<int> IDs de espetáculos afetados
     */
    private function resolve_dirty(): array {
        $dirty       = $this->dirty;
        $this->dirty = [ 'espetaculos' => [], 'peca' => [], 'companhia' => [], 'teatro' => [], 'terms' => [] ];

        $projector = new EspetaculoProjector();

        // Termos renomeados → itens vinculados (espetáculos, peças ou companhias).
        if ( $dirty['terms'] ) {
            $types = self::watched_post_types();
            foreach ( $projector->objects_with_terms( array_keys( $dirty['terms'] ) ) as $post_type => $object_ids ) {
                if ( isset( $types[ $post_type ] ) ) {
                    foreach ( $object_ids as $id ) {
                        $dirty[ $types[ $post_type ] ][ $id ] = true;
                    }
                }
            }
        }

        $ids = array_keys( $dirty['espetaculos'] );

        $relations = [
            'peca'      => TainacanIds::META_ESPETACULO_PECA,
            'companhia' => TainacanIds::META_ESPETACULO_COMPANHIA,
            'teatro'    => TainacanIds::META_ESPETACULO_TEATRO,
        ];
        foreach ( $relations as $bucket => $meta_id ) {
            if ( $dirty[ $bucket ] ) {
                $ids = array_merge( $ids, $projector->espetaculos_referencing( $meta_id, array_keys( $dirty[ $bucket ] ) ) );
            }
        }

        return EspetaculoProjector::clean_ids( $ids );
    }

    /**
     * @return array<string,string> post type => bucket
     */
    private static function watched_post_types(): array {
        return [
            TainacanIds::item_post_type( TainacanIds::ESPETACULOS ) => 'espetaculos',
            TainacanIds::item_post_type( TainacanIds::PECA )        => 'peca',
            TainacanIds::item_post_type( TainacanIds::COMPANHIA )   => 'companhia',
            TainacanIds::item_post_type( TainacanIds::TEATRO )      => 'teatro',
        ];
    }

    private static function is_watched_taxonomy( string $taxonomy ): bool {
        return in_array(
            $taxonomy,
            [
                TainacanIds::taxonomy( TainacanIds::TAX_IDIOMA ),
                TainacanIds::taxonomy( TainacanIds::TAX_NACIONALIDADE ),
                TainacanIds::taxonomy( TainacanIds::TAX_GENERO ),
            ],
            true
        );
    }
}
