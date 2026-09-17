<?php
/**
 * Renderiza o "casco" do shortcode: a `<div>` raiz com os `data-*` que o
 * `assets/presentations.js` lê, o container da tabela, a paginação
 * (`paginate_links()`) e o parágrafo de contagem.
 *
 * O `<form>` de filtros e a tabela em si chegam prontos (`form_html` /
 * `table_html` — este último já inclui a mensagem de "nada encontrado"
 * quando não há linhas, ver `ResultsContentRenderer`).
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Rendering;

use TeatroMusicadoSP\Customizations\Presentations\PresentationsRequest;
use TeatroMusicadoSP\Customizations\Presentations\Filters\PresentationFilters;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class ResultsViewRenderer
{
    /** @var string */
    private $rest_url;

    /** @var string */
    private $page_url;

    public function __construct( string $rest_url, string $page_url ) {
        $this->rest_url = $rest_url;
        $this->page_url = $page_url;
    }

    /**
     * @param array{
     *   is_grouped:bool,
     *   submitted:bool,
     *   rows:array<int,array<string,mixed>>,
     *   total:int,
     *   total_pages:int,
     *   paged:int,
     *   per_page:int,
     *   orderby:string,
     *   order:string,
     *   search:string,
     *   has_request_search:bool,
     *   group:string,
     *   filters:PresentationFilters,
     *   form_html:string,
     *   table_html:string
     * } $ctx
     */
    public function render( array $ctx ): string {
        $is_grouped  = (bool) $ctx['is_grouped'];
        $total       = (int) $ctx['total'];
        $total_pages = (int) $ctx['total_pages'];
        $paged       = (int) $ctx['paged'];
        $has_request_orderby = ! empty( $ctx['has_request_orderby'] );
        $submitted           = ! empty( $ctx['submitted'] );
        /** @var PresentationFilters $filters */
        $filters = $ctx['filters'];

        ob_start();
        ?>
        <div
            class="teatro-apresentacoes alignfull"
            data-rest="<?php echo esc_url( $this->rest_url ); ?>"
            data-submitted="<?php echo esc_attr( $submitted ? '1' : '' ); ?>"
            data-per-page="<?php echo esc_attr( (string) $ctx['per_page'] ); ?>"
            data-orderby="<?php echo esc_attr( (string) $ctx['orderby'] ); ?>"
            data-order="<?php echo esc_attr( strtoupper( (string) $ctx['order'] ) === 'DESC' ? 'DESC' : 'ASC' ); ?>"
            data-preset-search="<?php echo esc_attr( $ctx['has_request_search'] ? '' : (string) $ctx['search'] ); ?>"
            data-group="<?php echo esc_attr( $is_grouped ? (string) $ctx['group'] : '' ); ?>"
            data-sort-columns="<?php echo esc_attr( (string) wp_json_encode( FilterFormRenderer::sort_options_by_mode() ) ); ?>"
        >
            <?php echo $ctx['form_html']; // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <div class="teatro-apresentacoes__table-wrap<?php echo $is_grouped ? ' teatro-apresentacoes__table-wrap--grouped' : ''; ?>" aria-live="polite">
                <?php if ( ! $submitted ) : ?>
                    <p class="teatro-apresentacoes__prompt">
                        <?php esc_html_e( 'Use os filtros acima e clique em Buscar para listar as apresentações.', 'customizations-teatromusicadosp' ); ?>
                    </p>
                <?php else : ?>
                    <?php // table_html já traz a mensagem de "nada encontrado" quando $rows está vazio (ver ResultsContentRenderer). ?>
                    <?php echo $ctx['table_html']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php endif; ?>
            </div>

            <?php if ( $submitted && $total_pages > 1 ) : ?>
                <nav class="teatro-apresentacoes__pagination" aria-label="<?php esc_attr_e( 'Paginação', 'customizations-teatromusicadosp' ); ?>">
                    <?php
                    echo wp_kses_post(
                        (string) paginate_links(
                            [
                                'base'      => add_query_arg( PresentationsRequest::QV_PAGED, '%#%', $this->page_url ),
                                'format'    => '',
                                'current'   => $paged,
                                'total'     => $total_pages,
                                'add_args'  => $this->pagination_args(
                                $filters,
                                (string) $ctx['group'],
                                (bool) $ctx['has_request_search'],
                                (string) $ctx['search'],
                                $has_request_orderby,
                                (string) $ctx['orderby'],
                                (string) $ctx['order']
                            ),
                                'prev_text' => __( '&laquo; Anterior', 'customizations-teatromusicadosp' ),
                                'next_text' => __( 'Próxima &raquo;', 'customizations-teatromusicadosp' ),
                            ]
                        )
                    );
                    ?>
                </nav>
            <?php endif; ?>

            <p class="teatro-apresentacoes__count" role="status"<?php echo $submitted ? '' : ' hidden'; ?>>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: total de apresentações. */
                        _n( '%s apresentação', '%s apresentações', $total, 'customizations-teatromusicadosp' ),
                        number_format_i18n( $total )
                    )
                );
                ?>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Args extra dos links de paginação: preserva busca, filtros e agrupamento
     * ativos para quem navega sem JavaScript.
     *
     * @return array<string,mixed>
     */
    private function pagination_args(
        PresentationFilters $filters,
        string $group,
        bool $has_request_search,
        string $search,
        bool $has_request_orderby = false,
        string $orderby = 'presentationDate',
        string $order = 'ASC'
    ): array {
        $args = $filters->to_query_args();

        // A paginação só existe depois do 1º submit — preserva o marcador para
        // quem navega sem JavaScript.
        $args[ PresentationsRequest::QV_SUBMITTED ] = '1';

        if ( $has_request_search ) {
            $args[ PresentationsRequest::QV_SEARCH ] = $search;
        }

        if ( '' !== $group ) {
            $args[ PresentationsRequest::QV_GROUP ] = $group;
        }

        if ( $has_request_orderby ) {
            $args[ PresentationsRequest::QV_ORDERBY ] = $orderby;
            $args[ PresentationsRequest::QV_ORDER ]   = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';
        }

        return $args;
    }
}
