<?php

namespace TeatroMusicadoSP\Customizations\ViewModes;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\References\TainacanIds;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class RegisterViewModes implements Module
{
    use Singleton;

    public $componentsFolderURL = TMSP_CUSTOMIZATIONS_URL . 'components/';
    public $componentsFolderPath = TMSP_CUSTOMIZATIONS_PATH . 'components/';

    public function register(): void {
        add_action( 'tainacan-register-vuejs-component', array( $this, 'register_viewmode_components' ) );
        add_action( 'wp_print_scripts', array($this, 'viewmode_components_enqueue_styles') );
        // Os componentes Vue leem os IDs de coleção/metadado de window.TMSP_TAINACAN_IDS.
        add_action( 'wp_enqueue_scripts', array( TainacanIds::class, 'enqueue_for_js' ) );
        add_action( 'admin_enqueue_scripts', array( TainacanIds::class, 'enqueue_for_js' ) );
    }

    function register_viewmode_components($helper) {

        if ( function_exists( 'tainacan_register_view_mode' ) ) {

            // While `npm run dev` (webpack-dev-server) is running, load the bundle straight from
            // it instead of the built file, so edits hot-reload. Enable by adding
            // define('TEATRO_COMPONENTS_DEV_SERVER', true); to wp-config.php.
            if ( defined('TEATRO_COMPONENTS_DEV_SERVER') && TEATRO_COMPONENTS_DEV_SERVER ) {
                $component_script_url = 'http://127.0.0.1:8080/components.bundle.js';
            } else {
                $component_script_url = $this->componentsFolderURL . '/build/components.bundle.js';

                // Tainacan's register_vuejs_component() always enqueues with its own
                // TAINACAN_VERSION as the cache-busting `ver`, so bumping this plugin's
                // version has no effect on the browser cache for this bundle. Append the
                // built file's mtime as our own cache buster instead, so production picks
                // up a new bundle automatically on every build.
                $bundle_path = $this->componentsFolderPath . 'build/components.bundle.js';
                if ( file_exists( $bundle_path ) ) {
                    $component_script_url = add_query_arg( 'v', filemtime( $bundle_path ), $component_script_url );
                }
            }

            $helper->register_vuejs_component('teatro-person-viewmode', $component_script_url, [ 'public' => true, 'deps' => ['wp-i18n'] ], null, true);
            // Registering the view mode
            tainacan_register_view_mode('person-viewmode', [
                'label' 				=> 'Pessoa',
                'description' 			=> __('Visualização de itens relacionados na página de item da coleção Pessoa', 'customizations-teatromusicadosp'),
                'icon' 					=> '<span class="icon"><i><svg fill="var(--tainacan-info-color, #555758)" xmlns="http://www.w3.org/2000/svg" height="24" width="24"><path d="M8.492 6.074h7.016v11.852H8.492zM4.943 7.477h2.806v9.046H4.943zM16.251 7.477h2.807v9.046H16.25zM19.8 8.442h1.884v7.116h-1.883zM2.316 8.442h1.883v7.116H2.316z"/></svg></i></span>',
                'type' 					=> 'component',
                'component' 			=> 'view-mode-person',
                'dynamic_metadata' 		=> true,
                'implements_skeleton' 	=> true
                ]);
                
            $helper->register_vuejs_component('teatro-company-viewmode', $component_script_url, [ 'public' => true, 'deps' => ['wp-i18n'] ], null, true);
            // Registering the view mode
            tainacan_register_view_mode('company-viewmode', [
                'label' 				=> 'Companhia',
                'description' 			=> __('Visualização de itens relacionados na página de item da coleção Companhia', 'customizations-teatromusicadosp'),
                'icon' 					=> '<span class="icon"><i><svg fill="var(--tainacan-info-color, #555758)" xmlns="http://www.w3.org/2000/svg" height="24" width="24"><path d="M8.492 6.074h7.016v11.852H8.492zM4.943 7.477h2.806v9.046H4.943zM16.251 7.477h2.807v9.046H16.25zM19.8 8.442h1.884v7.116h-1.883zM2.316 8.442h1.883v7.116H2.316z"/></svg></i></span>',
                'type' 					=> 'component',
                'component' 			=> 'view-mode-company',
                'dynamic_metadata' 		=> true,
                'implements_skeleton' 	=> true
            ]);
        }
    }

    function viewmode_components_enqueue_styles() {
        // Enqueue template view mode styles
        wp_enqueue_style( 'tainacan-extra-viewmodes-view-mode-person', $this->componentsFolderURL . '/css/_view-mode-person.css', [], TMSP_CUSTOMIZATIONS_VERSION );
        wp_enqueue_style( 'tainacan-extra-viewmodes-view-mode-company', $this->componentsFolderURL . '/css/_view-mode-company.css', [], TMSP_CUSTOMIZATIONS_VERSION );
    }
}
