<?php

namespace TeatroMusicadoSP\Customizations\Settings;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;
use TeatroMusicadoSP\Customizations\Presentations\Sync\PresentationsReconciler;
use TeatroMusicadoSP\Customizations\Presentations\Sync\PresentationsSync;
use TeatroMusicadoSP\Customizations\Presentations\Sync\SyncState;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Página "Configurações > Teatro Musicado SP": liga e desliga as
 * funcionalidades do plugin (ver Features).
 *
 * É registrada antes da checagem do Tainacan em Plugin::boot() — assim ela
 * continua acessível (somente leitura) mesmo com o Tainacan inativo.
 */
final class SettingsPage implements Module
{
    use Singleton;

    const PAGE_SLUG     = 'teatromusicadosp-settings';
    const OPTION_GROUP  = 'tmsp_customizations_settings';
    const CAPABILITY    = 'manage_options';
    const ASSET_HANDLE  = 'tmsp-customizations-settings';

    public function register(): void
    {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_setting' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter(
            'plugin_action_links_' . plugin_basename( TMSP_CUSTOMIZATIONS_FILE ),
            array( $this, 'add_action_link' )
        );
        add_action(
            'update_option_' . Features::OPTION,
            array( $this, 'on_features_updated' ),
            10,
            2
        );
    }

    public function add_menu(): void
    {
        add_options_page(
            __( 'Teatro Musicado SP', 'customizations-teatromusicadosp' ),
            __( 'Teatro Musicado SP', 'customizations-teatromusicadosp' ),
            self::CAPABILITY,
            self::PAGE_SLUG,
            array( $this, 'render' )
        );
    }

    public function register_setting(): void
    {
        register_setting(
            self::OPTION_GROUP,
            Features::OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( Features::get_instance(), 'sanitize' ),
                'default'           => array(),
            )
        );
    }

    /**
     * Atalho "Configurações" na linha do plugin, na lista de plugins.
     *
     * @param string[] $links
     * @return string[]
     */
    public function add_action_link( array $links ): array
    {
        $url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

        array_unshift(
            $links,
            sprintf(
                '<a href="%s">%s</a>',
                esc_url( $url ),
                esc_html__( 'Configurações', 'customizations-teatromusicadosp' )
            )
        );

        return $links;
    }

    public function enqueue_assets( string $hook_suffix ): void
    {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
            return;
        }

        $path = TMSP_CUSTOMIZATIONS_PATH . 'assets/';
        $url  = TMSP_CUSTOMIZATIONS_URL . 'assets/';

        wp_enqueue_style(
            self::ASSET_HANDLE,
            $url . 'settings.css',
            array(),
            (string) filemtime( $path . 'settings.css' )
        );
        wp_enqueue_script(
            self::ASSET_HANDLE,
            $url . 'settings.js',
            array(),
            (string) filemtime( $path . 'settings.js' ),
            true
        );
    }

    /**
     * Ao salvar: desligar Apresentações (ou só o banco/cache) limpa os
     * transients; ligar o banco cria/popula a tabela na hora. Os dados da
     * tabela nunca são apagados.
     *
     * @param mixed $old_value
     * @param mixed $new_value
     */
    public function on_features_updated( $old_value, $new_value ): void
    {
        $features = Features::get_instance();
        $features->flush();

        $old = is_array( $old_value ) ? $old_value : array();
        $new = is_array( $new_value ) ? $new_value : array();

        $was_on = $features->is_enabled_in( $old, Features::TABLE_KEY );
        $is_on  = $features->is_enabled_in( $new, Features::TABLE_KEY );

        if ( $was_on && ! $is_on ) {
            $this->clear_presentations_cache();
        } elseif ( ! $was_on && $is_on ) {
            PresentationsRepository::install();
        }

        // Agenda (ou remove) a conferência a cada 3 dias conforme a sincronização.
        PresentationsReconciler::ensure_schedule();
    }

    /**
     * Apaga os transients das apresentações (consultas, facetas e trava de
     * instalação). Todos usam o prefixo tmsp_pres_, exceto a trava.
     */
    private function clear_presentations_cache(): void
    {
        global $wpdb;

        $patterns = array(
            $wpdb->esc_like( '_transient_tmsp_pres_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_tmsp_pres_' ) . '%',
        );

        foreach ( $patterns as $pattern ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query(
                $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern )
            );
        }

        delete_transient( PresentationsRepository::INSTALL_LOCK );
    }

    /**
     * Textos de cada funcionalidade e sub-opção (rótulo e descrição).
     *
     * @return array<string,array{label:string,description:string}>
     */
    private function copy(): array
    {
        $d = 'customizations-teatromusicadosp';

        return array(
            'collection_form' => array(
                'label'       => __( 'Formulário de Espetáculos', $d ),
                'description' => __( 'Insere a tabela de espetáculos no formulário de edição de itens da coleção Espetáculos.', $d ),
            ),
            'metadata_types' => array(
                'label'       => __( 'Tipo de metadado Slug/ID', $d ),
                'description' => __( 'Registra o tipo de metadado "Slug/ID" e usa o ID cadastrado como slug do item quando o slug é numérico.', $d ),
            ),
            'view_modes' => array(
                'label'       => __( 'Modos de visualização', $d ),
                'description' => __( 'Adiciona os modos de visualização de itens relacionados (Pessoa e Companhia).', $d ),
            ),
            'related_items_order' => array(
                'label'       => __( 'Ordem dos itens relacionados (Pessoa)', $d ),
                'description' => __( 'Reordena os grupos de itens relacionados exibidos na página de um item.', $d ),
            ),
            'bibliographic' => array(
                'label'       => __( 'Bloco "Como citar"', $d ),
                'description' => __( 'Adiciona a referência bibliográfica ao final da página de cada item.', $d ),
            ),
            'presentations' => array(
                'label'       => __( 'Apresentações', $d ),
                'description' => __( 'Tabela de apresentações com filtros e agrupamento. Escolha abaixo quais partes ativar.', $d ),
            ),
            'presentations_shortcode' => array(
                'label'       => __( 'Shortcode [teatro_apresentacoes]', $d ),
                'description' => __( 'Tabela e formulário de filtros para as páginas do site. Requer o banco de dados.', $d ),
            ),
            'presentations_rest' => array(
                'label'       => __( 'API REST pública', $d ),
                'description' => __( 'Rotas teatromusicadosp/v1/presentations, usadas pelo shortcode para filtrar sem recarregar a página. Requer o banco de dados.', $d ),
            ),
            'presentations_admin_page' => array(
                'label'       => __( 'Página administrativa (menu Espetáculos)', $d ),
                'description' => __( 'Entrada "Espetáculos" no menu do painel.', $d ),
            ),
            Features::TABLE_KEY => array(
                'label'       => __( 'Banco de dados e cache', $d ),
                'description' => __( 'Cria e popula a tabela de apresentações e usa cache (transients) nas consultas. Ao desligar, o cache é limpo e a tabela é mantida.', $d ),
            ),
            Features::SYNC_KEY => array(
                'label'       => __( 'Sincronização com o Tainacan', $d ),
                'description' => __( 'Mantém a tabela igual à coleção Espetáculos: atualiza a cada item salvo no Tainacan e confere tudo a cada 3 dias. Requer o banco de dados e a carga inicial (botão abaixo).', $d ),
            ),
        );
    }

    public function render(): void
    {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $features  = Features::get_instance();
        $copy      = $this->copy();
        $saved     = $features->saved();
        $tainacan  = PresentationsRepository::is_tainacan_available();
        $read_only = ! $tainacan;

        ?>
        <div class="wrap tmsp-settings">
            <h1><?php esc_html_e( 'Teatro Musicado SP — Funcionalidades', 'customizations-teatromusicadosp' ); ?></h1>

            <?php if ( $read_only ) : ?>
                <div class="notice notice-error inline">
                    <p>
                        <?php
                        esc_html_e(
                            'O Tainacan não está instalado e ativo. As funcionalidades dependem dele e não podem ser alteradas até que seja ativado.',
                            'customizations-teatromusicadosp'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e( 'Escolha quais funcionalidades do plugin serão aplicadas ao site.', 'customizations-teatromusicadosp' ); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_GROUP ); ?>

                <ul class="tmsp-features">
                    <?php foreach ( $features->definitions() as $key => $definition ) : ?>
                        <li class="tmsp-feature">
                            <?php $this->render_toggle( $key, $copy[ $key ], ! empty( $saved[ $key ] ), $read_only, 'tmsp-parent' ); ?>

                            <?php if ( ! empty( $definition['children'] ) ) : ?>
                                <ul class="tmsp-children" data-parent="<?php echo esc_attr( $key ); ?>">
                                    <?php foreach ( array_keys( $definition['children'] ) as $child_key ) : ?>
                                        <li class="tmsp-feature tmsp-feature--child">
                                            <?php $this->render_toggle( $child_key, $copy[ $child_key ], ! empty( $saved[ $child_key ] ), $read_only, 'tmsp-child' ); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php
                if ( ! $read_only ) {
                    submit_button( __( 'Salvar alterações', 'customizations-teatromusicadosp' ) );
                }
                ?>
            </form>

            <?php
            if ( $tainacan && $features->is_enabled( 'presentations' ) ) {
                $this->render_sync_status();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Estado da sincronização Tainacan → tabela e botão de conferência/carga.
     */
    private function render_sync_status(): void
    {
        $source     = PresentationsRepository::source();
        $from_csv   = PresentationsRepository::SOURCE_CSV === $source;
        $last_hook  = get_option( PresentationsSync::LAST_RUN_OPTION );
        $next       = wp_next_scheduled( PresentationsReconciler::RECONCILE_EVENT );
        $log        = array_slice( SyncState::recent_log(), 0, 10 );
        $format     = get_option( 'date_format' ) . ' H:i';

        ?>
        <h2><?php esc_html_e( 'Sincronização com o Tainacan', 'customizations-teatromusicadosp' ); ?></h2>

        <table class="widefat striped tmsp-sync-status">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Fonte dos dados', 'customizations-teatromusicadosp' ); ?></th>
                    <td><?php echo esc_html( $from_csv ? __( 'Dados de exemplo (CSV)', 'customizations-teatromusicadosp' ) : __( 'Tainacan — coleção Espetáculos', 'customizations-teatromusicadosp' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Sincronização automática', 'customizations-teatromusicadosp' ); ?></th>
                    <td><?php echo esc_html( PresentationsReconciler::sync_active() ? __( 'Ativa', 'customizations-teatromusicadosp' ) : __( 'Inativa', 'customizations-teatromusicadosp' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Próxima conferência', 'customizations-teatromusicadosp' ); ?></th>
                    <td><?php echo esc_html( $next ? wp_date( $format, $next ) : '—' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Última atualização por edição', 'customizations-teatromusicadosp' ); ?></th>
                    <td><?php echo esc_html( is_array( $last_hook ) ? $this->summarize( $last_hook ) : '—' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Fila / novas tentativas', 'customizations-teatromusicadosp' ); ?></th>
                    <td><?php echo esc_html( SyncState::queue_size() . ' / ' . SyncState::retry_size() ); ?></td>
                </tr>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr( PresentationsReconciler::MANUAL_ACTION ); ?>" />
            <?php wp_nonce_field( PresentationsReconciler::MANUAL_ACTION ); ?>
            <?php
            submit_button(
                $from_csv
                    ? __( 'Fazer a carga inicial a partir do Tainacan', 'customizations-teatromusicadosp' )
                    : __( 'Conferir agora', 'customizations-teatromusicadosp' ),
                'secondary',
                'submit',
                false
            );
            ?>
            <p class="description">
                <?php
                echo esc_html(
                    $from_csv
                        ? __( 'Substitui os dados de exemplo pelos espetáculos publicados no Tainacan e liga a sincronização. A tabela anterior é guardada.', 'customizations-teatromusicadosp' )
                        : __( 'Compara a tabela com o Tainacan e corrige as diferenças. Com muitos itens, pode levar alguns segundos.', 'customizations-teatromusicadosp' )
                );
                ?>
            </p>
        </form>

        <?php if ( $log ) : ?>
            <h3><?php esc_html_e( 'Últimas execuções', 'customizations-teatromusicadosp' ); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Quando (UTC)', 'customizations-teatromusicadosp' ); ?></th>
                        <th><?php esc_html_e( 'Origem', 'customizations-teatromusicadosp' ); ?></th>
                        <th><?php esc_html_e( 'Resultado', 'customizations-teatromusicadosp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $log as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $entry['origin'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( $this->summarize( $entry ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Resumo em uma linha de uma entrada do log de sincronização.
     *
     * @param array<string,mixed> $entry
     */
    private function summarize( array $entry ): string
    {
        if ( ! empty( $entry['error'] ) ) {
            return sprintf( __( 'Erro: %s', 'customizations-teatromusicadosp' ), (string) $entry['error'] );
        }
        if ( isset( $entry['queued'] ) ) {
            return sprintf( __( '%d espetáculo(s) enviados para a fila', 'customizations-teatromusicadosp' ), (int) $entry['queued'] );
        }

        $parts = array();
        $labels = array(
            'inserted'   => __( 'inseridos', 'customizations-teatromusicadosp' ),
            'updated'    => __( 'atualizados', 'customizations-teatromusicadosp' ),
            'deleted'    => __( 'removidos', 'customizations-teatromusicadosp' ),
            'unchanged'  => __( 'sem mudança', 'customizations-teatromusicadosp' ),
            'table_rows' => __( 'linhas na tabela', 'customizations-teatromusicadosp' ),
        );
        foreach ( $labels as $key => $label ) {
            if ( isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ) {
                $parts[] = $entry[ $key ] . ' ' . $label;
            }
        }
        if ( ! empty( $entry['time'] ) && ! isset( $entry['origin'] ) ) {
            array_unshift( $parts, (string) $entry['time'] . ' UTC —' );
        }

        return $parts ? implode( ', ', $parts ) : '—';
    }

    /**
     * Um interruptor (checkbox estilizado) com rótulo e descrição.
     *
     * @param array{label:string,description:string} $text
     */
    private function render_toggle( string $key, array $text, bool $checked, bool $disabled, string $class ): void
    {
        $name = Features::OPTION . '[' . $key . ']';
        $id   = 'tmsp-feature-' . $key;

        ?>
        <label class="tmsp-toggle" for="<?php echo esc_attr( $id ); ?>">
            <input
                type="checkbox"
                id="<?php echo esc_attr( $id ); ?>"
                class="<?php echo esc_attr( $class ); ?>"
                name="<?php echo esc_attr( $name ); ?>"
                value="1"
                data-key="<?php echo esc_attr( $key ); ?>"
                <?php checked( $checked ); ?>
                <?php disabled( $disabled ); ?>
            />
            <span class="tmsp-toggle__track" aria-hidden="true"></span>
            <span class="tmsp-toggle__text">
                <strong><?php echo esc_html( $text['label'] ); ?></strong>
                <span class="description"><?php echo esc_html( $text['description'] ); ?></span>
            </span>
        </label>
        <?php
    }
}
