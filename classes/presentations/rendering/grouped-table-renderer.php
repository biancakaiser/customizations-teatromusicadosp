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
        $identity_labels    = array_values( $identity_label_map );
        $leaf_labels        = GroupingModes::leaf_labels();
        $head_labels        = array_merge( $identity_labels, $leaf_labels );
        $ncols              = count( $head_labels );
        [ $theater_label, $kind_label, $language_label, $year_label, $sessions_label, $total_label ] = $leaf_labels;
        $language_is_acronym = PresentationsSchema::is_acronym( 'presentationLanguage' );
        $language_class_attr = $language_is_acronym ? ' class="teatro-apresentacoes__col--acronym"' : '';

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
                    <th scope="col"><?php echo esc_html( $theater_label ); ?></th>
                    <th scope="col"><?php echo esc_html( $kind_label ); ?></th>
                    <th scope="col"<?php echo $language_class_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php if ( $language_is_acronym ) : ?><span class="teatro-apresentacoes__acronym-head"><?php echo esc_html( $language_label ); ?></span><?php else : ?><?php echo esc_html( $language_label ); ?><?php endif; ?></th>
                    <th scope="col"><?php echo esc_html( $year_label ); ?></th>
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
                ?>
                <tbody class="teatro-apresentacoes__group">
                    <tr class="teatro-apresentacoes__group-row">
                        <th scope="colgroup" colspan="<?php echo esc_attr( (string) $ncols ); ?>">
                            <?php
                            echo esc_html(
                                $l1['label']
                                . ' | ' . $mode->count_label . ': ' . number_format_i18n( count( $l1['l2'] ) )
                                . ' | ' . $total_label . ': ' . number_format_i18n( $l1['total'] )
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
                                <td data-label="<?php echo esc_attr( $theater_label ); ?>"><?php echo esc_html( $leaf['theater'] ); ?></td>
                                <td data-label="<?php echo esc_attr( $kind_label ); ?>"><?php echo esc_html( $leaf['kind'] ); ?></td>
                                <?php $language_value = $language_is_acronym ? PresentationValue::acronym( $leaf['language'] ) : $leaf['language']; ?>
                                <td data-label="<?php echo esc_attr( $language_label ); ?>"><?php echo esc_html( $language_value ); ?></td>
                                <td data-label="<?php echo esc_attr( $year_label ); ?>"><?php echo esc_html( (string) $leaf['year'] ); ?></td>
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
