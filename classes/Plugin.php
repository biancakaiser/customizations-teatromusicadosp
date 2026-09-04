<?php

namespace TeatroMusicadoSP\Customizations;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\Form\CollectionForm;
use TeatroMusicadoSP\Customizations\MetadataTypes\RegisterMetadatas;
use TeatroMusicadoSP\Customizations\ViewModes\RegisterViewModes;
use TeatroMusicadoSP\Customizations\RelatedItems\PessoaRelatedItemsOrder;
use TeatroMusicadoSP\Customizations\Blocks\Bibliographic;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsPage;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsShortcode;

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

        if (! $this->is_tainacan_available()) {
            add_action(
                'admin_notices',
                array($this, 'render_missing_tainacan_notice')
            );

            return;
        }

        $modules = array(
            CollectionForm::get_instance(),
            RegisterMetadatas::get_instance(),
            RegisterViewModes::get_instance(),
            PessoaRelatedItemsOrder::get_instance(),
            Bibliographic::get_instance(),
            PresentationsPage::get_instance(),
            PresentationsRepository::get_instance(),
            PresentationsShortcode::get_instance()
        );

        foreach ($modules as $module) {
            $this->register_module($module);
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