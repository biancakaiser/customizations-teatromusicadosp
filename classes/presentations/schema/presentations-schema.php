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

use TeatroMusicadoSP\Customizations\References\TainacanIds;

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
        'Peça'         => [ 'playName', 'playGenre', 'playNationality', 'playConception' ],
        'Espetáculo'   => [ 'presentationLanguage', 'presentationKind', 'presentationSessionsN', 'presentationAdaptation' ],
    ];

    /**
     * Cards de filtros do formulário: `título do card => colunas`, na ordem de
     * exibição. Os contextos de busca não são os `$group` do schema (Companhia
     * e Teatro entram em "Espetáculo"). Só afeta `filter_column_groups()`.
     */
    const FILTER_LAYOUT = [
        'Peça'       => [ 'playName', 'playGenre', 'playNationality', 'playMusicAuthors', 'playTextAuthors', 'playArrangers' ],
        'Espetáculo' => [ 'companyName', 'companyNationality', 'presentationLanguage', 'presentationTranslators', 'presentationAdapters', 'presentationTheater', 'presentationKind' ],
    ];

    /**
     * Rótulo da contagem da faixa de 1º nível nos modos de 2 níveis, que contam
     * linhas (espetáculos) — não há coluna "Espetáculo" com `count_label`.
     */
    const ROW_COUNT_LABEL = 'Nº Espetáculos';

    /**
     * Colunas exibidas como link para a ficha do item no Tainacan: `coluna =>
     * [ coleção, coluna técnica com o post_name do item ]` (ver
     * `TainacanIds::item_url()`). As colunas `multi_value` linkam para Pessoa
     * pela sua coluna oculta `links_key()`.
     */
    const ITEM_LINKS = [
        'playName'            => [ TainacanIds::PECA, 'play_slug' ],
        'companyName'         => [ TainacanIds::COMPANHIA, 'company_slug' ],
        'presentationTheater' => [ TainacanIds::TEATRO, 'theater_slug' ],
    ];

    /** @var array<string,PresentationColumn>|null */
    private static $columns = null;

    /**
     * Todas as colunas, na ordem do banco (== ordem de exibição/seed original),
     * incluindo as compostas (virtuais, só de exibição — ver `keys()`).
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
     * Nomes das colunas gravadas no banco (sem as compostas, que só existem na
     * exibição) — usado pelo writer, hash, seed CSV e ORDER BY.
     *
     * @return list<string>
     */
    public static function keys(): array {
        return array_keys(
            array_filter(
                self::columns(),
                static fn( PresentationColumn $c ): bool => ! $c->is_composite()
            )
        );
    }

    /**
     * A coluna é uma das que alguma coluna composta junta (ex.: `playTextAuthors`
     * em "Concepção")? Essas não são exibidas sozinhas.
     */
    public static function is_composite_part( string $key ): bool {
        foreach ( self::columns() as $column ) {
            if ( isset( $column->composite_of[ $key ] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Colunas varridas pela busca livre (LIKE): as gravadas menos as
     * `multi_value` (nomes de pessoas só entram pelos filtros dedicados).
     *
     * @return list<string>
     */
    public static function text_search_keys(): array {
        return array_values(
            array_filter(
                self::keys(),
                static fn( string $key ): bool => ! self::column( $key )->multi_value
            )
        );
    }

    /**
     * Mapa `coluna multi_value => coluna oculta com a lista JSON`.
     *
     * @return array<string,string>
     */
    public static function list_columns(): array {
        $lists = [];
        foreach ( self::columns() as $key => $column ) {
            if ( $column->multi_value ) {
                $lists[ $key ] = $column->list_key();
            }
        }
        return $lists;
    }

    /**
     * Mapa `coluna multi_value => coluna oculta com os links JSON das Pessoas`.
     *
     * @return array<string,string>
     */
    public static function link_columns(): array {
        $links = [];
        foreach ( self::columns() as $key => $column ) {
            if ( $column->multi_value ) {
                $links[ $key ] = $column->links_key();
            }
        }
        return $links;
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
     * próprio `$group` — assim uma coluna nova não some da tabela. As colunas
     * de pessoas ficam de fora: aparecem pelas compostas que as juntam.
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
            if ( self::is_composite_part( $key ) ) {
                continue;
            }
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
     * Colunas facetáveis agrupadas nos cards do formulário de filtros, na ordem
     * de `FILTER_LAYOUT`. Uma coluna facetável ausente do layout entra no fim,
     * sob o próprio `$group` — assim um filtro novo não some do formulário.
     *
     * @return array<string,array<string,PresentationColumn>>
     */
    public static function filter_column_groups(): array {
        $facetable = self::facetable_columns();
        $groups    = [];

        foreach ( self::FILTER_LAYOUT as $group => $keys ) {
            foreach ( $keys as $key ) {
                if ( isset( $facetable[ $key ] ) ) {
                    $groups[ $group ][ $key ] = $facetable[ $key ];
                    unset( $facetable[ $key ] );
                }
            }
        }

        foreach ( $facetable as $key => $c ) {
            $groups[ $c->group ][ $key ] = $c;
        }

        return $groups;
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
     * Definições de coluna para o `CREATE TABLE`, na ordem do banco; cada
     * coluna `multi_value` vem seguida da sua lista JSON e dos seus links ocultos.
     *
     * @return list<string>
     */
    public static function sql_column_definitions(): array {
        $definitions = [];
        foreach ( self::columns() as $column ) {
            if ( $column->is_composite() ) {
                continue;
            }
            $definitions[] = $column->sql_definition();
            if ( $column->multi_value ) {
                $definitions[] = $column->list_sql_definition();
                $definitions[] = $column->links_sql_definition();
            }
        }
        return $definitions;
    }

    /**
     * Colunas técnicas da sincronização com o Tainacan — ficam de propósito
     * FORA de `columns()`: não são exibidas, não entram na busca livre (LIKE)
     * nem nos filtros. Servem para upsert (`espetaculo_id`), identidade entre
     * ambientes (`legacy_id`, o Slug ID; `montagem_legacy_id`, o da Montagem),
     * propagação de mudanças em Montagem/Peça/Companhia/Teatro (`*_id`),
     * links para as fichas (`*_slug`, o `post_name` do item — ver `ITEM_LINKS`)
     * e conferência (`source_hash`).
     *
     * `espetaculo_id` aceita NULL só para as linhas do seed mock (CSV), usado
     * como rollback; toda linha vinda do Tainacan o preenche.
     *
     * @return array<string,string> coluna => definição SQL
     */
    public static function technical_column_definitions(): array {
        return [
            'espetaculo_id'      => '`espetaculo_id` BIGINT UNSIGNED NULL',
            'legacy_id'        => '`legacy_id` BIGINT UNSIGNED NULL',
            'montagem_id'        => '`montagem_id` BIGINT UNSIGNED NULL',
            'montagem_legacy_id' => '`montagem_legacy_id` BIGINT UNSIGNED NULL',
            'play_id'            => '`play_id` BIGINT UNSIGNED NULL',
            'company_id'         => '`company_id` BIGINT UNSIGNED NULL',
            'theater_id'         => '`theater_id` BIGINT UNSIGNED NULL',
            'play_slug'          => '`play_slug` VARCHAR(200) NULL',
            'company_slug'       => '`company_slug` VARCHAR(200) NULL',
            'theater_slug'       => '`theater_slug` VARCHAR(200) NULL',
            'source_hash'        => '`source_hash` CHAR(32) NULL',
            'synced_at'          => '`synced_at` DATETIME NULL',
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
            'KEY montagem_id (montagem_id)',
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
        // count_label?, type, indexed?, filterable?, facetable?, searchable?, acronym?, multi_value?,
        // composite_of? ]
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
                'playTextAuthors' => [
                    'filters_label' => 'Autoria do Texto',
                    'header_label'  => 'Autoria do Texto',
                    'grouped_label' => 'Autoria do Texto',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                    'searchable'    => true,
                    'multi_value'   => true,
                ],
                'playMusicAuthors' => [
                    'filters_label' => 'Autoria da Música',
                    'header_label'  => 'Autoria da Música',
                    'grouped_label' => 'Autoria da Música',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                    'searchable'    => true,
                    'multi_value'   => true,
                ],
                'playArrangers' => [
                    'filters_label' => 'Arranjadores',
                    'header_label'  => 'Arranjadores',
                    'grouped_label' => 'Arranjadores',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                    'searchable'    => true,
                    'multi_value'   => true,
                ],
                // Composta: só exibição (ver `PresentationColumn::$composite_of`).
                'playConception' => [
                    'filters_label' => 'Concepção',
                    'header_label'  => 'Concepção',
                    'grouped_label' => 'Concepção',
                    'type'          => $s,
                    'composite_of'  => [
                        'playTextAuthors'  => 'A.T',
                        'playMusicAuthors' => 'A.M',
                        'playArrangers'    => 'Ar',
                    ],
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
                'presentationTranslators' => [
                    'filters_label' => 'Tradutores',
                    'header_label'  => 'Tradutores',
                    'grouped_label' => 'Tradutores',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                    'searchable'    => true,
                    'multi_value'   => true,
                ],
                'presentationAdapters' => [
                    'filters_label' => 'Adaptadores',
                    'header_label'  => 'Adaptadores',
                    'grouped_label' => 'Adaptadores',
                    'type'          => $s,
                    'filterable'    => true,
                    'facetable'     => true,
                    'searchable'    => true,
                    'multi_value'   => true,
                ],
                // Composta: só exibição (ver `PresentationColumn::$composite_of`).
                'presentationAdaptation' => [
                    'filters_label' => 'Adaptação',
                    'header_label'  => 'Adaptação',
                    'grouped_label' => 'Adaptação',
                    'type'          => $s,
                    'composite_of'  => [
                        'presentationTranslators' => 'Tr',
                        'presentationAdapters'    => 'Ad',
                    ],
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
                    count_label: $def['count_label'] ?? null,
                    multi_value: $def['multi_value'] ?? false,
                    composite_of: $def['composite_of'] ?? []
                );
            }
        }

        return $columns;
    }
}
