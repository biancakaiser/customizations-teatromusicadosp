<?php
/**
 * Conjunto imutável de filtros por coluna, com **múltiplos valores por coluna**
 * combinados em OR (equivale a `col IN (...)` no SQL — ver `PresentationFilterClause`).
 * Colunas diferentes continuam combinadas em AND.
 *
 * Só guarda `coluna => list<valor>` de colunas facetáveis (ver
 * `PresentationsSchema::facetable_columns()`) e apenas valores não vazios
 * (strings em branco e números <= 0 são descartados; duplicatas removidas).
 * Traduz o mesmo conjunto de filtros para as três representações usadas pelo plugin:
 *
 *   - `to_query_args()`  → `['tap_f' => ['col' => [v, ...]]]`  (URL da página)
 *   - `to_rest_params()` → `['filters' => ['col' => [v, ...]]]` (REST)
 *   - `cache_fragment()` → assoc ordenado, cada lista também ordenada — chave
 *     estável de transient independente da ordem em que as caixas foram marcadas.
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

    /** @var array<string,list<string|int>> */
    private $values;

    /**
     * @param array<string,list<string|int>> $values Já normalizados.
     */
    private function __construct( array $values ) {
        $this->values = $values;
    }

    /**
     * Constrói a partir de um mapa cru `coluna => valor(es)`, aplicando a
     * whitelist do schema e a normalização de cada coluna. Aceita tanto um
     * valor único por coluna (filtros legados `theater`/`year`, um checkbox só)
     * quanto uma lista (várias caixas marcadas) — ambos viram lista internamente.
     *
     * @param array<string,mixed> $raw
     */
    public static function from_array( array $raw ): self {
        $values = [];

        foreach ( $raw as $key => $raw_value ) {
            $key    = (string) $key;
            $column = PresentationsSchema::column( $key );

            if ( null === $column || ! $column->facetable ) {
                continue;
            }

            $list       = is_array( $raw_value ) ? $raw_value : [ $raw_value ];
            $normalized = [];

            foreach ( $list as $item ) {
                $item = $column->normalize_filter_value( $item );

                if ( $column->is_numeric() ) {
                    if ( (int) $item > 0 ) {
                        $normalized[] = (int) $item;
                    }
                    continue;
                }

                $item = trim( (string) $item );
                if ( '' !== $item ) {
                    $normalized[] = $item;
                }
            }

            $normalized = array_values( array_unique( $normalized, SORT_REGULAR ) );
            sort( $normalized );

            if ( $normalized ) {
                $values[ $key ] = $normalized;
            }
        }

        return new self( $values );
    }

    /**
     * Lê os filtros de `$_GET` (query var `tap_f`). Cada coluna vem como lista
     * (`tap_f[col][]=a&tap_f[col][]=b`, um `<input type="checkbox">` por valor).
     *
     * @param array<string,mixed> $get
     */
    public static function from_query( array $get ): self {
        $raw = $get[ self::QUERY_VAR ] ?? [];
        return self::from_array( is_array( $raw ) ? wp_unslash( $raw ) : [] );
    }

    /**
     * Lê os filtros de uma requisição REST, mesclando os parâmetros legados
     * `theater`/`year` (que o parâmetro `filters` sobrescreve por coluna).
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
     * @return array<string,list<string|int>>
     */
    public function values(): array {
        return $this->values;
    }

    public function is_empty(): bool {
        return [] === $this->values;
    }

    /**
     * Valores selecionados de uma coluna (lista vazia se não filtrada).
     *
     * @return list<string|int>
     */
    public function get( string $key ): array {
        return $this->values[ $key ] ?? [];
    }

    /**
     * @return array<string,array<string,list<string|int>>>
     */
    public function to_query_args(): array {
        return $this->is_empty() ? [] : [ self::QUERY_VAR => $this->values ];
    }

    /**
     * @return array<string,array<string,list<string|int>>>
     */
    public function to_rest_params(): array {
        return [ self::REST_PARAM => $this->values ];
    }

    /**
     * Fragmento estável (colunas e, dentro delas, valores, ambos ordenados)
     * para compor a chave do transient de consulta.
     *
     * @return array<string,list<string|int>>
     */
    public function cache_fragment(): array {
        $values = $this->values;
        ksort( $values );
        return $values;
    }
}
