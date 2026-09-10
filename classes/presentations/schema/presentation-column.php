<?php
/**
 * Value object que descreve uma coluna da tabela de apresentações.
 *
 * Reúne, num único lugar, tudo o que o repositório e o front-end precisam saber
 * sobre a coluna: o rótulo, o tipo (para o `CREATE TABLE`, o placeholder do
 * `$wpdb->prepare` e a normalização de valores de filtro), se ela é indexada, se
 * aceita filtro de igualdade e se ganha um `<select>` de valores distintos, além
 * do rótulo/ordem que ela ocupa na tabela plana do shortcode.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Schema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationColumn
{
    const TYPE_STRING = 'string';
    const TYPE_INT    = 'int';
    const TYPE_DATE   = 'date';

    /**
     * @param string      $key        Nome da coluna no banco.
     * @param string      $label      Rótulo canônico (usado no header REST X-TMSP-Columns).
     * @param string      $type       self::TYPE_STRING | TYPE_INT | TYPE_DATE.
     * @param bool        $indexed    A coluna tem KEY própria no schema.
     * @param bool        $filterable A coluna aceita um filtro de igualdade.
     * @param bool        $facetable  A coluna ganha um <select> de valores distintos.
     * @param string|null $flat_label Rótulo na tabela plana do shortcode (null = não exibida).
     * @param int         $flat_order Posição na tabela plana (menor primeiro).
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TYPE_STRING,
        public bool $indexed = false,
        public bool $filterable = false,
        public bool $facetable = false,
        public ?string $flat_label = null,
        public int $flat_order = 0
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
