<?php
/**
 * Shortcode público `[teatro_apresentacoes]`.
 *
 * Renderiza a tabela de apresentações (tabela `{$wpdb->prefix}teatro_presentations`,
 * populada pelo mock Flat_Table.csv) consultando a rota REST pública
 * `/wp-json/teatromusicadosp/v1/presentations` via `rest_do_request()`.
 *
 * O HTML é gerado no servidor (funciona sem JavaScript e é indexável); um
 * script opcional melhora a experiência fazendo busca/paginação sem recarregar
 * a página, consumindo a mesma rota REST.
 *
 * O campo "Agrupado por" (query var `tap_group`: `company` ou `play`) reformata
 * a saída num único `<table>` (cabeçalho de colunas uma vez) com um `<tbody>`
 * por grupo de 1º nível, iniciado por uma faixa. Hierarquia de 4 níveis:
 *   1. companhia OU peça — a faixa do grupo, com "Nº Peças/Companhias" e o
 *      total de sessões do grupo;
 *   2. o dado inverso (peça OU companhia) — bloco de identidade mesclado com
 *      rowspan, e a coluna "Total" com a soma daquele bloco;
 *   3. teatro;
 *   4. tipo de espetáculo + ano (a data é agrupada e exibida por ano).
 * As linhas "folha" somam `sessionsNumber` por (teatro, tipo, ano). Com
 * agrupamento ativo a paginação some (todas as linhas são lidas).
 *
 * Atributos:
 *   per_page  (int)    linhas por página (padrão 25, máx. 200)
 *   search    (string) busca inicial (varre todas as colunas)
 *   theater   (string) filtra por nome exato do teatro
 *   year      (int)     filtra por ano da temporada (settingYear)
 *   orderby   (string) coluna de ordenação (padrão presentationDate)
 *   order     (ASC|DESC)
 */

namespace TeatroMusicadoSP\Customizations\Presentations;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class PresentationsShortcode implements Module
{
    use Singleton;

    const SHORTCODE        = 'teatro_apresentacoes';
    const HANDLE           = 'teatro-apresentacoes';
    const DEFAULT_PER_PAGE = 25;

    /** Query vars próprias, com prefixo para não colidir com a query principal. */
    const QV_SEARCH = 'tap_s';
    const QV_PAGED  = 'tap_paged';
    const QV_GROUP  = 'tap_group';

    /**
     * Colunas da tabela plana (modo "Nenhum"), na ordem de exibição.
     *
     * @var array<string,string> nome da coluna => rótulo
     */
    const COLUMNS_FLAT = [
        'presentationDate'   => 'Data',
        'companyName'        => 'Nome da Companhia',
        'companyNationality' => 'Nacionalidade da Companhia',
        'playName'           => 'Título da Peça',
        'genre'              => 'Gênero',
        'playNationality'    => 'Nacionalidade',
        'playLanguage'       => 'Idioma',
        'theaterName'        => 'Teatro',
        'settingKind'        => 'Tipo de Espetáculo',
        'sessionsNumber'     => 'Nº de Sessões',
    ];

    /** Rótulos das colunas "folha", comuns às micro-tabelas dos dois modos. */
    const GROUP_LEAF_LABELS = [ 'Teatro', 'Tipo de Espetáculo', 'Ano', 'Nº de Sessões', 'Total' ];

