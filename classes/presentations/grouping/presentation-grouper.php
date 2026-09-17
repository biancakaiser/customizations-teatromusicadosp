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
 *       'total'  => (int) soma de presentationSessionsN do grupo inteiro,
 *       'sort'   => [ '<col>' => '<valor>' , ... ]  (campos da faixa de 1º nível),
 *       'l2'     => [ '<l2 name>' => [
 *           'identity' => [ '<col>' => '<valor>' , ... ],
 *           'total'    => (int) soma de presentationSessionsN do bloco l2,
 *           'leaves'   => [ '<teatro|tipo|idioma|ano>' => [
 *               'theater' =>, 'kind' =>, 'language' =>, 'year' =>, 'sessions' => (int),
 *           ] ],
 *       ] ],
 *   ] ]
 *
 * A ordenação default (sem `$sort`) é natural/alfabética em todos os níveis.
 * Passando um `$sort` (`['orderby' => <coluna>, 'order' => 'ASC'|'DESC']`):
 *   - se a coluna pertence à faixa de 1º nível ou ao bloco de identidade de
 *     2º nível, esse nível é ordenado por ela (os demais mantêm o default);
 *   - se a coluna é de linha-folha (`LEAF_FIELDS`), as faixas de 1º e 2º nível
 *     NÃO são reordenadas por um comparator próprio — elas mantêm a ordem de
 *     inserção da árvore, que é construída a partir de `$rows` na ordem em
 *     que chegam. Isso só produz a ordenação correta dos grupos porque os dois
 *     chamadores (`PresentationsShortcode::render()` e
 *     `PresentationsRepository::rest_get_presentations_grouped()`) sempre
 *     buscam `$rows` via `query_all_presentations()` com um `ORDER BY` SQL no
 *     mesmo `orderby`/`order` aqui recebido — logo a 1ª linha de cada grupo
 *     encontrada na iteração já é o valor mínimo (ASC) ou máximo (DESC) do
 *     grupo para essa coluna. Se `build()` passar a ser chamado com `$rows`
 *     fora dessa ordem, esse comportamento quebra silenciosamente.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Grouping;

