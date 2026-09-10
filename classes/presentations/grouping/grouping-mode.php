<?php
/**
 * Value object de um modo do campo "Agrupado por".
 *
 * Substitui uma entrada do antigo `PresentationsShortcode::GROUPS`. A hierarquia
 * é sempre de 4 níveis: `l1` (faixa do grupo) > `l2` (bloco de identidade +
 * Total) > `theaterName` > `settingKind` + ano.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Grouping;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class GroupingMode
{
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
