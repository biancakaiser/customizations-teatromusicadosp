<?php
/**
 * Fonte única de verdade das colunas da tabela `{$wpdb->prefix}teatro_presentations`.
 *
 * Antes, a lista de colunas vivia duplicada em `PresentationsRepository::COLUMNS`
 * (12 colunas, autoridade do banco) e em `PresentationsShortcode::COLUMNS_FLAT`
 * (10 colunas, subconjunto de exibição). Este registry unifica as duas visões:
 *
 *   - `labels()`      reproduz exatamente o antigo `Repository::COLUMNS`;
 *   - `flat_columns()` reproduz exatamente o antigo `Shortcode::COLUMNS_FLAT`;
 *   - `sql_column_definitions()` gera o mesmo texto do `CREATE TABLE` de hoje.
 *
 * Também marca quais colunas aceitam filtro de igualdade (`facetable`) — usado
 * pela nova UI de `<select>` populada por `SELECT DISTINCT`.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Schema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationsSchema
{
    /** @var array<string,PresentationColumn>|null */
    private static $columns = null;

    /**
     * Todas as colunas, na ordem do banco (== ordem de exibição/seed original).
     *
     * @return array<string,PresentationColumn>
     */
    public static function columns(): array {
        if ( null === self::$columns ) {
            self::$columns = self::build();
        }
        return self::$columns;
    }

    public static function column( string $key ): ?PresentationColumn {
        return self::columns()[ $key ] ?? null;
    }

    public static function has( string $key ): bool {
        return isset( self::columns()[ $key ] );
    }

    /**
     * Nomes de todas as colunas — o conjunto varrido pela busca livre (LIKE).
     *
     * @return list<string>
     */
    public static function keys(): array {
        return array_keys( self::columns() );
    }

    /**
     * Mapa `coluna => rótulo canônico` (idêntico ao antigo `Repository::COLUMNS`).
     *
     * @return array<string,string>
     */
    public static function labels(): array {
        return array_map( static fn( PresentationColumn $c ): string => $c->full_label, self::columns() );
    }

    /**
     * Colunas que podem aparecer no `ORDER BY` da rota REST.
     *
     * @return list<string>
     */
    public static function orderable_keys(): array {
        return array_merge( self::keys(), [ 'id' ] );
    }

    public static function is_orderable( string $key ): bool {
        return in_array( $key, self::orderable_keys(), true );
    }

    /**
     * Mapa `coluna => rótulo` da tabela plana do shortcode, na ordem de exibição
     * (idêntico ao antigo `Shortcode::COLUMNS_FLAT`). Usa o rótulo completo — os
     * consumidores deste método são `<select>`s e outros lugares que precisam de
     * contexto (ordenação, `data-label` das células em telas estreitas). Para o
     * cabeçalho agrupado (curto) da tabela plana, ver `flat_column_groups()`.
     *
     * @return array<string,string>
     */
    public static function flat_columns(): array {
        return array_map(
            static fn( PresentationColumn $c ): string => $c->full_label,
            self::columns()
        );
    }

    /**
     * Cabeçalho de 2 linhas da tabela plana, agrupado por `$group` na ordem de
     * `columns()` (colunas do mesmo grupo já são contíguas — `$defs` é aninhado
     * por grupo, ver `build()`). Cada grupo com mais de uma coluna vira um
     * `<th colspan>` na 1ª linha e um `short_label` por coluna na 2ª; um grupo
     * com uma única coluna vira um `<th rowspan="2">` sozinho, sem linha de baixo.
     *
     * @return array<string,array<string,array{short_label:string,full_label:string}>>
     */
    public static function flat_column_groups(): array {
        $groups = [];

        foreach ( self::columns() as $key => $c ) {
            $groups[ $c->group ][ $key ] = [
                'short_label' => $c->short_label,
                'full_label'  => $c->full_label,
            ];
        }

        return $groups;
    }

    /**
     * Colunas que ganham `<select>` de valores distintos.
     *
     * @return array<string,PresentationColumn>
     */
    public static function facetable_columns(): array {
        return array_filter(
            self::columns(),
            static fn( PresentationColumn $c ): bool => $c->facetable
        );
    }

    public static function is_facetable( string $key ): bool {
        $column = self::column( $key );
        return null !== $column && $column->facetable;
    }

    /**
     * A coluna deve exibir só a sigla do valor nas tabelas de resultado
     * (ver `PresentationColumn::$acronym` / `PresentationValue::acronym()`).
     */
    public static function is_acronym( string $key ): bool {
        $column = self::column( $key );
        return null !== $column && $column->acronym;
    }

    /**
     * Colunas facetáveis na ordem de exibição do formulário de filtros: primeiro
     * as com busca interna (`searchable`), depois as demais.
     *
     * @return array<string,PresentationColumn>
     */
    public static function filter_columns_ordered(): array {
        $facetable  = self::facetable_columns();
        $searchable = array_filter( $facetable, static fn( PresentationColumn $c ): bool => $c->searchable );
        $plain      = array_filter( $facetable, static fn( PresentationColumn $c ): bool => ! $c->searchable );

        return $searchable + $plain;
    }

    /**
     * @return list<string>
     */
    public static function indexed_keys(): array {
        return array_keys(
            array_filter(
                self::columns(),
                static fn( PresentationColumn $c ): bool => $c->indexed
            )
        );
    }

    /**
     * Definições de coluna para o `CREATE TABLE`, na ordem do banco.
     *
     * @return list<string>
     */
    public static function sql_column_definitions(): array {
        return array_values(
            array_map(
                static fn( PresentationColumn $c ): string => $c->sql_definition(),
                self::columns()
            )
        );
    }

    /**
     * @return array<string,PresentationColumn>
     */
    private static function build(): array {
        $s = PresentationColumn::TYPE_STRING;
        $i = PresentationColumn::TYPE_INT;
        $d = PresentationColumn::TYPE_DATE;

        // Aninhado por grupo: a chave externa é o `$group` exibido no cabeçalho da
        // tabela plana (colspan comum a todas as colunas dentro dela) e a ordem de
        // inserção — dos grupos entre si e das colunas dentro de cada grupo — é a
        // própria ordem de exibição. Não existe campo de ordenação numérica: mover
        // uma linha ou um bloco inteiro aqui já move a coluna/grupo na tabela.
        //
        // Cada coluna: key => [ full_label, short_label, type, indexed, filterable, facetable, searchable, acronym ]
        $defs = [
            'Peça' => [
                'playName'        => [ 'Título da Peça', 'Peça', $s, true, true, true, true ],
                'playGenre'       => [ 'Gênero da Peça', 'Gênero', $s, false, true, true ],
                'playNationality' => [ 'Nacionalidade da Peça', 'Nacionalidade', $s, false, true, true, false, true ],
            ],
            'Companhia' => [
                'companyName'        => [ 'Nome da Companhia', 'Companhia', $s, true, true, true, true ],
                'companyNationality' => [ 'Nacionalidade da Companhia', 'Nacionalidade', $s, false, true, true, false, true ],
            ],
            'Espetáculo' => [
                'presentationTheater'   => [ 'Teatro', 'Teatro', $s, true, true, true ],
                'presentationKind'      => [ 'Tipo de Espetáculo', 'Tipo', $s, false, true, true ],
                'presentationLanguage'  => [ 'Idioma do Espetáculo', 'Idioma', $s, false, true, true, false, true ],
                'presentationDate'      => [ 'Data da apresentação', 'Data', $d, false, false, false ],
                'presentationSessionsN' => [ 'Nº de Sessões', 'Nº de Sessões', $i, false, false, false ],
            ],
        ];

        $columns = [];
        foreach ( $defs as $group => $group_defs ) {
            foreach ( $group_defs as $key => $def ) {
                $columns[ $key ] = new PresentationColumn(
                    $key, $def[0], $def[1], $group, $def[2], $def[3] ?? false, $def[4] ?? false, $def[5] ?? false, $def[6] ?? false, $def[7] ?? false
                );
            }
        }

        return $columns;
    }
}
