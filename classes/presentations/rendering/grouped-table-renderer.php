<?php
/**
 * Renderiza a tabela agrupada do shortcode: um único `<table>` com o cabeçalho de
 * colunas exibido uma vez e um `<tbody>` por grupo de 1º nível, cada qual iniciado
 * por uma faixa (`<tr>` com `<th colspan>`). As colunas de identidade do 2º nível
 * e a coluna "Total" são mescladas com `rowspan`.
 *
 * As colunas-folha vêm de `GroupingMode::leaf_columns` (variam por modo); nos
 * modos de 2 níveis não há colunas de identidade.
 *
 * Movido de `PresentationsShortcode::group_table_html()`.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Rendering;

use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingMode;
use TeatroMusicadoSP\Customizations\Presentations\Grouping\PresentationGrouper;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class GroupedTableRenderer
{
    /**
     * @param array<string,array<string,mixed>> $tree
     */
    public function render( array $tree, GroupingMode $mode ): string {
        $identity_keys      = $mode->identity;
        $identity_label_map = $mode->labels_for( $mode->identity );
        $leaf_label_map     = $mode->leaf_labels();
        $sessions_label     = $leaf_label_map['presentationSessionsN'];
        $total_label        = $leaf_label_map[ GroupingMode::SORT_TOTAL ];
        $ncols              = count( $identity_keys ) + count( $leaf_label_map );

        // Colunas-folha do modo: coluna do schema => [ chave na folha, rótulo, é sigla? ].
        $leaf_columns = [];
        foreach ( $mode->leaf_columns as $column ) {
            $leaf_columns[ $column ] = [
                'field'   => PresentationGrouper::field_for( $column ),
                'label'   => $leaf_label_map[ $column ],
                'acronym' => PresentationsSchema::is_acronym( $column ),
            ];
        }

        ob_start();
        ?>
        <table class="teatro-apresentacoes__table teatro-apresentacoes__table--group">
            <caption class="screen-reader-text">
                <?php esc_html_e( 'Apresentações agrupadas', 'customizations-teatromusicadosp' ); ?>
            </caption>
            <thead>
                <tr>
                    <?php foreach ( $identity_keys as $ikey ) : ?>
                        <?php
                        $is_acronym = PresentationsSchema::is_acronym( $ikey );
                        $class_attr = $is_acronym ? ' class="teatro-apresentacoes__col--acronym"' : '';
                        ?>
                        <th scope="col"<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php if ( $is_acronym ) : ?><span class="teatro-apresentacoes__acronym-head"><?php echo esc_html( $identity_label_map[ $ikey ] ); ?></span><?php else : ?><?php echo esc_html( $identity_label_map[ $ikey ] ); ?><?php endif; ?></th>
                    <?php endforeach; ?>
                    <?php foreach ( $leaf_columns as $col ) : ?>
                        <?php $class_attr = $col['acronym'] ? ' class="teatro-apresentacoes__col--acronym"' : ''; ?>
                        <th scope="col"<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php if ( $col['acronym'] ) : ?><span class="teatro-apresentacoes__acronym-head"><?php echo esc_html( $col['label'] ); ?></span><?php else : ?><?php echo esc_html( $col['label'] ); ?><?php endif; ?></th>
                    <?php endforeach; ?>
                    <th scope="col"><?php echo esc_html( $sessions_label ); ?></th>
                    <th scope="col"><?php echo esc_html( $total_label ); ?></th>
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
                // 3 níveis: nº de blocos de 2º nível; 2 níveis: nº de linhas-folha.
                $l1_count       = $mode->has_identity_level() ? count( $l1['l2'] ) : $l1_row_count;
                ?>
                <tbody class="teatro-apresentacoes__group">
                    <tr class="teatro-apresentacoes__group-row">
                        <th scope="colgroup" colspan="<?php echo esc_attr( (string) $ncols ); ?>">
                            <?php
                            echo esc_html(
                                $l1['label']
                                . ' | ' . $mode->count_label . ': ' . number_format_i18n( $l1_count )
                                . ' | ' . GroupingMode::GROUP_TOTAL_LABEL . ': ' . number_format_i18n( $l1['total'] )
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
                                        <?php $is_acronym = PresentationsSchema::is_acronym( $ikey ); ?>
                                        <?php $value = $is_acronym ? PresentationValue::acronym( $value ) : ( '' !== $value ? $value : '—' ); ?>
                                        <td class="teatro-apresentacoes__group-cell"<?php echo $span_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> data-label="<?php echo esc_attr( $identity_label_map[ $ikey ] ); ?>">
                                            <?php echo esc_html( $value ); ?>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php foreach ( $leaf_columns as $col ) : ?>
                                    <?php $value = (string) $leaf[ $col['field'] ]; ?>
                                    <?php $value = $col['acronym'] ? PresentationValue::acronym( $value ) : $value; ?>
                                    <td data-label="<?php echo esc_attr( $col['label'] ); ?>"><?php echo esc_html( $value ); ?></td>
                                <?php endforeach; ?>
                                <td data-label="<?php echo esc_attr( $sessions_label ); ?>"><?php echo esc_html( number_format_i18n( $leaf['sessions'] ) ); ?></td>
                                <?php if ( ! $l1_total_shown ) : ?>
                                    <?php $l1_total_shown = true; ?>
                                    <td class="teatro-apresentacoes__group-cell"<?php echo $l1_span_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> data-label="<?php echo esc_attr( $total_label ); ?>">
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
