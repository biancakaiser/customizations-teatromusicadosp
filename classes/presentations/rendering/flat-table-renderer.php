<?php
/**
 * Renderiza a tabela plana (modo "Nenhum") do shortcode `[teatro_apresentacoes]`.
 *
 * Movido, sem mudança de markup, do corpo inline de
 * `PresentationsShortcode::render()`.
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

        ob_start();
        ?>
        <table class="teatro-apresentacoes__table">
            <caption class="screen-reader-text">
                <?php esc_html_e( 'Lista de apresentações', 'customizations-teatromusicadosp' ); ?>
            </caption>
            <thead>
                <tr>
                    <?php foreach ( $columns as $label ) : ?>
                        <th scope="col"><?php echo esc_html( $label ); ?></th>
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
