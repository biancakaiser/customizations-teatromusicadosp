<?php
/**
 * "Tabela nova" no WordPress para as apresentações (mock Flat_Table.csv) e a
 * respectiva rota REST usada pela página administrativa.
 *
 * A ideia é tratar o CSV como se fosse uma tabela recém-criada no banco do
 * WordPress: ele é importado uma única vez para `{$wpdb->prefix}teatro_presentations`
 * e passa a ser consultado exclusivamente via REST
 * (`/wp-json/teatromusicadosp/v1/presentations`).
 */

namespace TeatroMusicadoSP\Customizations\Presentations;

use TeatroMusicadoSP\Customizations\Contracts\Module;
use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;
use TeatroMusicadoSP\Customizations\Presentations\Filters\PresentationFilters;
use TeatroMusicadoSP\Customizations\Presentations\Filters\PresentationFilterClause;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingMode;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingModes;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupedPager;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\PresentationGrouper;
use TeatroMusicadoSP\Customizations\Presentations\Rendering\ResultsContentRenderer;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class PresentationsRepository implements Module
{
    use Singleton;

    const REST_NAMESPACE = 'teatromusicadosp/v1';
    const REST_ROUTE      = '/presentations';

    /** Nome base da tabela (sem prefixo do wpdb). */
    const TABLE_SUFFIX = 'teatro_presentations';

    /** Opção que guarda a versão do schema/seed já aplicada. */
    const SCHEMA_OPTION  = 'teatromusicadosp_presentations_schema';
    // 1.1.0: índices em playName / companyName (filtro por igualdade + autocomplete).
    // 1.2.0: renomeia settingYear/settingLanguage/settingKind/sessionsNumber/
    //        theaterName/genre (ver PresentationsSchema::build()).
    // 1.3.0: remove playLanguage (idioma só existe em Espetáculo) e
    //        presentationYear (duplicava o ano já contido em presentationDate;
    //        "Ano" continua existindo só como derivado no agrupamento — ver
    //        PresentationGrouper::LEAF_FIELDS). O filtro por ano vira filtro por
    //        intervalo de datas (PresentationFilters::DATE_FROM/DATE_TO).
    const SCHEMA_VERSION = '1.3.0';

    /**
     * As colunas da "tabela nova" agora vivem em
     * {@see \TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema}
     * (fonte única, compartilhada com o shortcode). Use `PresentationsSchema::labels()`
     * para o antigo mapa `coluna => rótulo`.
     */

    /** Prefixo da chave de transient que guarda os valores distintos de uma coluna. */
    const FACET_CACHE_PREFIX = 'tmsp_pres_facet_';

    /** Lock (transient) para evitar seed concorrente (ex.: requisições paralelas / wp-cron). */
    const INSTALL_LOCK = 'teatromusicadosp_presentations_installing';

    public function register(): void {
        // admin_init (e não init) para não rodar em cada requisição de front-end
        // ou em chamadas de wp-cron, que podem colidir durante o seed.
        add_action( 'admin_init', [ $this, 'maybe_install_table' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Ponto de entrada para o hook de ativação do plugin.
     */
    public static function install(): void {
        self::get_instance()->maybe_install_table();
    }

    /**
     * A tabela de apresentações depende do Tainacan: hoje ela é populada por um
     * mock (Flat_Table.csv), mas passará a ser alimentada por dados do Tainacan.
     * Por isso o schema/seed só é criado quando as APIs do Tainacan existem —
     * mesma checagem feita em \TeatroMusicadoSP\Customizations\Plugin.
     */
    public static function is_tainacan_available(): bool {
        return function_exists( 'tainacan_metadata' )
            && function_exists( 'tainacan_items' )
            && function_exists( 'tainacan_collections' )
            && function_exists( 'tainacan_taxonomies' );
    }

    /**
     * Nome completo da tabela no banco.
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Cria a tabela e importa o CSV uma única vez (ou quando a versão muda).
     */
    public function maybe_install_table(): void {
        static $done_in_request = false;
        if ( $done_in_request ) {
            return;
        }

        // Sem Tainacan não criamos a tabela: quando ele for instalado/ativado,
        // o hook 'admin_init' (registrado só nesse cenário) roda o seed.
        if ( ! self::is_tainacan_available() ) {
            return;
        }

        if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
            $done_in_request = true;
            return;
        }

        // Trava simples contra execução concorrente do seed.
        if ( get_transient( self::INSTALL_LOCK ) ) {
            return;
        }
        set_transient( self::INSTALL_LOCK, 1, 5 * MINUTE_IN_SECONDS );

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // dbDelta só adiciona colunas/índices novos — nunca renomeia nem remove os
        // antigos. Como esta tabela é inteiramente reproduzível a partir do CSV
        // (ver seed_from_csv()), o caminho mais simples e seguro numa mudança de
        // schema (coluna renomeada/removida) é recriar a tabela do zero.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );

        $column_defs = PresentationsSchema::sql_column_definitions();

        $key_defs = array_map(
            static fn( string $key ): string => "  KEY {$key} ({$key})",
            PresentationsSchema::indexed_keys()
        );

        $sql = "CREATE TABLE {$table} (\n"
            . "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  "
            . implode( ",\n  ", $column_defs ) . ",\n"
            . "  PRIMARY KEY  (id),\n"
            . implode( ",\n", $key_defs ) . "\n"
            . ") {$charset_collate};";

        dbDelta( $sql );

        $this->seed_from_csv( $table );

        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
        delete_transient( self::INSTALL_LOCK );
        $done_in_request = true;
    }

    /**
     * Lê data/flat-table.csv e insere as linhas na tabela (limpando antes).
     */
    private function seed_from_csv( string $table ): void {
        global $wpdb;

        $csv_path = TMSP_CUSTOMIZATIONS_PATH . 'data/flat-table.csv';
        if ( ! is_readable( $csv_path ) ) {
            return;
        }

        $handle = fopen( $csv_path, 'r' );
        if ( false === $handle ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        $header = fgetcsv( $handle, 0, ',', '"', '' );
        if ( ! is_array( $header ) ) {
            fclose( $handle );
            return;
        }
        $header = array_map( [ $this, 'fix_mojibake' ], $header );

        $columns = PresentationsSchema::keys();

        while ( ( $raw = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
            if ( [ null ] === $raw || [] === $raw ) {
                continue;
            }

            $assoc = [];
            foreach ( $header as $index => $name ) {
                $assoc[ $name ] = $raw[ $index ] ?? null;
            }

            $data    = [];
            $formats = [];
            foreach ( $columns as $column ) {
                $value = $assoc[ $column ] ?? null;
                $value = ( null === $value || 'NULL' === $value || '' === $value ) ? null : $this->fix_mojibake( $value );

                if ( PresentationsSchema::column( $column )->is_numeric() ) {
                    $data[ $column ]    = ( null === $value ) ? null : (int) $value;
                    $formats[]          = '%d';
                    continue;
                }

                if ( 'presentationDate' === $column && null !== $value ) {
                    $value = str_replace( '/', '-', $value );
                }

                $data[ $column ] = $value;
                $formats[]       = '%s';
            }

            $wpdb->insert( $table, $data, $formats );
        }

        fclose( $handle );

        // O seed mudou o conjunto de valores: invalida os caches de DISTINCT.
        foreach ( array_keys( PresentationsSchema::facetable_columns() ) as $facet_column ) {
            delete_transient( self::facet_cache_key( $facet_column ) );
        }
    }

    /**
     * O CSV do mock veio com dupla codificação (ex.: "PortuguÃªs"). Reverte para
     * UTF-8 quando detecta o padrão, mantendo o texto original caso contrário.
     */
    private function fix_mojibake( $value ): string {
        $value = (string) $value;

        if ( '' === $value || ! preg_match( '/[ÃÂ][\x80-\xBF ]/u', $value ) ) {
            return trim( $value );
        }

        $decoded = mb_convert_encoding( $value, 'ISO-8859-1', 'UTF-8' );
        if ( mb_check_encoding( $decoded, 'UTF-8' ) ) {
            return trim( $decoded );
        }

        return trim( $value );
    }

    /**
     * Registra a rota REST que devolve as apresentações da tabela nova.
     *
     * Leitura pública: os dados são um catálogo histórico, sem informação
     * sensível, e a mesma rota alimenta a página administrativa e o shortcode
     * `[teatro_apresentacoes]` no front-end.
     */
    public function register_rest_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods'             => \WP_REST_Server::READABLE,
                'permission_callback' => '__return_true',
                'callback'            => [ $this, 'rest_get_presentations' ],
                'args'                => array_merge(
                    self::pagination_rest_args(),
                    self::shared_query_args()
                ),
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE . '/grouped',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'permission_callback' => '__return_true',
                'callback'            => [ $this, 'rest_get_presentations_grouped' ],
                'args'                => array_merge(
                    [
                        'group' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_key',
                            'validate_callback' => static function ( $value ) {
                                return GroupingModes::has( (string) $value );
                            },
                        ],
                    ],
                    self::pagination_rest_args(),
                    self::shared_query_args()
                ),
            ]
        );
    }

    /**
     * Argumentos `page`/`per_page` compartilhados pelas duas rotas (a plana e
     * a agrupada) — `per_page` é o seletor "Resultados por página" visível
     * nos dois modos; `0` significa "Sem Paginação".
     *
     * @return array<string,array<string,mixed>>
     */
    private static function pagination_rest_args(): array {
        return [
            'page' => [
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'default'           => PresentationsRequest::DEFAULT_RESULTS_PER_PAGE,
                'sanitize_callback' => 'absint',
                'validate_callback' => static function ( $value ) {
                    return in_array( (int) $value, PresentationsRequest::RESULTS_PER_PAGE_OPTIONS, true );
                },
            ],
        ];
    }

    /**
     * Argumentos de consulta comuns às duas rotas (a paginada e a agrupada):
     * ordenação, busca e filtros por coluna.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function shared_query_args(): array {
        return [
            'orderby' => [
                'default'           => 'presentationDate',
                'validate_callback' => static function ( $value ) {
                    // `__total` só faz sentido no modo agrupado; a rota plana o
                    // ignora (revalida em `query_presentations()`).
                    return PresentationsSchema::is_orderable( (string) $value )
                        || GroupingMode::SORT_TOTAL === (string) $value;
                },
            ],
            'order' => [
                'default'           => 'ASC',
                'validate_callback' => static function ( $value ) {
                    return in_array( strtoupper( (string) $value ), [ 'ASC', 'DESC' ], true );
                },
            ],
            'search' => [
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'theater' => [
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            // Intervalo de datas ('YYYY-MM-DD') sobre PresentationFilters::DATE_COLUMN
            // (presentationDate) — a validação "de verdade" (formato + calendário)
            // fica em PresentationFilters::from_array(); aqui só sanitiza texto.
            'date_from' => [
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_to' => [
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'filters' => [
                'default'           => [],
                // Cada coluna aceita um valor único ou uma lista (várias caixas
                // marcadas, combinadas em OR) — a whitelist e a normalização
                // "de verdade" ficam em `PresentationFilters::from_array()`,
                // chamado logo em seguida por `from_rest_request()`. Aqui só
                // garantimos a forma (array) do parâmetro.
                'sanitize_callback' => static function ( $value ) {
                    return is_array( $value ) ? $value : [];
                },
            ],
        ];
    }

    /**
     * Callback da rota agrupada: monta a árvore (`PresentationGrouper`) e devolve
     * o HTML da tabela já pronto (`ResultsContentRenderer::grouped()`), evitando
     * que o cliente precise reimplementar o algoritmo de agrupamento ou a
     * decisão de "sem resultados".
     */
    public function rest_get_presentations_grouped( \WP_REST_Request $request ): \WP_REST_Response {
        $mode = GroupingModes::get( (string) $request['group'] );
        if ( null === $mode ) {
            return new \WP_REST_Response(
                [ 'message' => __( 'Modo de agrupamento inválido.', 'customizations-teatromusicadosp' ) ],
                400
            );
        }

        $orderby = (string) $request['orderby'];
        $order   = strtoupper( (string) $request['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $rows = $this->query_all_presentations(
            [
                'orderby' => $orderby,
                'order'   => $order,
                'search'  => trim( (string) $request['search'] ),
                'filters' => PresentationFilters::from_rest_request( $request ),
            ]
        );

        $tree  = ( new PresentationGrouper() )->build( $rows, $mode, [ 'orderby' => $orderby, 'order' => $order ] );
        $paged = ( new GroupedPager() )->paginate( $tree, (int) $request['per_page'], max( 1, (int) $request['page'] ) );
        $html  = ResultsContentRenderer::grouped( $rows, $paged['tree'], $mode );

        $response = new \WP_REST_Response(
            [
                'html'        => $html,
                'total'       => count( $rows ),
                'total_pages' => $paged['total_pages'],
            ],
            200
        );

        if ( ! is_user_logged_in() ) {
            $response->header( 'Cache-Control', 'public, max-age=300, s-maxage=300' );
        }

        return $response;
    }

    /**
     * Callback da rota: consulta o wpdb e devolve a tabela plana já pronta
     * (`ResultsContentRenderer::flat()`) — o cliente só troca `innerHTML`.
     */
    public function rest_get_presentations( \WP_REST_Request $request ): \WP_REST_Response {
        $per_page = (int) $request['per_page'];
        $orderby  = (string) $request['orderby'];
        $order    = strtoupper( (string) $request['order'] ) === 'DESC' ? 'DESC' : 'ASC';
        $search   = trim( (string) $request['search'] );
        $filters  = PresentationFilters::from_rest_request( $request );

        // 0 = "Sem Paginação": mesma consulta sem `LIMIT`/`OFFSET` do modo
        // agrupado (mesmo teto de segurança de 10 mil linhas).
        if ( 0 === $per_page ) {
            $rows        = $this->query_all_presentations(
                [
                    'orderby' => $orderby,
                    'order'   => $order,
                    'search'  => $search,
                    'filters' => $filters,
                ]
            );
            $total       = count( $rows );
            $total_pages = 1;
        } else {
            $result = $this->query_presentations(
                [
                    'page'     => max( 1, (int) $request['page'] ),
                    'per_page' => $per_page,
                    'orderby'  => $orderby,
                    'order'    => $order,
                    'search'   => $search,
                    'filters'  => $filters,
                ]
            );
            $rows        = $result['data'];
            $total       = $result['total'];
            $total_pages = (int) ceil( $total / $per_page );
        }

        $response = new \WP_REST_Response(
            [
                'html'        => ResultsContentRenderer::flat( $rows ),
                'total'       => $total,
                'total_pages' => $total_pages,
            ],
            200
        );

        // Catálogo estático: pode ser cacheado por proxies/CDN para visitantes
        // anônimos. Usuários logados recebem a resposta sem cache.
        if ( ! is_user_logged_in() ) {
            $response->header( 'Cache-Control', 'public, max-age=300, s-maxage=300' );
        }

        return $response;
    }

    /**
     * Consulta paginada da tabela, com cache curto em transient (o seed é
     * estático; a chave inclui a versão do schema, então um re-seed invalida).
     *
     * @param array $args page, per_page, orderby, order, search, filters (um `PresentationFilters` já pronto)
     * @return array{data:array<int,array<string,mixed>>,total:int}
     */
    public function query_presentations( array $args ): array {
        global $wpdb;

        $args = wp_parse_args(
            $args,
            [
                'page'     => 1,
                'per_page' => 50,
                'orderby'  => 'presentationDate',
                'order'    => 'ASC',
                'search'   => '',
                'filters'  => null,
            ]
        );

        $orderby  = PresentationsSchema::is_orderable( (string) $args['orderby'] ) ? (string) $args['orderby'] : 'presentationDate';
        $order    = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
        // O maior valor do seletor "Resultados por página" (ver
        // PresentationsRequest::RESULTS_PER_PAGE_OPTIONS); "Sem Paginação" (0)
        // é tratado antes de chegar aqui pelos chamadores.
        $per_page = min( 1000, max( 1, (int) $args['per_page'] ) );
        $page     = max( 1, (int) $args['page'] );
        $offset   = ( $page - 1 ) * $per_page;
        $search   = trim( (string) $args['search'] );

        $filters = $args['filters'] instanceof PresentationFilters
            ? $args['filters']
            : PresentationFilters::from_array( [] );

        $cache_key = 'tmsp_pres_' . md5(
            self::SCHEMA_VERSION . '|' . wp_json_encode(
                [
                    'orderby'  => $orderby,
                    'order'    => $order,
                    'per_page' => $per_page,
                    'page'     => $page,
                    'search'   => $search,
                    'filters'  => $filters->cache_fragment(),
                ]
            )
        );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $table  = self::table_name();
        $clause = ( new PresentationFilterClause( $wpdb ) )->build( $filters, $search );
        $params = $clause['params'];
        $where  = '' !== $clause['sql'] ? ( 'WHERE ' . $clause['sql'] ) : '';

        $total_sql = "SELECT COUNT(*) FROM {$table} {$where}";
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

        $rows_sql = "SELECT * FROM {$table} {$where} ORDER BY `{$orderby}` {$order}, id ASC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, [ $per_page, $offset ] ) ), ARRAY_A );

        $result = [
            'data'  => array_map( [ self::class, 'map_row' ], $rows ?: [] ),
            'total' => $total,
        ];

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Todas as linhas que satisfazem os filtros, sem paginação — usado pelo
     * modo agrupado (onde os grupos podem cruzar páginas e a tabela precisa do
     * conjunto completo antes de `GroupedPager` cortar em páginas) e pela
     * opção "Sem Paginação" do seletor "Resultados por página" no modo plano.
     * Teto de segurança: nunca devolve mais que 10 mil linhas (mesmo limite
     * que a antiga varredura página a página do shortcode aplicava: 200 × 50).
     *
     * @param array $args orderby, order, search, filters (um `PresentationFilters` já pronto)
     * @return array<int,array<string,mixed>>
     */
    public function query_all_presentations( array $args ): array {
        global $wpdb;

        $args = wp_parse_args(
            $args,
            [
                'orderby' => 'presentationDate',
                'order'   => 'ASC',
                'search'  => '',
                'filters' => null,
            ]
        );

        $orderby  = PresentationsSchema::is_orderable( (string) $args['orderby'] ) ? (string) $args['orderby'] : 'presentationDate';
        $order    = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
        $search   = trim( (string) $args['search'] );
        $filters  = $args['filters'] instanceof PresentationFilters
            ? $args['filters']
            : PresentationFilters::from_array( [] );
        $max_rows = 10000;

        $cache_key = 'tmsp_pres_all_' . md5(
            self::SCHEMA_VERSION . '|' . wp_json_encode(
                [
                    'orderby' => $orderby,
                    'order'   => $order,
                    'search'  => $search,
                    'filters' => $filters->cache_fragment(),
                ]
            )
        );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $table  = self::table_name();
        $clause = ( new PresentationFilterClause( $wpdb ) )->build( $filters, $search );
        $params = $clause['params'];
        $where  = '' !== $clause['sql'] ? ( 'WHERE ' . $clause['sql'] ) : '';

        $rows_sql = "SELECT * FROM {$table} {$where} ORDER BY `{$orderby}` {$order}, id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, [ $max_rows ] ) ), ARRAY_A );

        $data = array_map( [ self::class, 'map_row' ], $rows ?: [] );

        set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );

        return $data;
    }

    /**
     * Normaliza os tipos de uma linha crua do `wpdb` (colunas numéricas vêm
     * como string do banco).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function map_row( array $row ): array {
        $row['id']                    = (int) $row['id'];
        $row['presentationSessionsN'] = ( null === $row['presentationSessionsN'] ) ? null : (int) $row['presentationSessionsN'];
        return $row;
    }

    /**
     * Chave do transient que guarda os valores distintos de uma coluna.
     */
    public static function facet_cache_key( string $column ): string {
        return self::FACET_CACHE_PREFIX . md5( self::SCHEMA_VERSION . '|' . $column );
    }

    /**
     * Valores distintos, não vazios e ordenados de uma coluna facetável — usados
     * para popular os `<select>` de filtro. Resultado cacheado por 1h (o seed é
     * estático; `seed_from_csv()` limpa esses transients ao re-importar).
     *
     * @return list<string|int>
     */
    public function facet_values( string $column ): array {
        if ( ! PresentationsSchema::is_facetable( $column ) ) {
            return [];
        }

        $cache_key = self::facet_cache_key( $column );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;

        $table   = self::table_name();
        $numeric = PresentationsSchema::column( $column )->is_numeric();
        $order   = $numeric ? "`{$column}`+0 ASC" : "`{$column}` ASC";

        // $column vem da whitelist do schema; não há valor de usuário na query.
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $raw = $wpdb->get_col(
            "SELECT DISTINCT `{$column}` FROM {$table} WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY {$order}"
        );

        $values = array_map(
            static fn( $value ) => $numeric ? (int) $value : (string) $value,
            $raw ?: []
        );

        set_transient( $cache_key, $values, HOUR_IN_SECONDS );

        return $values;
    }

    /**
     * Mapa `coluna => list<valor>` para todas as colunas facetáveis.
     *
     * @return array<string,list<string|int>>
     */
    public function all_facets(): array {
        $facets = [];
        foreach ( array_keys( PresentationsSchema::facetable_columns() ) as $column ) {
            $facets[ $column ] = $this->facet_values( $column );
        }
        return $facets;
    }
}