    /**
     * Modos do campo "Agrupado por".
     *
     * - `l1`  coluna do 1º nível (vira a faixa de cabeçalho do grupo);
     * - `l2`  coluna do 2º nível — o dado inverso ao `l1`; `identity` são as
     *         colunas desse bloco (mescladas com rowspan no grupo `l2`);
     * - `l1_label_fields`  "Rótulo => coluna" que compõem a faixa do 1º nível
     *   (ex.: "Companhia: X - Nacionalidade: Y");
     * - `count_label`  rótulo da contagem de grupos de 2º nível na faixa
     *   (ex.: "Nº Peças" quando se agrupa por companhia).
     *
     * O 3º nível é sempre `theaterName` e o 4º `settingKind` + ano.
     *
     * @var array<string,array{label:string,l1:string,l2:string,identity:array<string,string>,l1_label_fields:array<string,string>,count_label:string}>
     */
    const GROUPS = [
        'company' => [
            'label'           => 'Companhia',
            'l1'              => 'companyName',
            'l2'              => 'playName',
            'count_label'     => 'Nº Peças',
            'identity'        => [
                'playName'        => 'Título da Peça',
                'genre'           => 'Gênero',
                'playNationality' => 'Nacionalidade',
                'playLanguage'    => 'Idioma',
            ],
            'l1_label_fields' => [
                'Companhia'     => 'companyName',
                'Nacionalidade' => 'companyNationality',
            ],
        ],
        'play' => [
            'label'           => 'Peça',
            'l1'              => 'playName',
            'l2'              => 'companyName',
            'count_label'     => 'Nº Companhias',
            'identity'        => [
                'companyName'        => 'Nome da Companhia',
                'companyNationality' => 'Nacionalidade da Companhia',
                'settingLanguage'    => 'Idioma',
            ],
            'l1_label_fields' => [
                'Peça'          => 'playName',
                'Gênero'        => 'genre',
                'Nacionalidade' => 'playNationality',
            ],
        ],
    ];

    private $assets_url;
    private $assets_path;

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

        wp_localize_script(
            self::HANDLE,
            'TeatroApresentacoes',
            [
                'restUrl' => esc_url_raw( rest_url( PresentationsRepository::REST_NAMESPACE . PresentationsRepository::REST_ROUTE ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'i18n'    => [
                    'loading' => __( 'Carregando…', 'customizations-teatromusicadosp' ),
                    'empty'   => __( 'Nenhuma apresentação encontrada.', 'customizations-teatromusicadosp' ),
                    'error'   => __( 'Não foi possível carregar as apresentações.', 'customizations-teatromusicadosp' ),
                    'prev'    => __( 'Anterior', 'customizations-teatromusicadosp' ),
                    'next'    => __( 'Próxima', 'customizations-teatromusicadosp' ),
                    /* translators: 1: total de resultados. */
                    'results' => __( '%s apresentação(ões)', 'customizations-teatromusicadosp' ),
                ],
            ]
        );

        $post = get_post();
        if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
            $this->enqueue_assets();
        }
    }

