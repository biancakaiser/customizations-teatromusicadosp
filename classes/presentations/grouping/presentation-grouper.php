<?php
/**
 * Monta a árvore de agrupamento para um `GroupingMode`.
 *
 * Movido do antigo `PresentationsShortcode::build_groups()`.
 *
 * Estrutura devolvida (por nível 1):
 *   [ '<l1 name>' => [
 *       'label'  => 'Companhia: X - Nacionalidade: Y',
 *       'total'  => (int) soma de presentationSessionsN do grupo inteiro,
 *       'sort'   => [ '<col>' => '<valor>' , ... ]  (campos da faixa de 1º nível),
 *       'l2'     => [ '<l2 name>' => [
 *           'identity' => [ '<col>' => '<valor>' , ... ],
 *           'total'    => (int) soma de presentationSessionsN do bloco l2,
 *           'leaves'   => [ '<valores das leaf_columns, unidos por |>' => [
 *               '<campo>' => ..., (só os de `leaf_columns`, ver `field_for()`:
 *                             theater|kind|language|year nos modos de 3 níveis;
 *                             também play|genre|play_nationality|company|
 *                             company_nationality nos de 2 níveis)
 *               'sessions' => (int) soma das sessões dessa combinação,
 *           ] ],
 *       ] ],
 *   ] ]
 *
 * Nos modos de 2 níveis (`GroupingMode::has_identity_level()` falso) cada faixa
 * tem um único bloco l2 sintético (chave `''`, `identity` vazio): as folhas
 * ficam logo abaixo da faixa e o resto (paginação, ordenação, renderização)
 * trata os dois formatos da mesma forma.
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
     * Colunas de Peça/Companhia que também podem virar campo da linha folha —
     * nos modos de 2 níveis cada linha repete esses dados, como na tabela plana.
     * Separadas de `LEAF_FIELDS` porque este também decide o nível de ordenação
     * (`sort_level()`), e essas colunas não são ordenáveis na folha.
     */
    const ROW_FIELDS = [
        'playName'           => 'play',
        'playGenre'          => 'genre',
        'playNationality'    => 'play_nationality',
        'companyName'        => 'company',
        'companyNationality' => 'company_nationality',
    ];

    /**
     * Chave, no array da linha folha, de uma coluna de `GroupingMode::leaf_columns`.
     */
    public static function field_for( string $column ): string {
        return self::ROW_FIELDS[ $column ] ?? self::LEAF_FIELDS[ $column ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array{orderby?:string,order?:string} $sort
     * @return array<string,array<string,mixed>>
     */
    public function build( array $rows, GroupingMode $mode, array $sort = [] ): array {
        $orderby = (string) ( $sort['orderby'] ?? '' );
        $order   = 'DESC' === strtoupper( (string) ( $sort['order'] ?? 'ASC' ) ) ? 'DESC' : 'ASC';
        $dir     = 'DESC' === $order ? -1 : 1;

        $tree        = [];
        $leaf_fields = $this->leaf_fields( $mode );

        foreach ( $rows as $row ) {
            $l1_name  = PresentationValue::non_empty( $row[ $mode->l1_key ] ?? '' );
            $l2_name  = $mode->has_identity_level()
                ? PresentationValue::non_empty( $row[ $mode->l2_key ] ?? '' )
                : '';
            $sessions = (int) ( $row['presentationSessionsN'] ?? 0 );

            $leaf = [];
            foreach ( $leaf_fields as $column => $field ) {
                $leaf[ $field ] = 'year' === $field
                    ? PresentationValue::year_of( $row[ $column ] ?? null )
                    : PresentationValue::non_empty( $row[ $column ] ?? '' );
            }

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
                    'label' => implode( ' | ', $parts ),
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

            $leaf_key = implode( '|', $leaf );
            if ( ! isset( $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ] ) ) {
                $tree[ $l1_name ]['l2'][ $l2_name ]['leaves'][ $leaf_key ] = $leaf + [ 'sessions' => 0 ];
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
                    uasort( $l2['leaves'], $this->leaf_comparator( $orderby, $dir, $leaf_fields ) );
                } else {
                    uasort(
                        $l2['leaves'],
                        static fn( array $a, array $b ): int => self::compare_leaf_fields( $a, $b, $leaf_fields )
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
        $l2_key = (string) $mode->l2_key;

        return static function ( array $a, array $b ) use ( $orderby, $l2_key, $dir ): int {
            $va = (string) ( $a['identity'][ $orderby ] ?? '' );
            $vb = (string) ( $b['identity'][ $orderby ] ?? '' );
            return $dir * strnatcasecmp( $va, $vb )
                ?: strnatcasecmp(
                    (string) ( $a['identity'][ $l2_key ] ?? '' ),
                    (string) ( $b['identity'][ $l2_key ] ?? '' )
                );
        };
    }

    /**
     * @param array<string,string> $leaf_fields Ver `leaf_fields()`.
     */
    private function leaf_comparator( string $orderby, int $dir, array $leaf_fields ): callable {
        $field   = self::LEAF_FIELDS[ $orderby ];
        $numeric = in_array( $field, [ 'year', 'sessions' ], true );

        return static function ( array $a, array $b ) use ( $field, $numeric, $dir, $leaf_fields ): int {
            $primary = $numeric
                ? ( (int) ( $a[ $field ] ?? 0 ) <=> (int) ( $b[ $field ] ?? 0 ) )
                : strnatcasecmp( (string) ( $a[ $field ] ?? '' ), (string) ( $b[ $field ] ?? '' ) );

            return $dir * $primary ?: self::compare_leaf_fields( $a, $b, $leaf_fields );
        };
    }

    /**
     * Ordem natural das folhas: compara campo a campo, na ordem das
     * `leaf_columns` do modo (ex.: teatro, tipo, idioma, ano).
     *
     * @param array<string,mixed>  $a
     * @param array<string,mixed>  $b
     * @param array<string,string> $leaf_fields
     */
    private static function compare_leaf_fields( array $a, array $b, array $leaf_fields ): int {
        foreach ( $leaf_fields as $field ) {
            $cmp = strnatcasecmp( (string) $a[ $field ], (string) $b[ $field ] );
            if ( 0 !== $cmp ) {
                return $cmp;
            }
        }
        return 0;
    }

    /**
     * Colunas-folha do modo, `coluna do schema => chave na folha` (ver `field_for()`).
     *
     * @return array<string,string>
     */
    private function leaf_fields( GroupingMode $mode ): array {
        $fields = [];
        foreach ( $mode->leaf_columns as $column ) {
            $fields[ $column ] = self::field_for( $column );
        }
        return $fields;
    }
}
