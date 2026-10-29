<?php
/**
 * Registry dos modos de agrupamento ("Agrupado por").
 *
 * O agrupamento (`PresentationGrouper` + `GroupedTableRenderer`) roda só no
 * servidor; o cliente (`assets/presentations.js`) consome o HTML já pronto da
 * rota REST `/presentations/grouped`, então não precisa mais desta config.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Grouping;

use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class GroupingModes
{
    /** @var array<string,GroupingMode>|null */
    private static $modes = null;

    /**
     * @return array<string,GroupingMode>
     */
    public static function all(): array {
        if ( null === self::$modes ) {
            self::$modes = [
                'company' => new GroupingMode(
                    key: 'company',
                    select_label: PresentationsSchema::column( 'companyName' )->grouped_label,
                    l1_key: 'companyName',
                    l2_key: 'playName',
                    count_label: PresentationsSchema::column( 'playName' )->count_label,
                    // A Concepção é da Peça: fica no bloco dela, mesclada com rowspan.
                    identity: [ 'playName', 'playGenre', 'playNationality', 'playConception' ],
                    l1_label_fields: [ 'companyName', 'companyNationality' ],
                    count_nouns: [ 'companhia', 'companhias' ]
                ),
                'play' => new GroupingMode(
                    key: 'play',
                    select_label: PresentationsSchema::column( 'playName' )->grouped_label,
                    l1_key: 'playName',
                    l2_key: 'companyName',
                    count_label: PresentationsSchema::column( 'companyName' )->count_label,
                    identity: [ 'companyName', 'companyNationality' ],
                    l1_label_fields: [ 'playName', 'playGenre', 'playNationality' ],
                    leaf_columns: array_merge( [ 'playConception' ], GroupingMode::DEFAULT_LEAF_COLUMNS ),
                    count_nouns: [ 'peça', 'peças' ]
                ),
                // Modos de 2 níveis: faixa do valor > um espetáculo por linha
                // (sem bloco de identidade). Ver `two_level()`.
                'genre'               => self::two_level( 'genre', 'playGenre', [ 'gênero', 'gêneros' ] ),
                'play_nationality'    => self::two_level( 'play_nationality', 'playNationality', [ 'nacionalidade', 'nacionalidades' ] ),
                'company_nationality' => self::two_level( 'company_nationality', 'companyNationality', [ 'nacionalidade', 'nacionalidades' ] ),
                'theater'             => self::two_level( 'theater', 'presentationTheater', [ 'teatro', 'teatros' ] ),
                'language'            => self::two_level( 'language', 'presentationLanguage', [ 'idioma', 'idiomas' ] ),
            ];
        }

        return self::$modes;
    }

    /**
     * Modo de 2 níveis agrupado por uma única coluna do schema, cujo
     * `filters_label` vira o do `<option>`.
     *
     * Cada linha traz todos os dados da tabela plana (Peça, Companhia e
     * Espetáculo, com a data reduzida ao ano) menos a coluna que agrupa — ela já
     * está na faixa. As sessões são somadas por essa combinação de colunas.
     *
     * @param array{0:string,1:string} $count_nouns Ver `GroupingMode::$count_nouns`.
     */
    private static function two_level( string $key, string $column, array $count_nouns ): GroupingMode {
        $leaf_columns = array_values(
            array_diff(
                array_merge(
                    array_keys( PresentationGrouper::ROW_FIELDS ),
                    GroupingMode::DEFAULT_LEAF_COLUMNS
                ),
                [ $column ]
            )
        );

        return new GroupingMode(
            key: $key,
            select_label: PresentationsSchema::column( $column )->filters_label,
            l1_key: $column,
            l2_key: null,
            count_label: PresentationsSchema::ROW_COUNT_LABEL,
            identity: [],
            l1_label_fields: [ $column ],
            leaf_columns: $leaf_columns,
            count_nouns: $count_nouns
        );
    }

    public static function has( string $key ): bool {
        return '' !== $key && isset( self::all()[ $key ] );
    }

    public static function get( string $key ): ?GroupingMode {
        return self::all()[ $key ] ?? null;
    }
}
