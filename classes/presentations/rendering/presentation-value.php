<?php
/**
 * Helpers de formatação de valores de apresentação, antes triplicados entre o
 * shortcode (PHP), o `assets/presentations.js` e a montagem de grupos.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Rendering;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationValue
{
    /**
     * Valor de texto sem espaços; devolve "—" quando vazio.
     *
     * @param mixed $value
     */
    public static function non_empty( $value ): string {
        $value = trim( (string) $value );
        return '' !== $value ? $value : '—';
    }

    /**
     * Converte "YYYY-MM-DD[ HH:MM:SS]" em "DD/MM/YYYY"; devolve "—" se vazio.
     *
     * @param mixed $value
     */
    public static function date_br( $value ): string {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '—';
        }
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        return $value;
    }

    /**
     * Extrai o ano (primeiro bloco de 4 dígitos) de uma data; "—" se não houver.
     *
     * @param mixed $date
     */
    public static function year_of( $date ): string {
        if ( preg_match( '/(\d{4})/', (string) $date, $m ) ) {
            return $m[1];
        }
        return '—';
    }

    /**
     * Sigla de um valor de idioma/nacionalidade para exibição nas tabelas de
     * resultado. Espera o valor no formato "XY - Nome" (ex.: "EN - English") e
     * devolve só o "XY"; quando o valor não segue esse formato, usa os dois
     * primeiros caracteres em maiúsculas (ex.: "English" → "EN"). "—" se vazio.
     *
     * @param mixed $value
     */
    public static function acronym( $value ): string {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '—';
        }

        $sep = strpos( $value, ' - ' );
        if ( false !== $sep && $sep > 0 ) {
            return strtoupper( trim( substr( $value, 0, $sep ) ) );
        }

        return strtoupper( mb_substr( $value, 0, 2 ) );
    }
}
