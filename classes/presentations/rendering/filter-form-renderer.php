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

    public function render(
        string $search,
        bool $has_request_search,
        string $group,
        PresentationFilters $filters,
        string $orderby = 'presentationDate',
        string $order = 'ASC'
    ): string {
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
            <?php echo $this->sort_field( $group, $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
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
     * `<select>` "Ordenado por:" + `<select>` de direção. As colunas oferecidas
     * dependem do modo: no modo plano são as colunas da tabela plana; num modo
     * agrupado são as colunas daquele agrupamento (identidade + folha + Total).
     */
    private function sort_field( string $group, string $orderby, string $order ): string {
        $options = self::sort_options( $group );
        $order   = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';

        ob_start();
        ?>
        <div class="teatro-apresentacoes__sort-field">
            <label for="teatro-apresentacoes-orderby" class="teatro-apresentacoes__search-label">
                <?php esc_html_e( 'Ordenado por:', 'customizations-teatromusicadosp' ); ?>
            </label>
            <select
                id="teatro-apresentacoes-orderby"
                name="<?php echo esc_attr( PresentationsRequest::QV_ORDERBY ); ?>"
                onchange="this.form.submit()"
            >
                <?php foreach ( $options as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $orderby, $value ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="teatro-apresentacoes-order" class="teatro-apresentacoes__search-label">
                <?php esc_html_e( 'Ordem', 'customizations-teatromusicadosp' ); ?>
            </label>
            <select
                id="teatro-apresentacoes-order"
                name="<?php echo esc_attr( PresentationsRequest::QV_ORDER ); ?>"
                onchange="this.form.submit()"
            >
                <option value="ASC" <?php selected( $order, 'ASC' ); ?>><?php esc_html_e( 'Crescente', 'customizations-teatromusicadosp' ); ?></option>
                <option value="DESC" <?php selected( $order, 'DESC' ); ?>><?php esc_html_e( 'Decrescente', 'customizations-teatromusicadosp' ); ?></option>
            </select>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Mapa `coluna => rótulo` do `<select>` "Ordenado por:" para um modo de
     * agrupamento (`''` = modo plano). Também usado, agregado por modo, no
     * `data-sort-columns` que o JS lê para repopular o campo sem recarregar.
     *
     * @return array<string,string>
     */
    public static function sort_options( string $group ): array {
        $mode = '' !== $group ? GroupingModes::get( $group ) : null;

        return null !== $mode
            ? $mode->sortable_columns()
            : PresentationsSchema::flat_columns();
    }

    /**
     * `data-sort-columns`: as opções de "Ordenado por:" de cada modo, para o JS
     * trocar o conteúdo do `<select>` quando o agrupamento muda sem reload.
     *
     * @return array<string,array<string,string>>
     */
    public static function sort_options_by_mode(): array {
        $map = [ '' => self::sort_options( '' ) ];

        foreach ( array_keys( GroupingModes::all() ) as $group_key ) {
            $map[ $group_key ] = self::sort_options( $group_key );
        }

        return $map;
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
            PresentationsRequest::QV_ORDERBY,
            PresentationsRequest::QV_ORDER,
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
