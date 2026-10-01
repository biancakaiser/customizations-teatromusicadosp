<?php
/**
 * Value object que descreve uma coluna da tabela de apresentações.
 *
 * Reúne, num único lugar, tudo o que o repositório e o front-end precisam saber
 * sobre a coluna: os rótulos de cada contexto (filtros, tabela plana, tabela
 * agrupada), o tipo (para o `CREATE TABLE`,
 * o placeholder do `$wpdb->prepare` e a normalização de valores de filtro), se
 * ela é indexada, se aceita filtro de igualdade e se ganha um `<select>` de
 * valores distintos, além do grupo/ordem que ela ocupa na tabela plana do
 * shortcode.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Schema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationColumn
{
    const TYPE_STRING = 'string';
    const TYPE_INT    = 'int';
    const TYPE_DATE   = 'date';

    /**
     * `PresentationsSchema::build()::$defs` é aninhado por grupo — `$group` e a
     * posição desta coluna não são valores escolhidos à mão, vêm da própria
     * chave/ordem de `$defs` (chave externa = `$group`, ordem de inserção =
     * ordem de exibição). Não há campo `order` aqui de propósito: a ordem do
     * array PHP já é a fonte da verdade.
     *
     * @param string      $key            Nome da coluna no banco — valor único absoluto: todo
     *                                    lookup de rótulo/grupo em outras classes deve passar por
     *                                    ele (`PresentationsSchema::column( $key )`), nunca duplicar
     *                                    um dos rótulos abaixo como literal.
     * @param string      $filters_label  Rótulo canônico e completo (`PresentationsSchema::labels()`,
     *                                    campos do painel de filtros, `<option>` dos modos de 2
     *                                    níveis, atributos `abbr`/`data-label` das tabelas).
     * @param string      $header_label   Texto do `<th>` da tabela plana (modo "Nenhum"); só faz
     *                                    sentido com o `<th colspan>` do `$group` acima dele.
     * @param string      $grouped_label  Texto do `<th>`/rótulo da tabela agrupada e `<option>` dos
     *                                    modos de 3 níveis.
     * @param string      $group       Rótulo do grupo de cabeçalho da tabela plana (colunas com
     *                                 o mesmo grupo ficam sob um `<th colspan>` comum). Colunas
     *                                 cujo grupo não é compartilhado por nenhuma outra coluna
     *                                 ganham um `<th rowspan="2">` sozinhas, sem linha de
     *                                 `$header_label` abaixo.
     * @param string      $type        self::TYPE_STRING | TYPE_INT | TYPE_DATE.
     * @param bool        $indexed     A coluna tem KEY própria no schema.
     * @param bool        $filterable  A coluna aceita um filtro de igualdade.
     * @param bool        $facetable   A coluna ganha um <select> de valores distintos.
     * @param bool        $searchable  O <select> de filtro ganha busca interna (autocomplete).
     * @param bool        $acronym     Nas tabelas de resultado (plana e agrupada), exibir só a
     *                                 sigla do valor (ver `PresentationValue::acronym()`) em vez
     *                                 do valor completo. Não afeta o painel de filtros.
     * @param string|null $combined_label Rótulo do valor combinado na tabela agrupada: o ano
     *                                    extraído de `presentationDate` ("Ano") e a soma de
     *                                    sessões da faixa ("Total").
     * @param string|null $count_label    Texto exato da contagem da faixa de 1º nível quando a
     *                                    coluna é o 2º nível de um modo (ex.: "Nº Peças").
     */
    public function __construct(
        public string $key,
        public string $filters_label,
        public string $header_label,
        public string $grouped_label,
        public string $group,
        public string $type = self::TYPE_STRING,
        public bool $indexed = false,
        public bool $filterable = false,
        public bool $facetable = false,
        public bool $searchable = false,
        public bool $acronym = false,
        public ?string $combined_label = null,
        public ?string $count_label = null
    ) {}

    public function is_numeric(): bool {
        return self::TYPE_INT === $this->type;
    }

    public function is_date(): bool {
        return self::TYPE_DATE === $this->type;
    }

    /**
     * Definição da coluna para o `CREATE TABLE` — deve continuar byte a byte
     * igual ao que o loop original de PresentationsRepository gerava.
     */
    public function sql_definition(): string {
        if ( $this->is_numeric() ) {
            return "`{$this->key}` INT NULL";
        }
        if ( $this->is_date() ) {
            return "`{$this->key}` DATETIME NULL";
        }
        return "`{$this->key}` VARCHAR(255) NULL";
    }

    /**
     * Placeholder do `$wpdb->prepare` para valores desta coluna.
     */
    public function placeholder(): string {
        return $this->is_numeric() ? '%d' : '%s';
    }

    /**
     * Normaliza um valor cru vindo da request para comparação/armazenamento.
     *
     * @param mixed $raw
     * @return int|string
     */
    public function normalize_filter_value( $raw ) {
        if ( $this->is_numeric() ) {
            return absint( $raw );
        }
        return sanitize_text_field( (string) $raw );
    }
}
