<?php

namespace TeatroMusicadoSP\Customizations\Form;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class CollectionForm implements Module
{
    use Singleton;

    const MONTAGEM_COLLECTION_ID = '3922';

    /**
     * ID do container que a instância Vue do Form Hook (bundle próprio,
     * ver components/src/espetaculos-form-hook/) procura para se montar.
     */
    const ESPETACULOS_APP_CONTAINER_ID = 'teatro-espetaculos-app';

    public $componentsFolderURL = TMSP_CUSTOMIZATIONS_URL . 'components/';
    public $componentsFolderPath = TMSP_CUSTOMIZATIONS_PATH . 'components/';

    public function register(): void {
        add_action('tainacan-register-admin-hooks', array($this, 'register_admin_hook'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_espetaculos_table_assets'));
    }

    public function register_admin_hook(): void {
        if (! function_exists('tainacan_register_admin_hook')) {
            return;
        }

        tainacan_register_admin_hook(
            'item',
            array($this, 'form'),
            'end-right',
            [ 'collectionId' => self::MONTAGEM_COLLECTION_ID ]
        );
    }

    public function form() {
        if (!function_exists('tainacan_get_api_postdata')) {
            return '';
        }

        ob_start();
        ?>
        <div class="field tainacan-collection--section-header">
            <h4><?php _e('Espetáculos', 'customizations-teatromusicadosp'); ?></h4>
            <p class="description">
                <?php _e('SPIKE de usabilidade — tabela com dados de exemplo (mock), sem integração com a API do Tainacan.', 'customizations-teatromusicadosp'); ?>
            </p>
            <hr>
        </div>

        <div class="field">
            <div id="<?php echo esc_attr(self::ESPETACULOS_APP_CONTAINER_ID); ?>">
                <p><?php _e('Carregando tabela de Espetáculos…', 'customizations-teatromusicadosp'); ?></p>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Carrega o bundle Vue e o CSS do Form Hook de Espetáculos, apenas na
     * SPA do Tainacan (onde o container do form() é renderizado via v-html).
     */
    public function enqueue_espetaculos_table_assets(): void {
        if (! isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'tainacan_admin') {
            return;
        }

        // Enquanto `npm run dev` (webpack-dev-server) está rodando, carrega o
        // bundle direto dele em vez do arquivo buildado, para hot-reload.
        // Ative com define('TEATRO_COMPONENTS_DEV_SERVER', true); no
        // wp-config.php (mesma flag usada pelos view modes).
        if (defined('TEATRO_COMPONENTS_DEV_SERVER') && TEATRO_COMPONENTS_DEV_SERVER) {
            $script_url = 'http://127.0.0.1:8080/espetaculos-form-hook.bundle.js';
            $version = null;
        } else {
            $script_url = $this->componentsFolderURL . 'build/espetaculos-form-hook.bundle.js';

            $bundle_path = $this->componentsFolderPath . 'build/espetaculos-form-hook.bundle.js';
            $version = file_exists($bundle_path) ? filemtime($bundle_path) : TMSP_CUSTOMIZATIONS_VERSION;
        }

        wp_enqueue_script(
            'teatro-espetaculos-form-hook',
            $script_url,
            [],
            $version,
            true
        );

        wp_enqueue_style(
            'teatro-espetaculos-form-hook',
            $this->componentsFolderURL . 'css/_espetaculos-form-hook.css',
            [],
            TMSP_CUSTOMIZATIONS_VERSION
        );
    }
}
