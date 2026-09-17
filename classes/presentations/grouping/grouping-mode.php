<?php
/**
 * Value object de um modo do campo "Agrupado por".
 *
 * Substitui uma entrada do antigo `PresentationsShortcode::GROUPS`. A hierarquia
 * é sempre de 4 níveis: `l1_key` (faixa do grupo) > `l2_key` (bloco de
 * identidade + Total) > `presentationTheater` > `presentationKind` + ano.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Grouping;

use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class GroupingMode
{
    /** Chave sintética de ordenação pela coluna "Total" (soma de sessões do grupo de 1º nível). */
    const SORT_TOTAL = '__total';

    /**
     * @param string       $key             'company' | 'play'
     * @param string       $select_label    Rótulo do <option>.
     * @param string       $l1_key          Coluna da faixa de 1º nível.
     * @param string       $l2_key          Coluna do 2º nível (dado inverso).
     * @param string       $count_label     Rótulo da contagem de grupos de 2º nível.
     * @param list<string> $identity        Colunas do bloco de identidade do 2º nível.
     * @param list<string> $l1_label_fields Colunas que compõem o texto da faixa de 1º nível.
     */
    public function __construct(
        public string $key,
        public string $select_label,
        public string $l1_key,
        public string $l2_key,
        public string $count_label,
        public array $identity,
        public array $l1_label_fields
    ) {}

    /**
     * Colunas oferecidas no `<select>` "Ordenado por:" quando este modo está
     * ativo, na ordem em que aparecem na tabela: identidade do 1º nível (faixa),
     * identidade do 2º nível, colunas folha e, por fim, "Total".
     *
     * @return array<string,string> chave da coluna => rótulo
     */
    public function sortable_columns(): array {
        $columns = [];

        // Faixa de 1º nível.
        foreach ( $this->l1_label_fields as $field ) {
            $columns[ $field ] = $this->column_label( $field );
        }

        // Bloco de identidade de 2º nível.
        foreach ( $this->identity as $field ) {
            $columns[ $field ] = $this->column_label( $field );
        }

        // Colunas folha, comuns aos dois modos. "Ano" não vem do schema: é o ano
        // extraído de `presentationDate` (ver PresentationGrouper::LEAF_FIELDS),
        // não o rótulo dessa coluna.
        $columns['presentationTheater']    = $this->column_label( 'presentationTheater' );
        $columns['presentationKind']       = $this->column_label( 'presentationKind' );
        $columns['presentationLanguage']   = $this->column_label( 'presentationLanguage' );
        $columns['presentationDate']       = 'Ano';
        $columns['presentationSessionsN']  = $this->column_label( 'presentationSessionsN' );

        $columns[ self::SORT_TOTAL ] = 'Total';

        return $columns;
    }

    private function column_label( string $key ): string {
        $column = PresentationsSchema::column( $key );
        return null !== $column ? $column->full_label : $key;
    }

    /**
     * Mapa `coluna => rótulo curto`, resolvido a partir do schema. Usado tanto
     * pelo bloco de identidade quanto pela faixa de 1º nível — nenhum dos dois
     * guarda rótulo próprio, o schema é a única fonte de verdade.
     *
     * @param list<string> $keys
     * @return array<string,string>
     */
    public function labels_for( array $keys ): array {
        $labels = [];
        foreach ( $keys as $key ) {
            $labels[ $key ] = $this->column_short_label( $key );
        }
        return $labels;
    }

    private function column_short_label( string $key ): string {
        $column = PresentationsSchema::column( $key );
        return null !== $column ? $column->short_label : $key;
    }
}
