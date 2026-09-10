<?php
/**
 * Renderiza o `<form method="get">` do shortcode: campos ocultos que preservam
 * outras query vars, o campo de busca, o `<select>` "Agrupado por" e — novidade —
 * um `<select>` por coluna facetável, populado com os valores distintos da tabela.
 *
 * Todos os `<select>` ficam dentro do mesmo `<form>`; assim, sem JavaScript, um
 * submit da busca preserva os filtros automaticamente (nenhum campo oculto extra).
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Rendering;

use TeatroMusicadoSP\Customizations\Presentations\PresentationsRequest;
use TeatroMusicadoSP\Customizations\Presentations\Filters\PresentationFilters;
use TeatroMusicadoSP\Customizations\Presentations\Filters\PresentationsFacets;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingModes;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationColumn;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class FilterFormRenderer
{
    /** @var PresentationsFacets */
    private $facets;

    /** @var string */
    private $page_url;

    public function __construct( PresentationsFacets $facets, string $page_url ) {
        $this->facets   = $facets;
        $this->page_url = $page_url;
    }

    public function render( string $search, bool $has_request_search, string $group, PresentationFilters $filters ): string {
        ob_start();
        ?>
        <form class="teatro-apresentacoes__search" method="get" action="<?php echo esc_url( $this->page_url ); ?>" role="search">
            <?php echo $this->preserved_query_fields(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <div class="teatro-apresentacoes__search-field">
                <label for="teatro-apresentacoes-s" class="teatro-apresentacoes__search-label">
                    <?php esc_html_e( 'Buscar apresentações', 'customizations-teatromusicadosp' ); ?>
                </label>
                <input
                    type="search"
                    id="teatro-apresentacoes-s"
                    name="<?php echo esc_attr( PresentationsRequest::QV_SEARCH ); ?>"
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
                    name="<?php echo esc_attr( PresentationsRequest::QV_GROUP ); ?>"
                    onchange="this.form.submit()"
                >
                    <option value=""><?php esc_html_e( 'Nenhum', 'customizations-teatromusicadosp' ); ?></option>
                    <?php foreach ( GroupingModes::all() as $group_key => $mode ) : ?>
                        <option value="<?php echo esc_attr( $group_key ); ?>" <?php selected( $group, $group_key ); ?>>
                            <?php echo esc_html( $mode->label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="teatro-apresentacoes__filters">
                <?php foreach ( PresentationsSchema::facetable_columns() as $key => $column ) : ?>
                    <?php echo $this->column_select( $key, $column, $filters->get( $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php endforeach; ?>
            </div>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param string|int|null $selected
     */
    private function column_select( string $key, PresentationColumn $column, $selected ): string {
        $options     = $this->facets->options( $key );
        $selected    = ( null === $selected ) ? '' : (string) $selected;
        $field_id    = 'teatro-apresentacoes-f-' . $key;
        $field_name  = PresentationFilters::QUERY_VAR . '[' . $key . ']';

        ob_start();
        ?>
        <div class="teatro-apresentacoes__filter-field">
            <label for="<?php echo esc_attr( $field_id ); ?>" class="teatro-apresentacoes__search-label">
                <?php echo esc_html( $column->label ); ?>
            </label>
            <select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" onchange="this.form.submit()">
                <option value=""><?php esc_html_e( 'Todos', 'customizations-teatromusicadosp' ); ?></option>
                <?php foreach ( $options as $option ) : ?>
                    <?php $option = (string) $option; ?>
                    <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $selected, $option ); ?>>
                        <?php echo esc_html( $option ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Campos ocultos para preservar outras query vars ao submeter a busca (mas
     * descartando as nossas — a busca sempre volta para a página 1). Os filtros
     * (`tap_f`) não entram aqui: cada coluna facetável tem seu próprio `<select>`
     * dentro deste mesmo formulário.
     */
    private function preserved_query_fields(): string {
        $skip = [
            PresentationsRequest::QV_SEARCH,
            PresentationsRequest::QV_PAGED,
            PresentationsRequest::QV_GROUP,
            PresentationFilters::QUERY_VAR,
            'paged',
        ];

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
}
