<?php
/**
 * Renderiza a tabela agrupada do shortcode: um único `<table>` com o cabeçalho de
 * colunas exibido uma vez e um `<tbody>` por grupo de 1º nível, cada qual iniciado
 * por uma faixa (`<tr>` com `<th colspan>`). As colunas de identidade do 2º nível
 * e a coluna "Total" são mescladas com `rowspan`.
 *
 * Movido, sem mudança de markup, de `PresentationsShortcode::group_table_html()`.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Rendering;

use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingMode;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingModes;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class GroupedTableRenderer
{
    /**
     * @param array<string,array<string,mixed>> $tree
     */
    public function render( array $tree, GroupingMode $mode ): string {
        $identity_keys   = array_keys( $mode->identity );
        $identity_labels = array_values( $mode->identity );
        $head_labels     = array_merge( $identity_labels, GroupingModes::LEAF_LABELS );
        $ncols           = count( $head_labels );

        ob_start();
        ?>
        <table class="teatro-apresentacoes__table teatro-apresentacoes__table--group">
            <caption class="screen-reader-text">
                <?php esc_html_e( 'Apresentações agrupadas', 'customizations-teatromusicadosp' ); ?>
            </caption>
            <thead>
                <tr>
                    <?php foreach ( $head_labels as $label ) : ?>
                        <th scope="col"><?php echo esc_html( $label ); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <?php foreach ( $tree as $l1 ) : ?>
                <?php
                $l1_row_count = 0;
                foreach ( $l1['l2'] as $l2_for_count ) {
                    $l1_row_count += count( $l2_for_count['leaves'] );
                }
                $l1_span_attr   = $l1_row_count > 1 ? ' rowspan="' . esc_attr( (string) $l1_row_count ) . '"' : '';
                $l1_total_shown = false;
                ?>
                <tbody class="teatro-apresentacoes__group">
                    <tr class="teatro-apresentacoes__group-row">
                        <th scope="colgroup" colspan="<?php echo esc_attr( (string) $ncols ); ?>">
                            <?php
                            echo esc_html(
                                $l1['label']
                                . ' - ' . $mode->count_label . ': ' . number_format_i18n( count( $l1['l2'] ) )
                            );
                            ?>
                        </th>
                    </tr>
                    <?php foreach ( $l1['l2'] as $l2 ) : ?>
                        <?php
                        $leaves    = array_values( $l2['leaves'] );
                        $leaf_span = count( $leaves );
                        $span_attr = $leaf_span > 1 ? ' rowspan="' . esc_attr( (string) $leaf_span ) . '"' : '';
                        ?>
                        <?php foreach ( $leaves as $i => $leaf ) : ?>
                            <tr>
                                <?php if ( 0 === $i ) : ?>
                                    <?php foreach ( $identity_keys as $ikey ) : ?>
                                        <?php $value = $l2['identity'][ $ikey ] ?? ''; ?>
                                        <td class="teatro-apresentacoes__group-cell"<?php echo $span_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> data-label="<?php echo esc_attr( $mode->identity[ $ikey ] ); ?>">
                                            <?php echo esc_html( '' !== $value ? $value : '—' ); ?>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <td data-label="Teatro"><?php echo esc_html( $leaf['theater'] ); ?></td>
                                <td data-label="Tipo de Espetáculo"><?php echo esc_html( $leaf['kind'] ); ?></td>
                                <td data-label="Ano"><?php echo esc_html( (string) $leaf['year'] ); ?></td>
                                <td data-label="Nº de Sessões"><?php echo esc_html( number_format_i18n( $leaf['sessions'] ) ); ?></td>
                                <?php if ( ! $l1_total_shown ) : ?>
                                    <?php $l1_total_shown = true; ?>
                                    <td class="teatro-apresentacoes__group-cell"<?php echo $l1_span_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> data-label="Total">
                                        <?php echo esc_html( number_format_i18n( $l1['total'] ) ); ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            <?php endforeach; ?>
        </table>
        <?php

        return (string) ob_get_clean();
    }
}