use TeatroMusicadoSP\Customizations\Presentations\Rendering\PresentationValue;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationGrouper
{
    /** Coluna do schema => chave no array da linha folha. */
    const LEAF_FIELDS = [
        'presentationTheater'   => 'theater',
        'presentationKind'      => 'kind',
        'presentationLanguage'  => 'language',
        'presentationDate'      => 'year',
        'presentationSessionsN' => 'sessions',
    ];

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array{orderby?:string,order?:string} $sort
     * @return array<string,array<string,mixed>>
     */
    public function build( array $rows, GroupingMode $mode, array $sort = [] ): array {
        $orderby = (string) ( $sort['orderby'] ?? '' );
        $order   = 'DESC' === strtoupper( (string) ( $sort['order'] ?? 'ASC' ) ) ? 'DESC' : 'ASC';
        $dir     = 'DESC' === $order ? -1 : 1;

        $tree = [];

        foreach ( $rows as $row ) {
            $l1_name  = PresentationValue::non_empty( $row[ $mode->l1_key ] ?? '' );
            $l2_name  = PresentationValue::non_empty( $row[ $mode->l2_key ] ?? '' );
            $theater  = PresentationValue::non_empty( $row['presentationTheater'] ?? '' );
            $kind     = PresentationValue::non_empty( $row['presentationKind'] ?? '' );
            $language = PresentationValue::non_empty( $row['presentationLanguage'] ?? '' );
            $year     = PresentationValue::year_of( $row['presentationDate'] ?? null );
            $sessions = (int) ( $row['presentationSessionsN'] ?? 0 );

            if ( ! isset( $tree[ $l1_name ] ) ) {
                $parts     = [];
                $l1_sort   = [];
                $l1_labels = $mode->labels_for( $mode->l1_label_fields );
                foreach ( $mode->l1_label_fields as $field ) {
                    $raw               = $row[ $field ] ?? '';
                    $parts[]           = $l1_labels[ $field ] . ': ' . ( PresentationsSchema::is_acronym( $field ) ? PresentationValue::acronym( $raw ) : PresentationValue::non_empty( $raw ) );
                    $l1_sort[ $field ] = trim( (string) $raw );
                }
                $l1_sort[ $mode->l1_key ] = $l1_name;
                $tree[ $l1_name ] = [
                    'label' => implode( ' - ', $parts ),
                    'total' => 0,
                    'sort'  => $l1_sort,
                    'l2'    => [],
                ];
            }
            $tree[ $l1_name ]['total'] += $sessions;

            if ( ! isset( $tree[ $l1_name ]['l2'][ $l2_name ] ) ) {
                $identity = [];
                foreach ( $mode->identity as $field ) {
                    $identity[ $field ] = trim( (string) ( $row[ $field ] ?? '' ) );
                }
                $tree[ $l1_name ]['l2'][ $l2_name ] = [
                    'identity' => $identity,
                    'total'    => 0,
                    'leaves'   => [],
                ];
            }
            $tree[ $l1_name ]['l2'][ $l2_name ]['total'] += $sessions;

            $leaf_key = $theater . '|' . $kind . '|' . $language . '|' . $year;
            if ( ! isset( $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ] ) ) {
                $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ] = [
                    'theater'  => $theater,
                    'kind'     => $kind,
                    'language' => $language,
                    'year'     => $year,
                    'sessions' => 0,
                ];
            }
            $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ]['sessions'] += $sessions;
        }

        $level = $this->sort_level( $orderby, $mode );

        // Nível 1 (faixas do grupo).
        if ( 'l1' === $level ) {
            uasort( $tree, $this->l1_comparator( $orderby, $mode, $dir ) );
        } elseif ( '' === $level ) {
            uksort( $tree, static fn( $a, $b ): int => strnatcasecmp( (string) $a, (string) $b ) );
        }
        // 'leaf': mantém a ordem de inserção, já ordenada pelo ORDER BY da consulta (ver docblock da classe).

        foreach ( $tree as &$l1 ) {
            // Nível 2 (blocos de identidade).
            if ( 'l2' === $level ) {
                uasort( $l1['l2'], $this->l2_comparator( $orderby, $mode, $dir ) );
            } elseif ( '' === $level ) {
                uksort( $l1['l2'], static fn( $a, $b ): int => strnatcasecmp( (string) $a, (string) $b ) );
            }
            // 'leaf': idem, mantém a ordem de inserção.

            foreach ( $l1['l2'] as &$l2 ) {
                // Linhas folha.
                if ( 'leaf' === $level ) {
                    uasort( $l2['leaves'], $this->leaf_comparator( $orderby, $dir ) );
                } else {
                    uasort(
                        $l2['leaves'],
                        static function ( array $a, array $b ): int {
                            return strnatcasecmp( $a['theater'], $b['theater'] )
                                ?: strnatcasecmp( $a['kind'], $b['kind'] )
                                ?: strnatcasecmp( $a['language'], $b['language'] )
                                ?: strnatcasecmp( (string) $a['year'], (string) $b['year'] );
                        }
                    );
                }
            }
            unset( $l2 );
        }
        unset( $l1 );

        return $tree;
    }

    /**
     * Em que nível da árvore a coluna pedida vive: 'l1', 'l2', 'leaf' ou '' (default).
     */
    private function sort_level( string $orderby, GroupingMode $mode ): string {
        if ( '' === $orderby ) {
            return '';
        }
        if ( isset( self::LEAF_FIELDS[ $orderby ] ) ) {
            return 'leaf';
        }
        if ( GroupingMode::SORT_TOTAL === $orderby
            || $orderby === $mode->l1_key
            || in_array( $orderby, $mode->l1_label_fields, true )
        ) {
            return 'l1';
        }
        if ( $orderby === $mode->l2_key || in_array( $orderby, $mode->identity, true ) ) {
            return 'l2';
        }
        return '';
    }

    private function l1_comparator( string $orderby, GroupingMode $mode, int $dir ): callable {
        if ( GroupingMode::SORT_TOTAL === $orderby ) {
            return static fn( array $a, array $b ): int => $dir * ( $a['total'] <=> $b['total'] );
        }
        return static function ( array $a, array $b ) use ( $orderby, $mode, $dir ): int {
            $va = (string) ( $a['sort'][ $orderby ] ?? '' );
            $vb = (string) ( $b['sort'][ $orderby ] ?? '' );
            return $dir * strnatcasecmp( $va, $vb )
                ?: strnatcasecmp( (string) ( $a['sort'][ $mode->l1_key ] ?? '' ), (string) ( $b['sort'][ $mode->l1_key ] ?? '' ) );
        };
    }

    private function l2_comparator( string $orderby, GroupingMode $mode, int $dir ): callable {
        return static function ( array $a, array $b ) use ( $orderby, $mode, $dir ): int {
            $va = (string) ( $a['identity'][ $orderby ] ?? '' );
            $vb = (string) ( $b['identity'][ $orderby ] ?? '' );
            return $dir * strnatcasecmp( $va, $vb )
                ?: strnatcasecmp(
                    (string) ( $a['identity'][ $mode->l2_key ] ?? '' ),
                    (string) ( $b['identity'][ $mode->l2_key ] ?? '' )
                );
        };
    }

    private function leaf_comparator( string $orderby, int $dir ): callable {
        $field   = self::LEAF_FIELDS[ $orderby ];
        $numeric = in_array( $field, [ 'year', 'sessions' ], true );

        return static function ( array $a, array $b ) use ( $field, $numeric, $dir ): int {
            $primary = $numeric
                ? ( (int) $a[ $field ] <=> (int) $b[ $field ] )
                : strnatcasecmp( (string) $a[ $field ], (string) $b[ $field ] );

            return $dir * $primary
                ?: strnatcasecmp( $a['theater'], $b['theater'] )
                ?: strnatcasecmp( $a['kind'], $b['kind'] )
                ?: strnatcasecmp( $a['language'], $b['language'] )
                ?: strnatcasecmp( (string) $a['year'], (string) $b['year'] );
        };
    }
}
