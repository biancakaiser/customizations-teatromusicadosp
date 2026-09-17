<?php
/**
 * Fonte única de "linhas → HTML pronto para a área de resultados": a tabela
 * (plana ou agrupada) quando há linhas, ou a mensagem de "nada encontrado"
 * quando não há. Usada tanto pela renderização inicial do shortcode
 * (`PresentationsShortcode::render()`) quanto pelas duas rotas REST — assim
 * nem o PHP nem o `assets/presentations.js` precisam decidir esse estado em
 * mais de um lugar (o JS só troca `innerHTML` pelo que vier daqui).
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Rendering;

use TeatroMusicadoSP\Customizations\Presentations\Grouping\GroupingMode;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class ResultsContentRenderer
{
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public static function flat( array $rows ): string {
        return $rows ? ( new FlatTableRenderer() )->render( $rows ) : self::empty_html();
    }

    /**
     * @param array<int,array<string,mixed>> $rows Linhas usadas só para decidir
     *                                              se há resultado — a árvore já
     *                                              vem pronta em `$tree`.
     * @param array<string,array<string,mixed>> $tree
     */
    public static function grouped( array $rows, array $tree, GroupingMode $mode ): string {
        return $rows ? ( new GroupedTableRenderer() )->render( $tree, $mode ) : self::empty_html();
    }

    public static function empty_html(): string {
        return '<p class="teatro-apresentacoes__empty">'
            . esc_html__( 'Nenhuma apresentação encontrada.', 'customizations-teatromusicadosp' )
            . '</p>';
    }
}
