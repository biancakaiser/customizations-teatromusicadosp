<?php
/**
 * Value object de um modo do campo "Agrupado por".
 *
 * Substitui uma entrada do antigo `PresentationsShortcode::GROUPS`. Há dois
 * formatos de hierarquia:
 *   - 3 níveis (Companhia, Peça): `l1_key` (faixa do grupo) > `l2_key` (bloco de
 *     identidade) > linhas-folha (`leaf_columns` + sessões somadas);
 *   - 2 níveis (`l2_key` = null; Gênero, Nacionalidade, Teatro, Idioma): faixa
 *     do grupo > linhas-folha, sem bloco de identidade.
 * Em ambos, a coluna "Total" (soma de sessões da faixa) fecha a linha.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Grouping;

use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class GroupingMode
{
    /** Chave sintética de ordenação pela coluna "Total" (soma de sessões do grupo de 1º nível). */
    const SORT_TOTAL = '__total';

    /** Colunas-folha padrão (as dos modos de 3 níveis), na ordem de exibição. */
    const DEFAULT_LEAF_COLUMNS = [
        'presentationTheater',
        'presentationKind',
        'presentationLanguage',
        'presentationDate',
    ];

    /**
     * @param string       $key             Chave do modo (`tap_group`), ex.: 'company'.
     * @param string       $select_label    Rótulo do <option>.
     * @param string       $l1_key          Coluna da faixa de 1º nível.
     * @param string|null  $l2_key          Coluna do 2º nível (dado inverso); null = modo de 2 níveis.
     * @param string       $count_label     Rótulo da contagem da faixa: blocos de 2º nível
     *                                      (3 níveis) ou linhas-folha (2 níveis).
     * @param list<string> $identity        Colunas do bloco de identidade do 2º nível.
     * @param list<string> $l1_label_fields Colunas que compõem o texto da faixa de 1º nível.
     * @param list<string> $leaf_columns    Colunas do schema (as de `PresentationGrouper::
     *                                      ROW_FIELDS`/`LEAF_FIELDS`, sem Sessões)
     *                                      que distinguem as linhas-folha — as sessões
     *                                      são somadas por essa combinação. Cada uma
     *                                      vira uma coluna da tabela, antes de
     *                                      "Sessões" e "Total".
     */
    public function __construct(
        public string $key,
        public string $select_label,
        public string $l1_key,
        public ?string $l2_key,
        public string $count_label,
        public array $identity,
        public array $l1_label_fields,
        public array $leaf_columns = self::DEFAULT_LEAF_COLUMNS
    ) {}

    /**
     * Tem o bloco de identidade de 2º nível (modo de 3 níveis)?
     */
    public function has_identity_level(): bool {
        return null !== $this->l2_key;
    }

    /**
     * Rótulo do total na faixa do grupo de 1º nível (texto do botão
     * `group-toggle`) e da opção correspondente em "Ordenado por": o
     * `combined_label` de `presentationSessionsN` + " de " + o seu
     * `filters_label` ("Total de Sessões"). O `<th>` e o `data-label` da coluna
     * seguem só com o `combined_label` ("Total", ver `leaf_labels()`).
     */
    public static function group_total_label(): string {
        $sessions = PresentationsSchema::column( 'presentationSessionsN' );
        return $sessions->combined_label . ' de ' . $sessions->filters_label;
    }

    /**
     * Rótulos das colunas-folha deste modo, na ordem de `leaf_columns`, seguidos
     * de "Sessões" e "Total". Todos vêm do schema (fonte única): cada folha usa
     * o `combined_label` da coluna quando ela tem um (o "Ano" extraído de
     * `presentationDate`), senão o `filters_label`; "Total" é o `combined_label`
     * de `presentationSessionsN` (soma de sessões da faixa).
     * São os rótulos completos (`data-label`/`abbr`); o texto do `<th>` usa o
     * `grouped_label` do schema, resolvido em `GroupedTableRenderer`.
     *
     * @return array<string,string> coluna => rótulo (chaves `presentationSessionsN` e `SORT_TOTAL` no fim)
     */
    public function leaf_labels(): array {
        $labels = [];
        foreach ( $this->leaf_columns as $column ) {
            $schema_column     = PresentationsSchema::column( $column );
            $labels[ $column ] = $schema_column->combined_label ?? $schema_column->filters_label;
        }
        $sessions                        = PresentationsSchema::column( 'presentationSessionsN' );
        $labels['presentationSessionsN'] = $sessions->filters_label;
        $labels[ self::SORT_TOTAL ]      = $sessions->combined_label;

        return $labels;
    }

    /**
     * Colunas oferecidas no `<select>` "Ordenado por:" quando este modo está
     * ativo (a ordenação só existe no modo agrupado). A 1ª é o padrão.
     *   - "Ano": o ano extraído de `presentationDate` (linha-folha; ver
     *     PresentationGrouper::LEAF_FIELDS);
     *   - "Total de Sessões": a coluna "Total" (soma de sessões da faixa de
     *     1º nível, `SORT_TOTAL`).
     *
     * @return array<string,string> chave da coluna => rótulo
     */
    public function sortable_columns(): array {
        return [
            'presentationDate' => PresentationsSchema::column( 'presentationDate' )->combined_label,
            self::SORT_TOTAL   => self::group_total_label(),
        ];
    }

    /**
     * A chave pedida é uma ordenação aceita por este modo?
     */
    public function is_sortable( string $key ): bool {
        return isset( $this->sortable_columns()[ $key ] );
    }

    /**
     * Mapa `coluna => grouped_label`, resolvido a partir do schema. Usado tanto
     * pelo bloco de identidade quanto pela faixa de 1º nível — nenhum dos dois
     * guarda rótulo próprio, o schema é a única fonte de verdade.
     *
     * @param list<string> $keys
     * @return array<string,string>
     */
    public function labels_for( array $keys ): array {
        $labels = [];
        foreach ( $keys as $key ) {
            $labels[ $key ] = $this->column_grouped_label( $key );
        }
        return $labels;
    }

    private function column_grouped_label( string $key ): string {
        $column = PresentationsSchema::column( $key );
        return null !== $column ? $column->grouped_label : $key;
    }
}
