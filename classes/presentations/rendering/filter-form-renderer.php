<?php
/**
 * Renderiza o `<form method="get">` do shortcode: campos ocultos que preservam
 * outras query vars, o campo de busca, o `<select>` "Agrupado por" e um
 * `<details>` por coluna facetável — uma "caixa tipo select" com uma caixa de
 * seleção por valor distinto, permitindo marcar vários valores por coluna
 * (combinados em OR na consulta; ver `checkbox_field()`).
 *
 * Um único botão de submit no fim do formulário; nenhum campo dispara busca
 * sozinho — tudo entra na mesma consulta ao clicar em "Buscar".
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
            <input type="hidden" name="<?php echo esc_attr( PresentationsRequest::QV_SUBMITTED ); ?>" value="1" />
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
            </div>
            <div class="teatro-apresentacoes__group-field">
                <label for="teatro-apresentacoes-group" class="teatro-apresentacoes__search-label">
                    <?php esc_html_e( 'Agrupado por', 'customizations-teatromusicadosp' ); ?>
                </label>
                <select
                    id="teatro-apresentacoes-group"
                    name="<?php echo esc_attr( PresentationsRequest::QV_GROUP ); ?>"
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
                <?php foreach ( PresentationsSchema::filter_columns_ordered() as $key => $column ) : ?>
                    <?php echo $this->checkbox_field( $key, $column, $filters->get( $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php endforeach; ?>
            </div>
            <div class="teatro-apresentacoes__submit-field">
                <button type="submit"><?php esc_html_e( 'Buscar', 'customizations-teatromusicadosp' ); ?></button>
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
     * Rótulo do campo de filtro. As colunas com busca interna usam um rótulo
     * "Nome da …" (mais claro para o visitante que o rótulo canônico curto).
     */
    private function filter_label( string $key, PresentationColumn $column ): string {
        switch ( $key ) {
            case 'playName':
                return __( 'Nome da Peça', 'customizations-teatromusicadosp' );
            case 'companyName':
                return __( 'Nome da Companhia', 'customizations-teatromusicadosp' );
            default:
                return $column->label;
        }
    }

    /**
     * "Caixa tipo select" com caixas de seleção: um `<details>` nativo (funciona
     * sem JS — o navegador já sabe abrir/fechar) cujo painel lista um
     * `<input type="checkbox" name="tap_f[col][]">` por valor distinto. Marcar
     * várias caixas filtra em OR (`col IN (...)`, ver `PresentationFilterClause`).
     * Nas colunas `searchable`, um campo de busca (melhorado via JS) filtra a
     * lista de caixas — não filtra a tabela, só a lista visível.
     *
     * @param list<string|int> $selected Valores hoje marcados nesta coluna.
     */
    private function checkbox_field( string $key, PresentationColumn $column, array $selected ): string {
        $options        = $this->facets->options( $key );
        $selected       = array_map( 'strval', $selected );
        $field_id       = 'teatro-apresentacoes-f-' . $key;
        $field_name     = PresentationFilters::QUERY_VAR . '[' . $key . '][]';
        $label          = $this->filter_label( $key, $column );
        $selected_count = count( $selected );
        $field_class    = 'teatro-apresentacoes__filter-field'
            . ( $column->searchable ? ' teatro-apresentacoes__filter-field--searchable' : '' );

        ob_start();
        ?>
        <details
            class="<?php echo esc_attr( $field_class ); ?>"
            <?php if ( $column->searchable ) : ?>
            data-search-placeholder="<?php esc_attr_e( 'Digite para buscar…', 'customizations-teatromusicadosp' ); ?>"
            <?php endif; ?>
        >
            <summary id="<?php echo esc_attr( $field_id ); ?>-label">
                <span class="teatro-apresentacoes__filter-label"><?php echo esc_html( $label ); ?></span>
                <span
                    class="teatro-apresentacoes__filter-count"
                    <?php echo 0 === $selected_count ? ' hidden' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                ><?php echo esc_html( (string) $selected_count ); ?></span>
            </summary>
            <div class="teatro-apresentacoes__filter-panel">
                <?php if ( $column->searchable ) : ?>
                    <input
                        type="text"
                        class="teatro-apresentacoes__filter-search"
                        placeholder="<?php esc_attr_e( 'Digite para buscar…', 'customizations-teatromusicadosp' ); ?>"
                        aria-controls="<?php echo esc_attr( $field_id ); ?>-options"
                    />
                <?php endif; ?>
                <div
                    id="<?php echo esc_attr( $field_id ); ?>-options"
                    class="teatro-apresentacoes__filter-options"
                    role="group"
                    aria-labelledby="<?php echo esc_attr( $field_id ); ?>-label"
                >
                    <?php foreach ( $options as $i => $option ) : ?>
                        <?php
                        $option  = (string) $option;
                        $opt_id  = $field_id . '-' . $i;
                        ?>
                        <label class="teatro-apresentacoes__filter-option" for="<?php echo esc_attr( $opt_id ); ?>">
                            <input
                                type="checkbox"
                                id="<?php echo esc_attr( $opt_id ); ?>"
                                name="<?php echo esc_attr( $field_name ); ?>"
                                value="<?php echo esc_attr( $option ); ?>"
                                <?php checked( in_array( $option, $selected, true ) ); ?>
                            />
                            <span><?php echo esc_html( $option ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>
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
            PresentationsRequest::QV_SUBMITTED,
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
