<?php
/**
 * Shortcode público `[teatro_apresentacoes]`.
 *
 * Renderiza a tabela de apresentações (tabela `{$wpdb->prefix}teatro_presentations`,
 * populada pelo mock Flat_Table.csv) consultando `PresentationsRepository`
 * diretamente (mesmos métodos `query_presentations()`/`query_all_presentations()`
 * usados pelas rotas REST — sem round-trip via `rest_do_request()`).
 *
 * O HTML é gerado no servidor (funciona sem JavaScript e é indexável); um
 * script opcional melhora a experiência fazendo busca/filtro/paginação sem
 * recarregar a página, consumindo a rota REST pública
 * `/wp-json/teatromusicadosp/v1/presentations`, que devolve o mesmo HTML já
 * pronto (`ResultsContentRenderer`) — o JS só troca `innerHTML`.
 *
 * Esta classe é apenas o *controller* do shortcode: o trabalho pesado está em
 * peças coesas dentro de `classes/presentations/`:
 *   - Schema\PresentationsSchema     fonte única das colunas;
 *   - Filters\*                      filtros por coluna (VO + cláusula SQL + facets);
 *   - Grouping\*                     modos de agrupamento + montagem da árvore;
 *   - Rendering\*                    formulário, tabelas e casco.
 *
 * Atributos:
 *   per_page   (int)    linhas por página inicial; só tem efeito se já for uma
 *                       das opções do seletor "Resultados por página" (ver
 *                       PresentationsRequest::RESULTS_PER_PAGE_OPTIONS) — o
 *                       visitante pode trocar livremente depois (padrão 100)
 *   search     (string) busca inicial (varre todas as colunas)
 *   theater    (string) filtra por nome exato do teatro (equivale a tap_f[presentationTheater])
 *   date_from  (string) 'YYYY-MM-DD' — início do intervalo de datas (equivale a tap_from)
 *   date_to    (string) 'YYYY-MM-DD' — fim do intervalo de datas (equivale a tap_to)
 *   orderby    (string) coluna de ordenação (padrão presentationDate)
 *   order      (ASC|DESC)
 */