    private function enqueue_assets(): void {
        wp_enqueue_style( self::HANDLE );
        wp_enqueue_script( self::HANDLE );
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
                'per_page' => self::DEFAULT_PER_PAGE,
                'search'   => '',
                'theater'  => '',
                'year'     => '',
                'orderby'  => 'presentationDate',
                'order'    => 'ASC',
            ],
            $atts,
            self::SHORTCODE
        );

        // A busca digitada pelo visitante prevalece sobre o atributo do shortcode.
        $has_request_search = isset( $_GET[ self::QV_SEARCH ] );
        $search   = $has_request_search
            ? sanitize_text_field( wp_unslash( $_GET[ self::QV_SEARCH ] ) )
            : (string) $atts['search'];
        $paged    = isset( $_GET[ self::QV_PAGED ] ) ? max( 1, (int) $_GET[ self::QV_PAGED ] ) : 1;
        $per_page = min( 200, max( 1, (int) $atts['per_page'] ) );
        $theater  = (string) $atts['theater'];
        $year     = (int) $atts['year'];

        $group = isset( $_GET[ self::QV_GROUP ] )
            ? sanitize_key( wp_unslash( $_GET[ self::QV_GROUP ] ) )
            : '';
        $is_grouped = array_key_exists( $group, self::GROUPS );

        $this->enqueue_assets();

        $columns = self::COLUMNS_FLAT;

        if ( $is_grouped ) {
            // Agrupamento precisa de todas as linhas (os grupos podem cruzar
            // páginas), então varre a rota REST até esgotar os resultados.
            $rows        = $this->fetch_all_rows( $search, $theater, $year, (string) $atts['orderby'], (string) $atts['order'] );
            $total       = count( $rows );
            $grouped     = $this->build_groups( $rows, $group );
            $total_pages = 1;
        } else {
            $request = new \WP_REST_Request(
                'GET',
                '/' . PresentationsRepository::REST_NAMESPACE . PresentationsRepository::REST_ROUTE
            );
            $request->set_query_params(
                [
                    'page'     => $paged,
                    'per_page' => $per_page,
                    'orderby'  => (string) $atts['orderby'],
                    'order'    => (string) $atts['order'],
                    'search'   => $search,
                    'theater'  => $theater,
                    'year'     => $year,
                ]
            );

            $response = rest_do_request( $request );

            if ( $response->is_error() ) {
                return '<div class="teatro-apresentacoes teatro-apresentacoes--error"><p>'
                    . esc_html__( 'Não foi possível carregar as apresentações.', 'customizations-teatromusicadosp' )
                    . '</p></div>';
            }

            $rows    = (array) $response->get_data();
            $headers = $response->get_headers();
            $total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );
            $grouped = [];

            $total_pages = (int) ceil( $total / $per_page );
        }

        $page_url    = get_permalink();
        if ( ! $page_url ) {
            $page_url = home_url( add_query_arg( [] ) );
        }

        ob_start();
        ?>
        <div
            class="teatro-apresentacoes alignfull"
            data-rest="<?php echo esc_url( rest_url( PresentationsRepository::REST_NAMESPACE . PresentationsRepository::REST_ROUTE ) ); ?>"
            data-per-page="<?php echo esc_attr( (string) $per_page ); ?>"
            data-orderby="<?php echo esc_attr( (string) $atts['orderby'] ); ?>"
            data-order="<?php echo esc_attr( strtoupper( (string) $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC' ); ?>"
            data-theater="<?php echo esc_attr( $theater ); ?>"
            data-year="<?php echo esc_attr( (string) $year ); ?>"
            data-preset-search="<?php echo esc_attr( $has_request_search ? '' : (string) $atts['search'] ); ?>"
            data-group="<?php echo esc_attr( $is_grouped ? $group : '' ); ?>"
            data-columns="<?php echo esc_attr( (string) wp_json_encode( $columns ) ); ?>"
        >
            <form class="teatro-apresentacoes__search" method="get" action="<?php echo esc_url( $page_url ); ?>" role="search">
                <?php echo $this->preserved_query_fields(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <div class="teatro-apresentacoes__search-field">
                    <label for="teatro-apresentacoes-s" class="teatro-apresentacoes__search-label">
                        <?php esc_html_e( 'Buscar apresentações', 'customizations-teatromusicadosp' ); ?>
                    </label>
                    <input
                        type="search"
                        id="teatro-apresentacoes-s"
                        name="<?php echo esc_attr( self::QV_SEARCH ); ?>"
                        value="<?php echo esc_attr( $has_request_search ? $search : '' ); ?>"
                        placeholder="<?php esc_attr_e( 'Peça, companhia, teatro…', 'customizations-teatromusicadosp' ); ?>"
                    />
                    <button type="submit"><?php esc_html_e( 'Buscar', 'customizations-teatromusicadosp' ); ?></button>
                </div>
                <div class="teatro-apresentacoes__group-field">
                    <label for="teatro-apresentacoes-group" class="teatro-apresentacoes__search-label">
                        <?php esc_html_e( 'Agrupado por', 'customizations-teatromusicadosp' ); ?>
                    </label>
                    <select
                        id="teatro-apresentacoes-group"
                        name="<?php echo esc_attr( self::QV_GROUP ); ?>"
                        onchange="this.form.submit()"
                    >
                        <option value=""><?php esc_html_e( 'Nenhum', 'customizations-teatromusicadosp' ); ?></option>
                        <?php foreach ( self::GROUPS as $group_key => $group_cfg ) : ?>
                            <option value="<?php echo esc_attr( $group_key ); ?>" <?php selected( $group, $group_key ); ?>>
                                <?php echo esc_html( $group_cfg['label'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <div class="teatro-apresentacoes__table-wrap<?php echo $is_grouped ? ' teatro-apresentacoes__table-wrap--grouped' : ''; ?>" aria-live="polite">
                <?php if ( empty( $rows ) ) : ?>
                    <p class="teatro-apresentacoes__empty">
                        <?php esc_html_e( 'Nenhuma apresentação encontrada.', 'customizations-teatromusicadosp' ); ?>
                    </p>
                <?php elseif ( $is_grouped ) : ?>
                    <?php echo $this->group_table_html( $grouped, self::GROUPS[ $group ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php else : ?>
                    <table class="teatro-apresentacoes__table">
                        <caption class="screen-reader-text">
                            <?php esc_html_e( 'Lista de apresentações', 'customizations-teatromusicadosp' ); ?>
                        </caption>
                        <thead>
                            <tr>
                                <?php foreach ( $columns as $label ) : ?>
                                    <th scope="col"><?php echo esc_html( $label ); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $rows as $row ) : ?>
                                <tr>
                                    <?php foreach ( array_keys( $columns ) as $key ) : ?>
                                        <?php $value = $row[ $key ] ?? null; ?>
                                        <?php if ( 'presentationDate' === $key ) { $value = $this->format_date_br( $value ); } ?>
                                        <td data-label="<?php echo esc_attr( $columns[ $key ] ); ?>">
                                            <?php echo esc_html( ( null === $value || '' === $value ) ? '—' : (string) $value ); ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if ( $total_pages > 1 ) : ?>
                <nav class="teatro-apresentacoes__pagination" aria-label="<?php esc_attr_e( 'Paginação', 'customizations-teatromusicadosp' ); ?>">
                    <?php
                    echo wp_kses_post(
                        (string) paginate_links(
                            [
                                'base'      => add_query_arg( self::QV_PAGED, '%#%', $page_url ),
                                'format'    => '',
                                'current'   => $paged,
                                'total'     => $total_pages,
                                'add_args'  => $has_request_search ? [ self::QV_SEARCH => $search ] : [],
                                'prev_text' => __( '&laquo; Anterior', 'customizations-teatromusicadosp' ),
                                'next_text' => __( 'Próxima &raquo;', 'customizations-teatromusicadosp' ),
                            ]
                        )
                    );
                    ?>
                </nav>
            <?php endif; ?>

            <p class="teatro-apresentacoes__count" role="status">
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
     * Campos ocultos para preservar outras query vars ao submeter a busca
     * (mas descartando as nossas — a busca sempre volta para a página 1).
     */
    private function preserved_query_fields(): string {
        $skip   = [ self::QV_SEARCH, self::QV_PAGED, self::QV_GROUP, 'paged' ];
        $fields = '';

        foreach ( (array) $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
            $key = (string) $key;
            if ( in_array( $key, $skip, true ) || is_array( $value ) ) {
                continue;
            }
            $fields .= sprintf(
                '<input type="hidden" name="%s" value="%s" />',
                esc_attr( $key ),
                esc_attr( sanitize_text_field( wp_unslash( $value ) ) )
            );
        }

        return $fields;
    }

    /**
     * Varre a rota REST pública página a página e devolve todas as linhas que
     * satisfazem os filtros. Usado apenas quando há agrupamento ativo, onde a
     * tabela precisa do conjunto completo (os grupos podem cruzar páginas).
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetch_all_rows( string $search, string $theater, int $year, string $orderby, string $order ): array {
        $all      = [];
        $page     = 1;
        $per_page = 200;
        // Teto de segurança para nunca varrer indefinidamente (200 * 50 = 10k linhas).
        $max_pages = 50;

        do {
            $request = new \WP_REST_Request(
                'GET',
                '/' . PresentationsRepository::REST_NAMESPACE . PresentationsRepository::REST_ROUTE
            );
            $request->set_query_params(
                [
                    'page'     => $page,
                    'per_page' => $per_page,
                    'orderby'  => $orderby,
                    'order'    => $order,
                    'search'   => $search,
                    'theater'  => $theater,
                    'year'     => $year,
                ]
            );

            $response = rest_do_request( $request );
            if ( $response->is_error() ) {
                break;
            }

            $batch = (array) $response->get_data();
            if ( empty( $batch ) ) {
                break;
            }
            $all = array_merge( $all, $batch );

            $headers     = $response->get_headers();
            $total_pages = isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : 1;
            $page++;
        } while ( $page <= $total_pages && $page <= $max_pages );

        return $all;
    }

    /**
     * Monta a árvore de agrupamento de 4 níveis para o modo escolhido.
     *
     * Estrutura devolvida (por nível 1):
     *   [ '<l1 name>' => [
     *       'label'  => 'Companhia: X - Nacionalidade: Y',
     *       'total'  => (int) soma de sessionsNumber do grupo inteiro,
     *       'l2'     => [ '<l2 name>' => [
     *           'identity' => [ '<col>' => '<valor>' , ... ],
     *           'total'    => (int) soma de sessionsNumber do bloco l2,
     *           'leaves'   => [ '<teatro|tipo|ano>' => [
     *               'theater' =>, 'kind' =>, 'year' =>, 'sessions' => (int),
     *           ] ],
     *       ] ],
     *   ] ]
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string                         $mode "company" ou "play"
     * @return array<string,array<string,mixed>>
     */
    private function build_groups( array $rows, string $mode ): array {
        $cfg  = self::GROUPS[ $mode ];
        $tree = [];

        foreach ( $rows as $row ) {
            $l1_name  = $this->non_empty( $row[ $cfg['l1'] ] ?? '' );
            $l2_name  = $this->non_empty( $row[ $cfg['l2'] ] ?? '' );
            $theater  = $this->non_empty( $row['theaterName'] ?? '' );
            $kind     = $this->non_empty( $row['settingKind'] ?? '' );
            $year     = $this->year_of( $row['presentationDate'] ?? null );
            $sessions = (int) ( $row['sessionsNumber'] ?? 0 );

            if ( ! isset( $tree[ $l1_name ] ) ) {
                $parts = [];
                foreach ( $cfg['l1_label_fields'] as $label => $field ) {
                    $parts[] = $label . ': ' . $this->non_empty( $row[ $field ] ?? '' );
                }
                $tree[ $l1_name ] = [
                    'label' => implode( ' - ', $parts ),
                    'total' => 0,
                    'l2'    => [],
                ];
            }
            $tree[ $l1_name ]['total'] += $sessions;

            if ( ! isset( $tree[ $l1_name ]['l2'][ $l2_name ] ) ) {
                $identity = [];
                foreach ( array_keys( $cfg['identity'] ) as $field ) {
                    $identity[ $field ] = trim( (string) ( $row[ $field ] ?? '' ) );
                }
                $tree[ $l1_name ]['l2'][ $l2_name ] = [
                    'identity' => $identity,
                    'total'    => 0,
                    'leaves'   => [],
                ];
            }
            $tree[ $l1_name ]['l2'][ $l2_name ]['total'] += $sessions;

            $leaf_key = $theater . '|' . $kind . '|' . $year;
            if ( ! isset( $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ] ) ) {
                $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ] = [
                    'theater'  => $theater,
                    'kind'     => $kind,
                    'year'     => $year,
                    'sessions' => 0,
                ];
            }
            $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ]['sessions'] += $sessions;
        }

        uksort( $tree, static fn( $a, $b ): int => strnatcasecmp( (string) $a, (string) $b ) );
        foreach ( $tree as &$l1 ) {
            uksort( $l1['l2'], static fn( $a, $b ): int => strnatcasecmp( (string) $a, (string) $b ) );
            foreach ( $l1['l2'] as &$l2 ) {
                uasort(
                    $l2['leaves'],
                    static function ( array $a, array $b ): int {
                        return strnatcasecmp( $a['theater'], $b['theater'] )
                            ?: strnatcasecmp( $a['kind'], $b['kind'] )
                            ?: strnatcasecmp( (string) $a['year'], (string) $b['year'] );
                    }
                );
            }
            unset( $l2 );
        }
        unset( $l1 );

        return $tree;
    }

    /**
     * HTML da tabela agrupada: um único `<table>` com o cabeçalho de colunas
     * exibido uma vez e um `<tbody>` por grupo de 1º nível, cada qual iniciado
     * por uma faixa (`<tr>` com `<th colspan>`). As colunas de identidade do 2º
     * nível e a coluna "Total" são mescladas com `rowspan`.
     */
    private function group_table_html( array $tree, array $cfg ): string {
        $identity_keys   = array_keys( $cfg['identity'] );
        $identity_labels = array_values( $cfg['identity'] );
        $head_labels     = array_merge( $identity_labels, self::GROUP_LEAF_LABELS );
        $ncols           = count( $head_labels );

        ob_start();
        ?>
        <table class="teatro-apresentacoes__table teatro-apresentacoes__table--group">
            <caption class="screen-reader-text">
                <?php esc_html_e( 'Apresentações agrupadas', 'customizations-teatromusicadosp' ); ?>
            </caption>
            <thead>
                <tr>
                    <?php foreach ( $head_labels as $label ) : ?>
                        <th scope="col"><?php echo esc_html( $label ); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <?php foreach ( $tree as $l1 ) : ?>
                <tbody class="teatro-apresentacoes__group">
                    <tr class="teatro-apresentacoes__group-row">
                        <th scope="colgroup" colspan="<?php echo esc_attr( (string) $ncols ); ?>">
                            <?php
                            echo esc_html(
                                $l1['label']
                                . ' - ' . $cfg['count_label'] . ': ' . number_format_i18n( count( $l1['l2'] ) )
                                . ' - ' . sprintf(
                                    /* translators: %s: total de sessões do grupo. */
                                    __( 'Total de Sessões: %s', 'customizations-teatromusicadosp' ),
                                    number_format_i18n( $l1['total'] )
                                )
                            );
                            ?>
                        </th>
                    </tr>
                    <?php foreach ( $l1['l2'] as $l2 ) : ?>
                        <?php
                        $leaves    = array_values( $l2['leaves'] );
                        $leaf_span = count( $leaves );
                        $span_attr = $leaf_span > 1 ? ' rowspan="' . esc_attr( (string) $leaf_span ) . '"' : '';
                        ?>
                        <?php foreach ( $leaves as $i => $leaf ) : ?>
                            <tr>
                                <?php if ( 0 === $i ) : ?>
                                    <?php foreach ( $identity_keys as $ikey ) : ?>
                                        <?php $value = $l2['identity'][ $ikey ] ?? ''; ?>
                                        <td class="teatro-apresentacoes__group-cell"<?php echo $span_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> data-label="<?php echo esc_attr( $cfg['identity'][ $ikey ] ); ?>">
                                            <?php echo esc_html( '' !== $value ? $value : '—' ); ?>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <td data-label="Teatro"><?php echo esc_html( $leaf['theater'] ); ?></td>
                                <td data-label="Tipo de Espetáculo"><?php echo esc_html( $leaf['kind'] ); ?></td>
                                <td data-label="Ano"><?php echo esc_html( (string) $leaf['year'] ); ?></td>
                                <td data-label="Nº de Sessões"><?php echo esc_html( number_format_i18n( $leaf['sessions'] ) ); ?></td>
                                <?php if ( 0 === $i ) : ?>
                                    <td class="teatro-apresentacoes__group-cell"<?php echo $span_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> data-label="Total">
                                        <?php echo esc_html( number_format_i18n( $l2['total'] ) ); ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            <?php endforeach; ?>
        </table>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Valor de texto sem espaços; devolve "—" quando vazio.
     */
    private function non_empty( $value ): string {
        $value = trim( (string) $value );
        return '' !== $value ? $value : '—';
    }

    /**
     * Converte "YYYY-MM-DD[ HH:MM:SS]" em "DD/MM/YYYY"; devolve "—" se vazio.
     */
    private function format_date_br( $value ): string {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '—';
        }
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        return $value;
    }

    /**
     * Extrai o ano (primeiro bloco de 4 dígitos) de uma data; "—" se não houver.
     */
    private function year_of( $date ): string {
        if ( preg_match( '/(\d{4})/', (string) $date, $m ) ) {
            return $m[1];
        }
        return '—';
    }
}
