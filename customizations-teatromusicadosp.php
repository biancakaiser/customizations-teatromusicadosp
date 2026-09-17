<?php
/**
 * Plugin Name: Customizations - Teatro Musicado SP
 * Description: Customizações do projeto Teatro Musicado SP.
 * Version: 1.0.0
 * Author: Teatro Musicado SP
 * Text Domain: customizations-teatromusicadosp
 */

/** Plugin version */
const TEATRO_CUSTOMIZATIONS_PLUGIN_VERSION = '0.0.1';

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define('TMSP_CUSTOMIZATIONS_VERSION', '1.0.0');
define('TMSP_CUSTOMIZATIONS_FILE', __FILE__);
define(
    'TMSP_CUSTOMIZATIONS_PATH',
    plugin_dir_path(__FILE__)
);
define(
    'TMSP_CUSTOMIZATIONS_URL',
    plugin_dir_url(__FILE__)
);

/*
 * A ordem do carregamento manual é importante:
 *
 * 1. contratos;
 * 2. recursos compartilhados;
 * 3. modulos (metadados, modos de exibição, etc.);
 * 4. classe principal.
 */
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/contracts/module.php';

require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/traits/singleton.php';

/*
 * Apresentações: schema (fonte única das colunas) e utilitários sem estado
 * primeiro; depois o repositório; então as peças que dependem dele (facets,
 * renderers, parser de request) e, por fim, o shortcode.
 */
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/schema/presentation-column.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/schema/presentations-schema.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/filters/presentation-filters.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/filters/presentation-filter-clause.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/grouping/grouping-mode.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/grouping/grouping-modes.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/rendering/presentation-value.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/grouping/presentation-grouper.php';

require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/presentations-repository.php';

require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/filters/presentation-facets.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/rendering/flat-table-renderer.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/rendering/grouped-table-renderer.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/rendering/results-content-renderer.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/rendering/filter-form-renderer.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/rendering/results-view-renderer.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/presentation-request.php';

require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/presentations-shortcode.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/presentations-page.php';

register_activation_hook(
    __FILE__,
    array( '\TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository', 'install' )
);

require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/metadata-types/register-metadatas.php';
    
/*
* Precisa ser registrado imediatamente (fora do ciclo 'plugins_loaded') porque
* o Tainacan dispara 'tainacan-register-metadata-type' no carregamento do seu
* próprio arquivo principal, antes de 'plugins_loaded' ser executado.
*/
add_action(
    'tainacan-register-metadata-type',
    array(
        \TeatroMusicadoSP\Customizations\MetadataTypes\RegisterMetadatas::get_instance(),
        'register_metadata_type'
    )
);
            
require_once TMSP_CUSTOMIZATIONS_PATH
. 'classes/view-modes/register-viewmodes.php';

require_once TMSP_CUSTOMIZATIONS_PATH
. 'classes/related-items/pessoa-related-items-order.php';

require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/form/collection-form.php';
    
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/blocks/bibliographic.php';

require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/Plugin.php';

TeatroMusicadoSP\Customizations\Plugin::get_instance()->register();

?>
