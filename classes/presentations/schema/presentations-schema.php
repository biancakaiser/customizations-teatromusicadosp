<?php
/**
 * Fonte única de verdade das colunas da tabela `{$wpdb->prefix}teatro_presentations`.
 *
 * Antes, a lista de colunas vivia duplicada em `PresentationsRepository::COLUMNS`
 * (12 colunas, autoridade do banco) e em `PresentationsShortcode::COLUMNS_FLAT`
 * (10 colunas, subconjunto de exibição). Este registry unifica as duas visões:
 *
 *   - `labels()`      reproduz exatamente o antigo `Repository::COLUMNS`
 *                     (mantém o header REST `X-TMSP-Columns` idêntico);
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
        return array_map( static fn( PresentationColumn $c ): string => $c->label, self::columns() );
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
     * (idêntico ao antigo `Shortcode::COLUMNS_FLAT`).
     *
     * @return array<string,string>
     */
    public static function flat_columns(): array {
        $flat = array_filter(
            self::columns(),
            static fn( PresentationColumn $c ): bool => null !== $c->flat_label
        );

        uasort(
            $flat,
            static fn( PresentationColumn $a, PresentationColumn $b ): int => $a->flat_order <=> $b->flat_order
        );

        return array_map( static fn( PresentationColumn $c ): string => (string) $c->flat_label, $flat );
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

        // key, label, type, indexed, filterable, facetable, flat_label, flat_order, searchable
        $defs = [
            [ 'presentationDate',   'Data da apresentação',        $d, false, false, false, 'Data',                        1 ],
            [ 'sessionsNumber',     'Nº de sessões',               $i, false, false, false, 'Nº de Sessões',               10 ],
            [ 'settingYear',        'Ano da temporada',            $i, true,  true,  true,  null,                          0 ],
            [ 'settingLanguage',    'Idioma da temporada',         $s, false, true,  true,  null,                          0 ],
            [ 'settingKind',        'Tipo de temporada',           $s, false, true,  true,  'Tipo de Espetáculo',          9 ],
            [ 'playName',           'Peça',                        $s, true,  true,  true,  'Título da Peça',              4, true ],
            [ 'genre',              'Gênero',                      $s, false, true,  true,  'Gênero',                      5 ],
            [ 'playLanguage',       'Idioma da peça',              $s, false, true,  true,  'Idioma',                      7 ],
            [ 'playNationality',    'Nacionalidade da peça',       $s, false, true,  true,  'Nacionalidade',               6 ],
            [ 'companyName',        'Companhia',                   $s, true,  true,  true,  'Nome da Companhia',           2, true ],
            [ 'companyNationality', 'Nacionalidade da companhia',  $s, false, true,  true,  'Nacionalidade da Companhia',  3 ],
            [ 'theaterName',        'Teatro',                      $s, true,  true,  true,  'Teatro',                      8 ],
        ];

        $columns = [];
        foreach ( $defs as $def ) {
            $columns[ $def[0] ] = new PresentationColumn(
                $def[0], $def[1], $def[2], $def[3], $def[4], $def[5], $def[6], $def[7], $def[8] ?? false
            );
        }

        return $columns;
    }
}
