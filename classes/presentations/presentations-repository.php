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
    const SCHEMA_VERSION = '1.0.0';

    /**
     * Colunas da "tabela nova". A ordem aqui é a ordem das colunas exibidas.
     *
     * @var array<string,string> nome da coluna => rótulo
     */
    const COLUMNS = [
        'presentationDate'    => 'Data da apresentação',
        'sessionsNumber'      => 'Nº de sessões',
        'settingYear'         => 'Ano da temporada',
        'settingLanguage'     => 'Idioma da temporada',
        'settingKind'         => 'Tipo de temporada',
        'playName'            => 'Peça',
        'genre'               => 'Gênero',
        'playLanguage'        => 'Idioma da peça',
        'playNationality'     => 'Nacionalidade da peça',
        'companyName'         => 'Companhia',
        'companyNationality'  => 'Nacionalidade da companhia',
        'theaterName'         => 'Teatro',
    ];

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

        $column_defs = [];
        foreach ( array_keys( self::COLUMNS ) as $column ) {
            if ( 'sessionsNumber' === $column || 'settingYear' === $column ) {
                $column_defs[] = "`{$column}` INT NULL";
                continue;
            }
            if ( 'presentationDate' === $column ) {
                $column_defs[] = "`{$column}` DATETIME NULL";
                continue;
            }
            $column_defs[] = "`{$column}` VARCHAR(255) NULL";
        }

        $sql = "CREATE TABLE {$table} (\n"
            . "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  "
            . implode( ",\n  ", $column_defs ) . ",\n"
            . "  PRIMARY KEY  (id),\n"
            . "  KEY settingYear (settingYear),\n"
            . "  KEY theaterName (theaterName)\n"
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

        $columns = array_keys( self::COLUMNS );

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

                if ( 'sessionsNumber' === $column || 'settingYear' === $column ) {
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
                'args'                => [
                    'page' => [
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'default'           => 50,
                        'sanitize_callback' => 'absint',
                    ],
                    'orderby' => [
                        'default'           => 'presentationDate',
                        'validate_callback' => static function ( $value ) {
                            return array_key_exists( $value, self::COLUMNS ) || 'id' === $value;
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
                    'year' => [
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * Callback da rota: consulta o wpdb e devolve as linhas paginadas.
     */
    public function rest_get_presentations( \WP_REST_Request $request ): \WP_REST_Response {
        $per_page = min( 200, max( 1, (int) $request['per_page'] ) );

        $args = [
            'page'     => max( 1, (int) $request['page'] ),
            'per_page' => $per_page,
            'orderby'  => (string) $request['orderby'],
            'order'    => strtoupper( (string) $request['order'] ) === 'DESC' ? 'DESC' : 'ASC',
            'search'   => trim( (string) $request['search'] ),
            'theater'  => trim( (string) $request['theater'] ),
            'year'     => (int) $request['year'],
        ];

        $result = $this->query_presentations( $args );

        $response = new \WP_REST_Response( $result['data'], 200 );
        $response->header( 'X-WP-Total', (string) $result['total'] );
        $response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $result['total'] / $per_page ) : 1 ) );
        $response->header( 'X-TMSP-Columns', wp_json_encode( self::COLUMNS ) );

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
     * @param array $args page, per_page, orderby, order, search, theater, year
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
                'theater'  => '',
                'year'     => 0,
            ]
        );

        $orderby  = ( array_key_exists( $args['orderby'], self::COLUMNS ) || 'id' === $args['orderby'] ) ? $args['orderby'] : 'presentationDate';
        $order    = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
        $per_page = min( 200, max( 1, (int) $args['per_page'] ) );
        $page     = max( 1, (int) $args['page'] );
        $offset   = ( $page - 1 ) * $per_page;
        $search   = trim( (string) $args['search'] );
        $theater  = trim( (string) $args['theater'] );
        $year     = (int) $args['year'];

        $cache_key = 'tmsp_pres_' . md5(
            self::SCHEMA_VERSION . '|' . wp_json_encode(
                compact( 'orderby', 'order', 'per_page', 'page', 'search', 'theater', 'year' )
            )
        );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $table   = self::table_name();
        $clauses = [];
        $params  = [];

        if ( '' !== $search ) {
            $like       = '%' . $wpdb->esc_like( $search ) . '%';
            $sub        = [];
            foreach ( array_keys( self::COLUMNS ) as $column ) {
                $sub[]    = "`{$column}` LIKE %s";
                $params[] = $like;
            }
            $clauses[] = '(' . implode( ' OR ', $sub ) . ')';
        }

        if ( '' !== $theater ) {
            $clauses[] = '`theaterName` = %s';
            $params[]  = $theater;
        }

        if ( $year > 0 ) {
            $clauses[] = '`settingYear` = %d';
            $params[]  = $year;
        }

        $where = $clauses ? ( 'WHERE ' . implode( ' AND ', $clauses ) ) : '';

        $total_sql = "SELECT COUNT(*) FROM {$table} {$where}";
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

        $rows_sql = "SELECT * FROM {$table} {$where} ORDER BY `{$orderby}` {$order}, id ASC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, [ $per_page, $offset ] ) ), ARRAY_A );

        $data = array_map(
            static function ( array $row ): array {
                $row['id']             = (int) $row['id'];
                $row['sessionsNumber'] = ( null === $row['sessionsNumber'] ) ? null : (int) $row['sessionsNumber'];
                $row['settingYear']    = ( null === $row['settingYear'] ) ? null : (int) $row['settingYear'];
                return $row;
            },
            $rows ?: []
        );

        $result = [
            'data'  => $data,
            'total' => $total,
        ];

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }
}
