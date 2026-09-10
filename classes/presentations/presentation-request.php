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
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingModes;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationsRequest
{
    const QV_SEARCH = 'tap_s';
    const QV_PAGED  = 'tap_paged';
    const QV_GROUP  = 'tap_group';

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

    public function per_page(): int {
        return min( 200, max( 1, (int) ( $this->atts['per_page'] ?? 25 ) ) );
    }

    public function orderby(): string {
        return (string) ( $this->atts['orderby'] ?? 'presentationDate' );
    }

    public function order(): string {
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
     * Filtros por coluna: os atributos legados `theater`/`year` formam a base;
     * a query var `tap_f` (escolhas do visitante) sobrescreve.
     */
    public function filters(): PresentationFilters {
        $from_atts = PresentationFilters::from_array(
            [
                'theaterName' => (string) ( $this->atts['theater'] ?? '' ),
                'settingYear' => (int) ( $this->atts['year'] ?? 0 ),
            ]
        );

        $from_query = PresentationFilters::from_query( $this->get );

        return PresentationFilters::from_array(
            array_merge( $from_atts->values(), $from_query->values() )
        );
    }
}
