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
    /**
     * Layout da tabela plana (modo "Nenhum"): `rótulo do grupo de cabeçalho =>
     * colunas`, na ordem de exibição. Só afeta `flat_columns()`/
     * `flat_column_groups()` — a ordem e o `$group` de `columns()` (banco, busca,
     * modos agrupados, demais consumidores) não mudam.
     */
    const FLAT_LAYOUT = [
        'Apresentação' => [ 'presentationDate', 'presentationTheater' ],
        'Companhia'    => [ 'companyName', 'companyNationality' ],
        'Peça'         => [ 'playName', 'playGenre', 'playNationality' ],
        'Espetáculo'   => [ 'presentationLanguage', 'presentationKind', 'presentationSessionsN' ],
    ];

    /**
     * Rótulo da contagem da faixa de 1º nível nos modos de 2 níveis, que contam
     * linhas (espetáculos) — não há coluna "Espetáculo" com `count_label`.
     */
    const ROW_COUNT_LABEL = 'Nº Espetáculos';

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
        return array_map( static fn( PresentationColumn $c ): string => $c->filters_label, self::columns() );
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
     * (idêntico ao antigo `Shortcode::COLUMNS_FLAT`). Usa o `filters_label` — os
     * consumidores deste método precisam de contexto (`data-label` das células
     * em telas estreitas). Para o texto do cabeçalho da tabela plana
     * (`header_label`), ver `flat_column_groups()`.
     *
     * @return array<string,string>
     */
    public static function flat_columns(): array {
        $columns = [];

        foreach ( self::flat_column_groups() as $members ) {
            foreach ( $members as $key => $col ) {
                $columns[ $key ] = $col['filters_label'];
            }
        }

        return $columns;
    }

    /**
     * Cabeçalho de 2 linhas da tabela plana, na ordem de `FLAT_LAYOUT`. Cada
     * grupo com mais de uma coluna vira um `<th colspan>` na 1ª linha e um
     * `header_label` por coluna na 2ª; um grupo com uma única coluna vira um
     * `<th rowspan="2">` sozinho, sem linha de baixo.
     *
     * Uma coluna de `columns()` ausente de `FLAT_LAYOUT` entra no fim, sob o
     * próprio `$group` — assim uma coluna nova não some da tabela.
     *
     * @return array<string,array<string,array{header_label:string,filters_label:string}>>
     */
    public static function flat_column_groups(): array {
        $groups = [];
        $placed = [];

        foreach ( self::FLAT_LAYOUT as $group => $keys ) {
            foreach ( $keys as $key ) {
                $c = self::column( $key );
                if ( null === $c ) {
                    continue;
                }
                $groups[ $group ][ $key ] = [
                    'header_label'  => $c->header_label,
                    'filters_label' => $c->filters_label,
                ];
                $placed[ $key ] = true;
            }
        }

        foreach ( array_diff_key( self::columns(), $placed ) as $key => $c ) {
            $groups[ $c->group ][ $key ] = [
                'header_label'  => $c->header_label,
                'filters_label' => $c->filters_label,
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
     * Colunas técnicas da sincronização com o Tainacan — ficam de propósito
     * FORA de `columns()`: não são exibidas, não entram na busca livre (LIKE)
     * nem nos filtros. Servem para upsert (`espetaculo_id`), identidade entre
     * ambientes (`legacy_id`, o Slug ID), propagação de mudanças em Peça/
     * Companhia/Teatro (`*_id`) e conferência (`source_hash`).
     *
     * `espetaculo_id` aceita NULL só para as linhas do seed mock (CSV), usado
     * como rollback; toda linha vinda do Tainacan o preenche.
     *
     * @return array<string,string> coluna => definição SQL
     */
    public static function technical_column_definitions(): array {
        return [
            'espetaculo_id' => '`espetaculo_id` BIGINT UNSIGNED NULL',
            'legacy_id'     => '`legacy_id` BIGINT UNSIGNED NULL',
            'play_id'       => '`play_id` BIGINT UNSIGNED NULL',
            'company_id'    => '`company_id` BIGINT UNSIGNED NULL',
            'theater_id'    => '`theater_id` BIGINT UNSIGNED NULL',
            'source_hash'   => '`source_hash` CHAR(32) NULL',
            'synced_at'     => '`synced_at` DATETIME NULL',
        ];
    }

    /**
     * Índices das colunas técnicas (sintaxe aceita pelo `dbDelta`).
     *
     * `legacy_id` não é UNIQUE de propósito: o Slug ID é digitado à mão, e um
     * valor repetido faria o `INSERT … ON DUPLICATE KEY UPDATE` (chaveado por
     * `espetaculo_id`) sobrescrever a linha de outro espetáculo. Duplicatas são
     * apontadas pela conferência (`wp tmsp presentations verify`).
     *
     * @return list<string>
     */
    public static function technical_key_definitions(): array {
        return [
            'UNIQUE KEY espetaculo_id (espetaculo_id)',
            'KEY legacy_id (legacy_id)',
            'KEY play_id (play_id)',
            'KEY company_id (company_id)',
            'KEY theater_id (theater_id)',
        ];
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
        // Cada coluna: key => [ filters_label, header_label, grouped_label, combined_label?,
        // count_label?, type, indexed?, filterable?, facetable?, searchable?, acronym? ]
        // (ver `PresentationColumn`). Flags omitidas valem false; rótulos opcionais, null.
        $defs = [
            'Peça' => [
                'playName' => [
                    'filters_label' => 'Título da Peça',
                    'header_label'  => 'Título',
                    'grouped_label' => 'Peça',
                    'count_label'   => 'Nº Peças',
                    'type'          => $s,
                    'indexed'       => true,
                    'filterable'    => true,
                    'facetable'     => true,
                    'searchable'    => true,
                ],
                'playGenre' => [
                    'filters_label' => 'Gênero da Peça',
                    'header_label'  => 'Gênero',
                    'grouped_label' => 'Gênero',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                ],
                'playNationality' => [
                    'filters_label' => 'Nacionalidade da Peça',
                    'header_label'  => 'Nacionalidade',
                    'grouped_label' => 'Nacionalidade',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                    'acronym'       => true,
                ],
            ],
            'Companhia' => [
                'companyName' => [
                    'filters_label' => 'Nome da Companhia',
                    'header_label'  => 'Nome',
                    'grouped_label' => 'Companhia',
                    'count_label'   => 'Nº Companhias',
                    'type'          => $s,
                    'indexed'       => true,
                    'filterable'    => true,
                    'facetable'     => true,
                    'searchable'    => true,
                ],
                'companyNationality' => [
                    'filters_label' => 'Nacionalidade da Companhia',
                    'header_label'  => 'Nacionalidade',
                    'grouped_label' => 'Nacionalidade',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                    'acronym'       => true,
                ],
            ],
            'Espetáculo' => [
                'presentationTheater' => [
                    'filters_label' => 'Teatro',
                    'header_label'  => 'Teatro',
                    'grouped_label' => 'Teatro',
                    'type'          => $s,
                    'indexed'       => true,
                    'filterable'    => true,
                    'facetable'     => true,
                ],
                'presentationKind' => [
                    'filters_label' => 'Tipo de Espetáculo',
                    'header_label'  => 'Tipo',
                    'grouped_label' => 'Tipo',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                ],
                'presentationLanguage' => [
                    'filters_label' => 'Idioma do Espetáculo',
                    'header_label'  => 'Idioma',
                    'grouped_label' => 'Idioma',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                    'acronym'       => true,
                ],
                'presentationDate' => [
                    'filters_label'  => 'Data da apresentação',
                    'header_label'   => 'Data',
                    'grouped_label'  => 'Data',
                    'combined_label' => 'Ano',
                    'type'           => $d,
                    'indexed'        => true,
                ],
                'presentationSessionsN' => [
                    'filters_label'  => 'Sessões',
                    'header_label'   => 'Sessões',
                    'grouped_label'  => 'Sessões',
                    'combined_label' => 'Total',
                    'type'           => $i,
                ],
            ],
        ];

        $columns = [];
        foreach ( $defs as $group => $group_defs ) {
            foreach ( $group_defs as $key => $def ) {
                $columns[ $key ] = new PresentationColumn(
                    key: $key,
                    filters_label: $def['filters_label'],
                    header_label: $def['header_label'],
                    grouped_label: $def['grouped_label'],
                    group: $group,
                    type: $def['type'],
                    indexed: $def['indexed'] ?? false,
                    filterable: $def['filterable'] ?? false,
                    facetable: $def['facetable'] ?? false,
                    searchable: $def['searchable'] ?? false,
                    acronym: $def['acronym'] ?? false,
                    combined_label: $def['combined_label'] ?? null,
                    count_label: $def['count_label'] ?? null
                );
            }
        }

        return $columns;
    }
}
