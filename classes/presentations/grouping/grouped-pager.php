<?php
/**
 * Corta a árvore de agrupamento (já pronta por `PresentationGrouper::build()`)
 * em páginas, sem nunca dividir uma faixa de 1º nível entre duas páginas.
 *
 * Acumula faixas inteiras, na ordem em que já estão na árvore, até atingir ou
 * ultrapassar `$per_page` linhas-folha; a faixa que cruza esse limite fecha a
 * página por inteiro (a página pode passar de `$per_page`, nunca ficar abaixo,
 * exceto a última). Como o corte acontece só entre faixas, nem
 * `PresentationGrouper` nem `GroupedTableRenderer` precisam mudar: ambos
 * continuam recebendo/produzindo uma árvore no mesmo formato, só que com menos
 * chaves de 1º nível.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Grouping;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class GroupedPager
{
    /**
     * @param array<string,array<string,mixed>> $tree Árvore completa, já ordenada.
     * @return array{tree:array<string,array<string,mixed>>,total_pages:int}
     */
    public function paginate( array $tree, int $per_page, int $page ): array {
        if ( $per_page <= 0 || ! $tree ) {
            return [
                'tree'        => $tree,
                'total_pages' => 1,
            ];
        }

        $pages   = [];
        $current = [];
        $count   = 0;

        foreach ( $tree as $l1_key => $l1 ) {
            $current[] = $l1_key;
            $count    += $this->group_size( $l1 );

            if ( $count >= $per_page ) {
                $pages[] = $current;
                $current = [];
                $count   = 0;
            }
        }
        if ( $current ) {
            $pages[] = $current;
        }

        $total_pages = max( 1, count( $pages ) );
        $page        = max( 1, min( $page, $total_pages ) );
        $keys        = $pages[ $page - 1 ] ?? [];

        return [
            'tree'        => array_intersect_key( $tree, array_flip( $keys ) ),
            'total_pages' => $total_pages,
        ];
    }

    /**
     * Número de linhas-folha (mesma unidade de `$l1_row_count` em
     * `GroupedTableRenderer`) de uma faixa de 1º nível.
     *
     * @param array<string,mixed> $l1
     */
    private function group_size( array $l1 ): int {
        $size = 0;
        foreach ( $l1['l2'] as $l2 ) {
            $size += count( $l2['leaves'] );
        }
        return $size;
    }
}
