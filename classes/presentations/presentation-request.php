<?php
/**
 * Interpreta a requisição do visitante para o shortcode `[teatro_apresentacoes]`.
 *
 * Concentra toda a leitura de `$_GET` e a mescla com os atributos do shortcode,
 * que antes estava espalhada pelo início de `PresentationsShortcode::render()`.
 * As query vars são prefixadas (`tap_*`) para não colidir com a query principal.
 */

namespace TeatroMusicadoSP\Customizations\Presentations;

use TeatroMusicadoSP\Customizations\Presentations\Filters\PresentationFilters;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingMode;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingModes;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationsRequest
{
    const QV_SEARCH           = 'tap_s';
    const QV_PAGED            = 'tap_paged';
    const QV_GROUP            = 'tap_group';
    const QV_ORDERBY          = 'tap_orderby';
    const QV_ORDER            = 'tap_order';
    const QV_SUBMITTED        = 'tap_go';
    const QV_RESULTS_PER_PAGE = 'tap_rpp';

    /**
     * Tamanhos de página aceitos pelo seletor "Resultados por página" (modo
     * plano e agrupado); `0` = "Sem Paginação".
     */
    const RESULTS_PER_PAGE_OPTIONS = [ 0, 50, 100, 200, 500, 1000 ];

    /** Tamanho de página padrão quando o visitante não escolheu nenhum. */
    const DEFAULT_RESULTS_PER_PAGE = 100;

    /** @var array<string,mixed> */
    private $get;

    /** @var array<string,mixed> */
    private $atts;

    /**
     * @param array<string,mixed> $get  Normalmente `$_GET`.
     * @param array<string,mixed> $atts Já passado por `shortcode_atts()`.
     */
    public function __construct( array $get, array $atts ) {
        $this->get  = $get;
        $this->atts = $atts;
    }

    public function has_request_search(): bool {
        return isset( $this->get[ self::QV_SEARCH ] );
    }

    /**
     * O visitante já submeteu o formulário ao menos uma vez? Enquanto for `false`,
     * o shortcode não consulta o banco — a tabela só carrega a partir do 1º submit.
     * O `<form>` sempre inclui um `<input type="hidden" name="tap_go" value="1">`,
     * então qualquer submit (botão ou programático) traz o marcador.
     */
    public function has_submitted(): bool {
        return isset( $this->get[ self::QV_SUBMITTED ] );
    }

    /**
     * A busca digitada pelo visitante prevalece sobre o atributo do shortcode.
     */
    public function search(): string {
        if ( $this->has_request_search() ) {
            return sanitize_text_field( wp_unslash( $this->get[ self::QV_SEARCH ] ) );
        }
        return (string) ( $this->atts['search'] ?? '' );
    }

    public function page(): int {
        return isset( $this->get[ self::QV_PAGED ] ) ? max( 1, (int) $this->get[ self::QV_PAGED ] ) : 1;
    }

    /**
     * Tamanho de página do seletor "Resultados por página" (`0` = "Sem
     * Paginação"), usado pelos dois modos (plano e agrupado). A escolha do
     * visitante (`tap_rpp`) prevalece; na ausência dela, cai para o atributo
     * `per_page` do shortcode só se ele já for um dos valores aceitos —
     * senão (ex.: o padrão antigo, 25), usa `DEFAULT_RESULTS_PER_PAGE`.
     */
    public function results_per_page(): int {
        if ( isset( $this->get[ self::QV_RESULTS_PER_PAGE ] ) ) {
            $value = (int) $this->get[ self::QV_RESULTS_PER_PAGE ];
            if ( in_array( $value, self::RESULTS_PER_PAGE_OPTIONS, true ) ) {
                return $value;
            }
        }
        $attr_value = (int) ( $this->atts['per_page'] ?? 0 );
        if ( $attr_value > 0 && in_array( $attr_value, self::RESULTS_PER_PAGE_OPTIONS, true ) ) {
            return $attr_value;
        }
        return self::DEFAULT_RESULTS_PER_PAGE;
    }

    /**
     * O visitante escolheu a ordenação nesta requisição (query var `tap_orderby`)?
     */
    public function has_request_orderby(): bool {
        return isset( $this->get[ self::QV_ORDERBY ] );
    }

    /**
     * Coluna do `ORDER BY`. A escolha do visitante (`tap_orderby`) prevalece sobre
     * o atributo do shortcode, desde que seja uma coluna ordenável conhecida.
     *
     * Não usar `sanitize_key()`: ele força minúsculas e quebraria chaves camelCase
     * como `presentationDate`. A validação é por match exato na whitelist do schema.
     */
    public function orderby(): string {
        if ( $this->has_request_orderby() ) {
            $key = sanitize_text_field( wp_unslash( $this->get[ self::QV_ORDERBY ] ) );
            if ( PresentationsSchema::is_orderable( $key ) || GroupingMode::SORT_TOTAL === $key ) {
                return $key;
            }
        }
        return (string) ( $this->atts['orderby'] ?? 'presentationDate' );
    }

    public function order(): string {
        if ( isset( $this->get[ self::QV_ORDER ] ) ) {
            return strtoupper( sanitize_text_field( wp_unslash( $this->get[ self::QV_ORDER ] ) ) ) === 'DESC' ? 'DESC' : 'ASC';
        }
        return strtoupper( (string) ( $this->atts['order'] ?? 'ASC' ) ) === 'DESC' ? 'DESC' : 'ASC';
    }

    /**
     * Modo de agrupamento pedido, ou `''` se ausente/inválido.
     */
    public function group(): string {
        $group = isset( $this->get[ self::QV_GROUP ] )
            ? sanitize_key( wp_unslash( $this->get[ self::QV_GROUP ] ) )
            : '';
        return GroupingModes::has( $group ) ? $group : '';
    }

    public function is_grouped(): bool {
        return '' !== $this->group();
    }

    /**
     * Filtros por coluna: o atributo legado `theater` e o intervalo de datas
     * (`date_from`/`date_to`) formam a base; a query var `tap_f`/`tap_from`/
     * `tap_to` (escolhas do visitante) sobrescreve.
     */
    public function filters(): PresentationFilters {
        $from_atts = PresentationFilters::from_array(
            [
                'presentationTheater'          => (string) ( $this->atts['theater'] ?? '' ),
                PresentationFilters::DATE_FROM => (string) ( $this->atts['date_from'] ?? '' ),
                PresentationFilters::DATE_TO   => (string) ( $this->atts['date_to'] ?? '' ),
            ]
        );

        $from_query = PresentationFilters::from_query( $this->get );

        return PresentationFilters::from_array(
            array_merge(
                $from_atts->values(),
                [
                    PresentationFilters::DATE_FROM => $from_query->date_from() ?? $from_atts->date_from() ?? '',
                    PresentationFilters::DATE_TO   => $from_query->date_to() ?? $from_atts->date_to() ?? '',
                ],
                $from_query->values()
            )
        );
    }
}