namespace TeatroMusicadoSP\Customizations\Presentations;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsRequest;
use TeatroMusicadoSP\Customizations\Presentations\Filters\PresentationsFacets;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingModes;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupedPager;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\PresentationGrouper;
use TeatroMusicadoSP\Customizations\Presentations\Rendering\FilterFormRenderer;
use TeatroMusicadoSP\Customizations\Presentations\Rendering\ResultsContentRenderer;
use TeatroMusicadoSP\Customizations\Presentations\Rendering\ResultsViewRenderer;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class PresentationsShortcode implements Module
{
    use Singleton;

    const SHORTCODE        = 'teatro_apresentacoes';
    const HANDLE           = 'teatro-apresentacoes';
    const DEFAULT_PER_PAGE = 25;

    private $assets_url;
    private $assets_path;

    /** Evita localizar o script mais de uma vez na mesma requisição. */
    private $localized = false;

    protected function init() {
        $this->assets_url  = TMSP_CUSTOMIZATIONS_URL . 'assets/';
        $this->assets_path = TMSP_CUSTOMIZATIONS_PATH . 'assets/';
    }

    public function register(): void {
        add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    /**
     * Registra os assets e os enfileira só quando a página atual usa o shortcode.
     */
    public function register_assets(): void {
        $style_ver  = file_exists( $this->assets_path . 'presentations.css' ) ? filemtime( $this->assets_path . 'presentations.css' ) : TMSP_CUSTOMIZATIONS_VERSION;
        $script_ver = file_exists( $this->assets_path . 'presentations.js' ) ? filemtime( $this->assets_path . 'presentations.js' ) : TMSP_CUSTOMIZATIONS_VERSION;

        wp_register_style( self::HANDLE, $this->assets_url . 'presentations.css', [], $style_ver );
        wp_register_script( self::HANDLE, $this->assets_url . 'presentations.js', [], $script_ver, true );

        $post = get_post();
        if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
            $this->enqueue_assets();
        }
    }

    /**
     * Enfileira os assets e passa a configuração para o JS. A localização vive
     * aqui (e não em `register_assets()`) para que a consulta de valores
     * distintos só rode nas páginas que realmente usam o shortcode.
     */
    private function enqueue_assets(): void {
        wp_enqueue_style( self::HANDLE );
        wp_enqueue_script( self::HANDLE );

        if ( $this->localized ) {
            return;
        }
        $this->localized = true;

        wp_localize_script(
            self::HANDLE,
            'TeatroApresentacoes',
            [
                'restUrl'      => esc_url_raw( $this->rest_endpoint_url() ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
                'facets'       => PresentationsRepository::get_instance()->all_facets(),
                'columnLabels' => PresentationsSchema::labels(),
                'filterKeys'   => array_keys( PresentationsSchema::facetable_columns() ),
                'i18n'         => [
                    'loading'         => __( 'Carregando…', 'customizations-teatromusicadosp' ),
                    'empty'           => __( 'Nenhuma apresentação encontrada.', 'customizations-teatromusicadosp' ),
                    'error'           => __( 'Não foi possível carregar as apresentações.', 'customizations-teatromusicadosp' ),
                    'prompt'          => __( 'Use os filtros acima e clique em Buscar para listar as apresentações.', 'customizations-teatromusicadosp' ),
                    'prev'            => __( 'Anterior', 'customizations-teatromusicadosp' ),
                    'next'            => __( 'Próxima', 'customizations-teatromusicadosp' ),
                    /* translators: 1: total de resultados. */
                    'results'         => __( '%s apresentação(ões)', 'customizations-teatromusicadosp' ),
                ],
            ]
        );
    }

    /**
     * Callback do shortcode.
     *
     * @param array|string $atts
     */
    public function render( $atts ): string {
        if ( ! class_exists( PresentationsRepository::class ) ) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'per_page'  => self::DEFAULT_PER_PAGE,
                'search'    => '',
                'theater'   => '',
                'date_from' => '',
                'date_to'   => '',
                'orderby'   => 'presentationDate',
                'order'     => 'ASC',
            ],
            $atts,
            self::SHORTCODE
        );

        // O parsing/sanitização de cada valor acontece dentro de PresentationsRequest.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
        $request    = new PresentationsRequest( (array) $_GET, $atts );
        $filters    = $request->filters();
        $group      = $request->group();
        $is_grouped = '' !== $group;
        $submitted  = $request->has_submitted();

        $this->enqueue_assets();

        $rows        = [];
        $total       = 0;
        $total_pages = 0;
        $table_html  = '';

        // Sem primeiro carregamento: a tabela só consulta o banco depois do 1º
        // submit do formulário (marcador `tap_go`).
        if ( $submitted && $is_grouped ) {
            $mode = GroupingModes::get( $group );
            $rows = PresentationsRepository::get_instance()->query_all_presentations(
                [
                    'orderby' => $request->orderby(),
                    'order'   => $request->order(),
                    'search'  => $request->search(),
                    'filters' => $filters,
                ]
            );
            $total       = count( $rows );
            $tree        = ( new PresentationGrouper() )->build(
                $rows,
                $mode,
                [ 'orderby' => $request->orderby(), 'order' => $request->order() ]
            );
            $paged       = ( new GroupedPager() )->paginate( $tree, $request->results_per_page(), $request->page() );
            $total_pages = $paged['total_pages'];
            $table_html  = ResultsContentRenderer::grouped( $rows, $paged['tree'], $mode );
        } elseif ( $submitted ) {
            $per_page = $request->results_per_page();

            // 0 = "Sem Paginação": mesma consulta sem `LIMIT`/`OFFSET` já usada
            // pelo modo agrupado (mesmo teto de segurança de 10 mil linhas).
            if ( 0 === $per_page ) {
                $rows        = PresentationsRepository::get_instance()->query_all_presentations(
                    [
                        'orderby' => $request->orderby(),
                        'order'   => $request->order(),
                        'search'  => $request->search(),
                        'filters' => $filters,
                    ]
                );
                $total       = count( $rows );
                $total_pages = 1;
            } else {
                $result = PresentationsRepository::get_instance()->query_presentations(
                    [
                        'page'     => $request->page(),
                        'per_page' => $per_page,
                        'orderby'  => $request->orderby(),
                        'order'    => $request->order(),
                        'search'   => $request->search(),
                        'filters'  => $filters,
                    ]
                );

                $rows        = $result['data'];
                $total       = $result['total'];
                $total_pages = (int) ceil( $total / $per_page );
            }
            $table_html = ResultsContentRenderer::flat( $rows );
        }

        $page_url = get_permalink();
        if ( ! $page_url ) {
            $page_url = home_url( add_query_arg( [] ) );
        }

        $facets    = new PresentationsFacets( PresentationsRepository::get_instance() );
        $form_html = ( new FilterFormRenderer( $facets, $page_url ) )->render(
            $request->search(),
            $request->has_request_search(),
            $group,
            $filters,
            $request->orderby(),
            $request->order()
        );

        return ( new ResultsViewRenderer( $this->rest_endpoint_url(), $page_url ) )->render(
            [
                'is_grouped'         => $is_grouped,
                'submitted'          => $submitted,
                'rows'               => $rows,
                'total'              => $total,
                'total_pages'        => $total_pages,
                'paged'              => $request->page(),
                'results_per_page'   => $request->results_per_page(),
                'orderby'            => $request->orderby(),
                'order'              => $request->order(),
                'has_request_orderby' => $request->has_request_orderby(),
                'search'             => $request->search(),
                'has_request_search' => $request->has_request_search(),
                'group'              => $group,
                'filters'            => $filters,
                'form_html'          => $form_html,
                'table_html'         => $table_html,
            ]
        );
    }

    /**
     * URL completa da rota REST de apresentações.
     */
    private function rest_endpoint_url(): string {
        return rest_url( PresentationsRepository::REST_NAMESPACE . PresentationsRepository::REST_ROUTE );
    }
}
