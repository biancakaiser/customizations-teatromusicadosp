<?php
/**
 * Admin custom page for the Teatro Musicado plugin.
 */

namespace TeatroMusicadoSP\Customizations\Presentations;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class PresentationsPage implements Module
{
    use Singleton;

    const PAGE_SLUG = 'teatromusicadosp-presentations';

    /** Quantidade de linhas por página da tabela. */
    const PER_PAGE = 50;

    public function register(): void {
        add_action('admin_menu', array($this, 'register_presentation_page'));
    }

    public function register_presentation_page(): void {
        if (!function_exists('add_menu_page')) {
            return;
        }

        add_menu_page(
            'Espetáculos',
            'Espetáculos',
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_presentation_page'),
            'dashicons-admin-generic',
            26
        );
    }

    public function render_presentation_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1>Espetáculos</h1>
            <p>Esta é a página administrativa personalizada do plugin Teatro Musicado.</p>
        </div>
        <?php
    }
}
