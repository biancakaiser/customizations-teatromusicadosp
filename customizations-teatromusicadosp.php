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
 * IDs de coleções/taxonomias/metadados do Tainacan — referência única, usada
 * por todos os módulos abaixo (nenhum outro arquivo deve fixar esses IDs).
 */
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/references/tainacan-ids.php';

/*
 * Configurações (quais funcionalidades estão ligadas). Precisa vir antes do
 * registro do hook de metadados, mais abaixo, que já consulta esse estado.
 */
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/settings/features.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/settings/settings-page.php';

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
    . 'classes/presentations/grouping/grouped-pager.php';

require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/presentations-repository.php';

/*
 * Sincronização Tainacan (coleção Espetáculos) → tabela de apresentações:
 * projeção, escrita, estado (log/filas), conferência/backfill e hooks.
 */
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/sync/espetaculo-projector.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/sync/sync-state.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/sync/presentations-sync-writer.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/sync/presentations-reconciler.php';
require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/presentations/sync/presentations-sync.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once TMSP_CUSTOMIZATIONS_PATH
        . 'classes/presentations/sync/presentations-sync-cli.php';
}

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
    array( '\TeatroMusicadoSP\Customizations\Settings\Features', 'on_activation' )
);

// Instalações anteriores à página de configurações mantêm tudo ligado.
\TeatroMusicadoSP\Customizations\Settings\Features::get_instance()->maybe_migrate();

require_once TMSP_CUSTOMIZATIONS_PATH
    . 'classes/metadata-types/register-metadatas.php';
    
/*
* Precisa ser registrado imediatamente (fora do ciclo 'plugins_loaded') porque
* o Tainacan dispara 'tainacan-register-metadata-type' no carregamento do seu
* próprio arquivo principal, antes de 'plugins_loaded' ser executado.
*/
if ( \TeatroMusicadoSP\Customizations\Settings\Features::get_instance()->is_enabled( 'metadata_types' ) ) {
    add_action(
        'tainacan-register-metadata-type',
        array(
            \TeatroMusicadoSP\Customizations\MetadataTypes\RegisterMetadatas::get_instance(),
            'register_metadata_type'
        )
    );
}
            
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
