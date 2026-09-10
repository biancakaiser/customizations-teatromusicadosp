<?php
/**
 * Value object de um modo do campo "Agrupado por".
 *
 * Substitui uma entrada do antigo `PresentationsShortcode::GROUPS`. A hierarquia
 * é sempre de 4 níveis: `l1` (faixa do grupo) > `l2` (bloco de identidade +
 * Total) > `theaterName` > `settingKind` + ano.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Grouping;

use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class GroupingMode
{
    /** Chave sintética de ordenação pela coluna "Total" (soma de sessões do grupo de 1º nível). */
    const SORT_TOTAL = '__total';

    /**
     * @param string                $key             'company' | 'play'
     * @param string                $label           Rótulo do <option>.
     * @param string                $l1              Coluna da faixa de 1º nível.
     * @param string                $l2              Coluna do 2º nível (dado inverso).
     * @param string                $count_label     Rótulo da contagem de grupos de 2º nível.
     * @param array<string,string>  $identity        Coluna => rótulo do bloco de identidade.
     * @param array<string,string>  $l1_label_fields Rótulo => coluna da faixa de 1º nível.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $l1,
        public string $l2,
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

        // Faixa de 1º nível: `l1_label_fields` é `rótulo => coluna`.
        foreach ( $this->l1_label_fields as $field ) {
            $columns[ $field ] = $this->column_label( $field );
        }

        // Bloco de identidade de 2º nível: `identity` é `coluna => rótulo`.
        foreach ( array_keys( $this->identity ) as $field ) {
            $columns[ $field ] = $this->column_label( $field );
        }

        // Colunas folha, comuns aos dois modos.
        $columns['theaterName']      = 'Teatro';
        $columns['settingKind']      = 'Tipo de Espetáculo';
        $columns['presentationDate'] = 'Ano';
        $columns['sessionsNumber']   = 'Nº de Sessões';

        $columns[ self::SORT_TOTAL ] = 'Total';

        return $columns;
    }

    private function column_label( string $key ): string {
        $column = PresentationsSchema::column( $key );
        return null !== $column ? $column->label : $key;
    }

    /**
     * Shape idêntico ao do antigo `GROUPS[<key>]`.
     *
     * @return array<string,mixed>
     */
    public function to_array(): array {
        return [
            'label'           => $this->label,
            'l1'              => $this->l1,
            'l2'              => $this->l2,
            'count_label'     => $this->count_label,
            'identity'        => $this->identity,
            'l1_label_fields' => $this->l1_label_fields,
        ];
    }
}
