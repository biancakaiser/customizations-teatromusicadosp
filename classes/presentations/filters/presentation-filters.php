<?php
/**
 * Conjunto imutável de filtros de igualdade por coluna.
 *
 * Só guarda pares `coluna => valor` de colunas facetáveis (ver
 * `PresentationsSchema::facetable_columns()`) e apenas valores não vazios
 * (strings em branco e números <= 0 são descartados). Traduz o mesmo conjunto
 * de filtros para as três representações usadas pelo plugin:
 *
 *   - `to_query_args()`  → `['tap_f' => [...]]`   (URL da página, `paginate_links`)
 *   - `to_rest_params()` → `['filters' => [...]]` (`WP_REST_Request::set_query_params`)
 *   - `cache_fragment()` → assoc ordenado         (chave estável de transient)
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Filters;

use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationFilters
{
    /** Query var (array) que carrega os filtros na URL da página. */
    const QUERY_VAR = 'tap_f';

    /** Nome do parâmetro de filtros na rota REST. */
    const REST_PARAM = 'filters';

    /** @var array<string,string|int> */
    private $values;

    /**
     * @param array<string,string|int> $values Já normalizados.
     */
    private function __construct( array $values ) {
        $this->values = $values;
    }

    /**
     * Constrói a partir de um mapa cru `coluna => valor`, aplicando a whitelist
     * do schema e a normalização de cada coluna.
     *
     * @param array<string,mixed> $raw
     */
    public static function from_array( array $raw ): self {
        $values = [];

        foreach ( $raw as $key => $value ) {
            $key    = (string) $key;
            $column = PresentationsSchema::column( $key );

            if ( null === $column || ! $column->facetable ) {
                continue;
            }

            $value = $column->normalize_filter_value( $value );

            if ( $column->is_numeric() ) {
                if ( (int) $value > 0 ) {
                    $values[ $key ] = (int) $value;
                }
                continue;
            }

            if ( '' !== trim( (string) $value ) ) {
                $values[ $key ] = (string) $value;
            }
        }

        return new self( $values );
    }

    /**
     * Lê os filtros de `$_GET` (query var `tap_f`).
     *
     * @param array<string,mixed> $get
     */
    public static function from_query( array $get ): self {
        $raw = $get[ self::QUERY_VAR ] ?? [];
        return self::from_array( is_array( $raw ) ? wp_unslash( $raw ) : [] );
    }

    /**
     * Lê os filtros de uma requisição REST, mesclando os parâmetros legados
     * `theater`/`year` (que o parâmetro `filters` sobrescreve).
     */
    public static function from_rest_request( \WP_REST_Request $request ): self {
        $legacy = [
            'theaterName' => (string) $request['theater'],
            'settingYear' => (int) $request['year'],
        ];

        $filters = $request[ self::REST_PARAM ];
        $filters = is_array( $filters ) ? $filters : [];

        return self::from_array( array_merge( $legacy, $filters ) );
    }

    /**
     * @return array<string,string|int>
     */
    public function values(): array {
        return $this->values;
    }

    public function is_empty(): bool {
        return [] === $this->values;
    }

    /**
     * @return string|int|null
     */
    public function get( string $key ) {
        return $this->values[ $key ] ?? null;
    }

    /**
     * @param string|int $value
     */
    public function with( string $key, $value ): self {
        return self::from_array( array_merge( $this->values, [ $key => $value ] ) );
    }

    public function without( string $key ): self {
        $values = $this->values;
        unset( $values[ $key ] );
        return new self( $values );
    }

    /**
     * @return array<string,array<string,string|int>>
     */
    public function to_query_args(): array {
        return $this->is_empty() ? [] : [ self::QUERY_VAR => $this->values ];
    }

    /**
     * @return array<string,array<string,string|int>>
     */
    public function to_rest_params(): array {
        return [ self::REST_PARAM => $this->values ];
    }

    /**
     * Fragmento estável (ordenado) para compor a chave do transient de consulta.
     *
     * @return array<string,string|int>
     */
    public function cache_fragment(): array {
        $values = $this->values;
        ksort( $values );
        return $values;
    }
}
