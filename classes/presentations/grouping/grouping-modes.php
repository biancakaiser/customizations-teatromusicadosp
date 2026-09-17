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
                    select_label: 'Companhia',
                    l1_key: 'companyName',
                    l2_key: 'playName',
                    count_label: 'Nº Peças',
                    identity: [ 'playName', 'playGenre', 'playNationality' ],
                    l1_label_fields: [ 'companyName', 'companyNationality' ]
                ),
                'play' => new GroupingMode(
                    key: 'play',
                    select_label: 'Peça',
                    l1_key: 'playName',
                    l2_key: 'companyName',
                    count_label: 'Nº Companhias',
                    identity: [ 'companyName', 'companyNationality' ],
                    l1_label_fields: [ 'playName', 'playGenre', 'playNationality' ]
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

    /**
     * Rótulos das colunas "folha", comuns às micro-tabelas dos dois modos, na
     * ordem de `PresentationGrouper::LEAF_FIELDS` + "Total". Os 4 que
     * correspondem a uma coluna real vêm do schema (fonte única); "Ano" é o ano
     * extraído de `presentationDate` e "Total" é um agregado sintético — nenhum
     * dos dois tem coluna própria.
     *
     * @return list<string>
     */
    public static function leaf_labels(): array {
        return [
            PresentationsSchema::column( 'presentationTheater' )->full_label,
            PresentationsSchema::column( 'presentationKind' )->full_label,
            PresentationsSchema::column( 'presentationLanguage' )->full_label,
            'Ano',
            PresentationsSchema::column( 'presentationSessionsN' )->full_label,
            'Total',
        ];
    }
}
