<?php
/**
 * Conjunto imutável de filtros de apresentações: filtros **por coluna**, com
 * múltiplos valores por coluna combinados em OR (equivale a `col IN (...)` no
 * SQL — ver `PresentationFilterClause`; colunas diferentes continuam em AND),
 * mais um **intervalo de datas** sobre `DATE_COLUMN` (`presentationDate`).
 *
 * Só guarda `coluna => list<valor>` de colunas facetáveis (ver
 * `PresentationsSchema::facetable_columns()`) e apenas valores não vazios
 * (strings em branco e números <= 0 são descartados; duplicatas removidas). O
 * intervalo de datas guarda `date_from`/`date_to` já validados (`Y-m-d` ou
 * `null` quando ausente/inválido) — não passa pela whitelist de colunas
 * facetáveis porque não é um filtro de igualdade, é um `>=`/`<=`.
 *
 * Traduz o mesmo conjunto de filtros para as três representações usadas pelo plugin:
 *
 *   - `to_query_args()`  → `['tap_f' => ['col' => [v, ...]], 'tap_from' => ..., 'tap_to' => ...]` (URL da página)
 *   - `to_rest_params()` → `['filters' => ['col' => [v, ...]], 'date_from' => ..., 'date_to' => ...]` (REST)
 *   - `cache_fragment()` → assoc ordenado, cada lista também ordenada — chave
 *     estável de transient independente da ordem em que as caixas foram marcadas.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Filters;

use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationFilters
{
    /** Query var (array) que carrega os filtros por igualdade na URL da página. */
    const QUERY_VAR = 'tap_f';

    /** Query vars que carregam o intervalo de datas na URL da página. */
    const QUERY_VAR_DATE_FROM = 'tap_from';
    const QUERY_VAR_DATE_TO   = 'tap_to';

    /** Nome do parâmetro de filtros por igualdade na rota REST. */
    const REST_PARAM = 'filters';

    /**
     * Chaves reservadas em `from_array()`/`from_rest_request()` e nomes dos
     * parâmetros REST do intervalo de datas (não passam pela whitelist de
     * colunas facetáveis — não existe coluna com essas chaves).
     */
    const DATE_FROM = 'date_from';
    const DATE_TO   = 'date_to';

    /** Coluna sobre a qual o intervalo de datas filtra. */
    const DATE_COLUMN = 'presentationDate';

    /** @var array<string,list<string|int>> */
    private $values;

    /** @var string|null 'YYYY-MM-DD', ou null (sem limite inferior/ausente/inválido). */
    private $date_from;

    /** @var string|null 'YYYY-MM-DD', ou null (sem limite superior/ausente/inválido). */
    private $date_to;

    /**
     * @param array<string,list<string|int>> $values Já normalizados.
     */
    private function __construct( array $values, ?string $date_from, ?string $date_to ) {
        $this->values    = $values;
        $this->date_from = $date_from;
        $this->date_to   = $date_to;
    }

    /**
     * Constrói a partir de um mapa cru `coluna => valor(es)`, aplicando a
     * whitelist do schema e a normalização de cada coluna. Aceita tanto um
     * valor único por coluna (filtro legado `theater`, um checkbox só) quanto
     * uma lista (várias caixas marcadas) — ambos viram lista internamente.
     * `DATE_FROM`/`DATE_TO` são tratados à parte (não são coluna).
     *
     * @param array<string,mixed> $raw
     */
    public static function from_array( array $raw ): self {
        $values = [];

        foreach ( $raw as $key => $raw_value ) {
            $key = (string) $key;

            if ( self::DATE_FROM === $key || self::DATE_TO === $key ) {
                continue;
            }

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

        return new self(
            $values,
            self::normalize_date( $raw[ self::DATE_FROM ] ?? null ),
            self::normalize_date( $raw[ self::DATE_TO ] ?? null )
        );
    }

    /**
     * Lê os filtros de `$_GET`: `tap_f` (cada coluna como lista —
     * `tap_f[col][]=a&tap_f[col][]=b`, um `<input type="checkbox">` por valor)
     * mais `tap_from`/`tap_to` (intervalo de datas, um `<input type="date">` cada).
     *
     * @param array<string,mixed> $get
     */
    public static function from_query( array $get ): self {
        $raw = $get[ self::QUERY_VAR ] ?? [];
        $raw = is_array( $raw ) ? (array) wp_unslash( $raw ) : [];

        if ( isset( $get[ self::QUERY_VAR_DATE_FROM ] ) ) {
            $raw[ self::DATE_FROM ] = wp_unslash( $get[ self::QUERY_VAR_DATE_FROM ] );
        }
        if ( isset( $get[ self::QUERY_VAR_DATE_TO ] ) ) {
            $raw[ self::DATE_TO ] = wp_unslash( $get[ self::QUERY_VAR_DATE_TO ] );
        }

        return self::from_array( $raw );
    }

    /**
     * Lê os filtros de uma requisição REST, mesclando o parâmetro legado
     * `theater` e o intervalo de datas (`date_from`/`date_to`), que o parâmetro
     * `filters` sobrescreve por coluna quando presente.
     */
    public static function from_rest_request( \WP_REST_Request $request ): self {
        $legacy = [
            'presentationTheater' => (string) $request['theater'],
            self::DATE_FROM       => (string) $request['date_from'],
            self::DATE_TO         => (string) $request['date_to'],
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
        return [] === $this->values && ! $this->has_date_range();
    }

    public function has_date_range(): bool {
        return null !== $this->date_from || null !== $this->date_to;
    }

    public function date_from(): ?string {
        return $this->date_from;
    }

    public function date_to(): ?string {
        return $this->date_to;
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
     * @return array<string,mixed>
     */
    public function to_query_args(): array {
        $args = [];

        if ( $this->values ) {
            $args[ self::QUERY_VAR ] = $this->values;
        }
        if ( null !== $this->date_from ) {
            $args[ self::QUERY_VAR_DATE_FROM ] = $this->date_from;
        }
        if ( null !== $this->date_to ) {
            $args[ self::QUERY_VAR_DATE_TO ] = $this->date_to;
        }

        return $args;
    }

    /**
     * @return array<string,mixed>
     */
    public function to_rest_params(): array {
        $params = [ self::REST_PARAM => $this->values ];

        if ( null !== $this->date_from ) {
            $params[ self::DATE_FROM ] = $this->date_from;
        }
        if ( null !== $this->date_to ) {
            $params[ self::DATE_TO ] = $this->date_to;
        }

        return $params;
    }

    /**
     * Fragmento estável (colunas e, dentro delas, valores, ambos ordenados;
     * mais o intervalo de datas) para compor a chave do transient de consulta.
     *
     * @return array{values:array<string,list<string|int>>,date_from:?string,date_to:?string}
     */
    public function cache_fragment(): array {
        $values = $this->values;
        ksort( $values );

        return [
            'values'    => $values,
            'date_from' => $this->date_from,
            'date_to'   => $this->date_to,
        ];
    }

    /**
     * Valida e normaliza uma data crua para `Y-m-d`; `null` se ausente/vazia
     * ou não for uma data real (formato errado ou calendário inválido, ex.:
     * "2024-02-30").
     *
     * @param mixed $raw
     */
    private static function normalize_date( $raw ): ?string {
        $raw = trim( (string) $raw );
        if ( '' === $raw || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
            return null;
        }

        [ , $year, $month, $day ] = $m;
        if ( ! checkdate( (int) $month, (int) $day, (int) $year ) ) {
            return null;
        }

        return $raw;
    }
}
