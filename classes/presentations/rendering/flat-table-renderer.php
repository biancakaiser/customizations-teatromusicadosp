<?php
/**
 * Renderiza a tabela plana (modo "Nenhum") do shortcode `[teatro_apresentacoes]`.
 *
 * O cabeçalho tem 2 linhas: a 1ª agrupa as colunas pelos grupos de
 * `PresentationsSchema::FLAT_LAYOUT` (um `<th colspan>` por grupo com mais de 1
 * coluna); a 2ª mostra o `header_label` de cada coluna agrupada. Colunas sozinhas
 * no seu grupo viram um `<th rowspan="2">` na 1ª linha. O `filters_label` completo
 * fica nos atributos `abbr`/`data-label` de cada `<th>` de coluna, para
 * acessibilidade e para os cartões em telas estreitas.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Rendering;

use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class FlatTableRenderer
{
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function render( array $rows ): string {
        $columns = PresentationsSchema::flat_columns();
        $groups  = PresentationsSchema::flat_column_groups();

        ob_start();
        ?>
        <table class="teatro-apresentacoes__table">
            <caption class="screen-reader-text">
                <?php esc_html_e( 'Lista de apresentações', 'customizations-teatromusicadosp' ); ?>
            </caption>
            <thead>
                <tr>
                    <?php foreach ( $groups as $group_label => $members ) : ?>
                        <?php if ( count( $members ) > 1 ) : ?>
                            <th scope="colgroup" colspan="<?php echo esc_attr( (string) count( $members ) ); ?>"><?php echo esc_html( $group_label ); ?></th>
                        <?php else : ?>
                            <?php
                            $solo_key   = array_key_first( $members );
                            $solo       = $members[ $solo_key ];
                            $is_acronym = PresentationsSchema::is_acronym( $solo_key );
                            $class_attr = $is_acronym ? ' class="teatro-apresentacoes__col--acronym"' : '';
                            ?>
                            <th scope="col" rowspan="2" abbr="<?php echo esc_attr( $solo['filters_label'] ); ?>" data-label="<?php echo esc_attr( $columns[ $solo_key ] ); ?>"<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>><span class="teatro-apresentacoes__th-label<?php echo $is_acronym ? ' teatro-apresentacoes__acronym-head' : ''; ?>"><?php echo esc_html( $solo['header_label'] ); ?></span></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ( $groups as $members ) : ?>
                        <?php if ( count( $members ) > 1 ) : ?>
                            <?php foreach ( $members as $key => $col ) : ?>
                                <?php
                                $is_acronym = PresentationsSchema::is_acronym( $key );
                                $class_attr = $is_acronym ? ' class="teatro-apresentacoes__col--acronym"' : '';
                                ?>
                                <th scope="col" abbr="<?php echo esc_attr( $col['filters_label'] ); ?>" data-label="<?php echo esc_attr( $columns[ $key ] ); ?>"<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>><span class="teatro-apresentacoes__th-label<?php echo $is_acronym ? ' teatro-apresentacoes__acronym-head' : ''; ?>"><?php echo esc_html( $col['header_label'] ); ?></span></th>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <?php foreach ( array_keys( $columns ) as $key ) : ?>
                            <?php $value = $row[ $key ] ?? null; ?>
                            <?php if ( 'presentationDate' === $key ) { $value = PresentationValue::date_br( $value ); } ?>
                            <td data-label="<?php echo esc_attr( $columns[ $key ] ); ?>">
                                <?php echo $this->cell_html( $key, $value, $row ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * HTML (já escapado) de uma célula: link para a ficha nas colunas de
     * `PresentationsSchema::ITEM_LINKS`, pessoas nas compostas (Concepção,
     * Adaptação), `<abbr>` nas de sigla (nacionalidades, idioma); texto nas demais.
     *
     * @param mixed               $value Valor já formatado (data).
     * @param array<string,mixed> $row
     */
    private function cell_html( string $key, $value, array $row ): string {
        if ( isset( PresentationsSchema::ITEM_LINKS[ $key ] ) ) {
            [ $collection, $slug_column ] = PresentationsSchema::ITEM_LINKS[ $key ];
            return PresentationValue::item_link( $value, $row[ $slug_column ] ?? '', $collection );
        }

        $column = PresentationsSchema::column( $key );
        if ( null !== $column && $column->is_composite() ) {
            return PresentationValue::composite_html( $row, $column );
        }

        if ( PresentationsSchema::is_acronym( $key ) ) {
            return PresentationValue::acronym_html( $value );
        }

        return esc_html( ( null === $value || '' === $value ) ? '—' : (string) $value );
    }
}
