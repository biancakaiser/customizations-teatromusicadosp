<?php
/**
 * Monta a árvore de agrupamento de 4 níveis para um `GroupingMode`.
 *
 * Movido, sem mudança de comportamento, do antigo
 * `PresentationsShortcode::build_groups()`.
 *
 * Estrutura devolvida (por nível 1):
 *   [ '<l1 name>' => [
 *       'label'  => 'Companhia: X - Nacionalidade: Y',
 *       'total'  => (int) soma de sessionsNumber do grupo inteiro,
 *       'l2'     => [ '<l2 name>' => [
 *           'identity' => [ '<col>' => '<valor>' , ... ],
 *           'total'    => (int) soma de sessionsNumber do bloco l2,
 *           'leaves'   => [ '<teatro|tipo|ano>' => [
 *               'theater' =>, 'kind' =>, 'year' =>, 'sessions' => (int),
 *           ] ],
 *       ] ],
 *   ] ]
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Grouping;

use TeatroMusicadoSP\Customizations\Presentations\Rendering\PresentationValue;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationGrouper
{
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    public function build( array $rows, GroupingMode $mode ): array {
        $tree = [];

        foreach ( $rows as $row ) {
            $l1_name  = PresentationValue::non_empty( $row[ $mode->l1 ] ?? '' );
            $l2_name  = PresentationValue::non_empty( $row[ $mode->l2 ] ?? '' );
            $theater  = PresentationValue::non_empty( $row['theaterName'] ?? '' );
            $kind     = PresentationValue::non_empty( $row['settingKind'] ?? '' );
            $year     = PresentationValue::year_of( $row['presentationDate'] ?? null );
            $sessions = (int) ( $row['sessionsNumber'] ?? 0 );

            if ( ! isset( $tree[ $l1_name ] ) ) {
                $parts = [];
                foreach ( $mode->l1_label_fields as $label => $field ) {
                    $parts[] = $label . ': ' . PresentationValue::non_empty( $row[ $field ] ?? '' );
                }
                $tree[ $l1_name ] = [
                    'label' => implode( ' - ', $parts ),
                    'total' => 0,
                    'l2'    => [],
                ];
            }
            $tree[ $l1_name ]['total'] += $sessions;

            if ( ! isset( $tree[ $l1_name ]['l2'][ $l2_name ] ) ) {
                $identity = [];
                foreach ( array_keys( $mode->identity ) as $field ) {
                    $identity[ $field ] = trim( (string) ( $row[ $field ] ?? '' ) );
                }
                $tree[ $l1_name ]['l2'][ $l2_name ] = [
                    'identity' => $identity,
                    'total'    => 0,
                    'leaves'   => [],
                ];
            }
            $tree[ $l1_name ]['l2'][ $l2_name ]['total'] += $sessions;

            $leaf_key = $theater . '|' . $kind . '|' . $year;
            if ( ! isset( $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ] ) ) {
                $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ] = [
                    'theater'  => $theater,
                    'kind'     => $kind,
                    'year'     => $year,
                    'sessions' => 0,
                ];
            }
            $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ]['sessions'] += $sessions;
        }

        uksort( $tree, static fn( $a, $b ): int => strnatcasecmp( (string) $a, (string) $b ) );
        foreach ( $tree as &$l1 ) {
            uksort( $l1['l2'], static fn( $a, $b ): int => strnatcasecmp( (string) $a, (string) $b ) );
            foreach ( $l1['l2'] as &$l2 ) {
                uasort(
                    $l2['leaves'],
                    static function ( array $a, array $b ): int {
                        return strnatcasecmp( $a['theater'], $b['theater'] )
                            ?: strnatcasecmp( $a['kind'], $b['kind'] )
                            ?: strnatcasecmp( (string) $a['year'], (string) $b['year'] );
                    }
                );
            }
            unset( $l2 );
        }
        unset( $l1 );

        return $tree;
    }
}
