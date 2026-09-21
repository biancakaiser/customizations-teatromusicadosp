<?php

namespace TeatroMusicadoSP\Customizations;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\Settings\Features;
use TeatroMusicadoSP\Customizations\Settings\SettingsPage;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class Plugin
{
    use Singleton;

    /**
     * Conecta a inicialização do plugin ao WordPress.
     */
    public function register(): void
    {
        add_action(
            'plugins_loaded',
            array($this, 'boot')
        );
    }

    /**
     * Inicializa as customizações disponíveis.
     */
    public function boot(): void
    {
        load_plugin_textdomain(
            'customizations-teatromusicadosp',
            false,
            dirname(
                plugin_basename(TMSP_CUSTOMIZATIONS_FILE)
            ) . '/languages'
        );

        // Antes da checagem do Tainacan: a página precisa existir mesmo sem ele.
        $this->register_module(SettingsPage::get_instance());

        if (! $this->is_tainacan_available()) {
            add_action(
                'admin_notices',
                array($this, 'render_missing_tainacan_notice')
            );

            return;
        }

        foreach (Features::get_instance()->enabled_modules() as $module_class) {
            $this->register_module($module_class::get_instance());
        }
    }

    /**
     * Registra um módulo do plugin.
     */
    private function register_module(Module $module): void
    {
        $module->register();
    }

    /**
     * Verifica se as APIs necessárias do Tainacan existem.
     */
    private function is_tainacan_available(): bool
    {
        return function_exists('tainacan_metadata')
            && function_exists('tainacan_items')
            && function_exists('tainacan_collections')
            && function_exists('tainacan_taxonomies');
    }

    /**
     * Informa que o Tainacan é necessário.
     */
    public function render_missing_tainacan_notice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        ?>
        <div class="notice notice-error">
            <p>
                <?php
                echo esc_html__(
                    'O plugin Teatro Musicado SP requer que o Tainacan esteja instalado e ativo.',
                    'customizations-teatromusicadosp'
                );
                ?>
            </p>
        </div>
        <?php
    }
}