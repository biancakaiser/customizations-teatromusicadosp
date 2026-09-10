<?php
/**
 * Registry dos modos de agrupamento ("Agrupado por").
 *
 * O agrupamento (`PresentationGrouper` + `GroupedTableRenderer`) roda só no
 * servidor; o cliente (`assets/presentations.js`) consome o HTML já pronto da
 * rota REST `/presentations/grouped`, então não precisa mais desta config.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Grouping;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class GroupingModes
{
    /** Rótulos das colunas "folha", comuns às micro-tabelas dos dois modos. */
    const LEAF_LABELS = [ 'Teatro', 'Tipo de Espetáculo', 'Ano', 'Nº de Sessões', 'Total' ];

    /** @var array<string,GroupingMode>|null */
    private static $modes = null;

    /**
     * @return array<string,GroupingMode>
     */
    public static function all(): array {
        if ( null === self::$modes ) {
            self::$modes = [
                'company' => new GroupingMode(
                    'company',
                    'Companhia',
                    'companyName',
                    'playName',
                    'Nº Peças',
                    [
                        'playName'        => 'Título da Peça',
                        'genre'           => 'Gênero',
                        'playNationality' => 'Nacionalidade',
                        'playLanguage'    => 'Idioma',
                    ],
                    [
                        'Companhia'     => 'companyName',
                        'Nacionalidade' => 'companyNationality',
                    ]
                ),
                'play' => new GroupingMode(
                    'play',
                    'Peça',
                    'playName',
                    'companyName',
                    'Nº Companhias',
                    [
                        'companyName'        => 'Nome da Companhia',
                        'companyNationality' => 'Nacionalidade da Companhia',
                        'settingLanguage'    => 'Idioma',
                    ],
                    [
                        'Peça'          => 'playName',
                        'Gênero'        => 'genre',
                        'Nacionalidade' => 'playNationality',
                    ]
                ),
            ];
        }

        return self::$modes;
    }

    public static function has( string $key ): bool {
        return '' !== $key && isset( self::all()[ $key ] );
    }

    public static function get( string $key ): ?GroupingMode {
        return self::all()[ $key ] ?? null;
    }
}
