<?php
/**
 * Renderiza a tabela plana (modo "Nenhum") do shortcode `[teatro_apresentacoes]`.
 *
 * O cabeçalho tem 2 linhas: a 1ª agrupa as colunas por `PresentationColumn::$group`
 * (um `<th colspan>` por grupo com mais de 1 coluna); a 2ª mostra o `short_label`
 * de cada coluna agrupada. Colunas cujo grupo não é compartilhado por nenhuma
 * outra viram um `<th rowspan="2">` sozinho na 1ª linha. O `full_label` completo
 * fica no atributo `abbr` de cada `<th>` de coluna, para acessibilidade.
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
                            $class_attr = PresentationsSchema::is_acronym( $solo_key ) ? ' class="teatro-apresentacoes__col--acronym"' : '';
                            ?>
                            <th scope="col" rowspan="2" abbr="<?php echo esc_attr( $solo['full_label'] ); ?>"<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $solo['short_label'] ); ?></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ( $groups as $members ) : ?>
                        <?php if ( count( $members ) > 1 ) : ?>
                            <?php foreach ( $members as $key => $col ) : ?>
                                <?php $class_attr = PresentationsSchema::is_acronym( $key ) ? ' class="teatro-apresentacoes__col--acronym"' : ''; ?>
                                <th scope="col" abbr="<?php echo esc_attr( $col['full_label'] ); ?>"<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $col['short_label'] ); ?></th>
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
                            <?php $is_acronym = PresentationsSchema::is_acronym( $key ); ?>
                            <?php if ( $is_acronym ) { $value = PresentationValue::acronym( $value ); } ?>
                            <?php $class_attr = $is_acronym ? ' class="teatro-apresentacoes__col--acronym"' : ''; ?>
                            <td<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> data-label="<?php echo esc_attr( $columns[ $key ] ); ?>">
                                <?php echo esc_html( ( null === $value || '' === $value ) ? '—' : (string) $value ); ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return (string) ob_get_clean();
    }
}
