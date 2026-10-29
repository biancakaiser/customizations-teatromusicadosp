<?php
/**
 * Helpers de formatação de valores de apresentação, antes triplicados entre o
 * shortcode (PHP), o `assets/presentations.js` e a montagem de grupos.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Rendering;

use TeatroMusicadoSP\Customizations\References\TainacanIds;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationColumn;
use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

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

    /**
     * `<abbr title="…">…</abbr>` já escapado: a forma curta visível e a completa
     * na dica (ao passar o mouse) e para leitores de tela.
     */
    public static function abbr( string $short, string $title ): string {
        return '<abbr title="' . esc_attr( $title ) . '">' . esc_html( $short ) . '</abbr>';
    }

    /**
     * HTML (já escapado) da sigla de um idioma/nacionalidade (`acronym()`) com o
     * valor completo no `<abbr title>` — ex.: "Portuguesa" →
     * `<abbr title="Portuguesa">PO</abbr>`. Vazio (ou já "—"): "—", sem `<abbr>`.
     *
     * @param mixed $value
     */
    public static function acronym_html( $value ): string {
        $value = trim( (string) $value );
        if ( '' === $value || '—' === $value ) {
            return esc_html( '—' );
        }
        return self::abbr( self::acronym( $value ), $value );
    }

    /**
     * HTML (já escapado) de um valor que aponta para a ficha de um item do
     * Tainacan: `<a>` para `/{coleção}/{post_name}/`; só o texto quando não há
     * `post_name` (ex.: linhas do seed CSV) ou o valor está vazio ("—").
     *
     * @param mixed $text
     * @param mixed $item_slug
     */
    public static function item_link( $text, $item_slug, int $collection_id ): string {
        $text = self::non_empty( $text );
        $url  = '—' === $text ? '' : TainacanIds::item_url( $collection_id, (string) $item_slug );

        return '' === $url
            ? esc_html( $text )
            : '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
    }

    /**
     * Papéis com pessoas de uma coluna composta (`PresentationColumn::$composite_of`),
     * na ordem de exibição. As pessoas vêm da coluna oculta `links_key()` de cada
     * papel (JSON `[[post_name, nome], ...]`); sem ela (ex.: seed CSV), do texto
     * exibido "A; B", sem link.
     *
     * @param array<string,mixed> $row
     * @return list<array{acronym:string,label:string,people:list<array{0:string,1:string}>}>
     */
    public static function composite_parts( array $row, PresentationColumn $composite ): array {
        $parts = [];

        foreach ( $composite->composite_of as $key => $acronym ) {
            $column = PresentationsSchema::column( $key );
            if ( null === $column ) {
                continue;
            }

            $people = [];
            $links  = $row[ $column->links_key() ] ?? null;
            $links  = is_string( $links ) ? json_decode( $links, true ) : null;
            if ( is_array( $links ) ) {
                foreach ( $links as $person ) {
                    if ( is_array( $person ) && '' !== trim( (string) ( $person[1] ?? '' ) ) ) {
                        $people[] = [ (string) ( $person[0] ?? '' ), trim( (string) $person[1] ) ];
                    }
                }
            }
            if ( ! $people ) {
                foreach ( explode( '; ', (string) ( $row[ $key ] ?? '' ) ) as $name ) {
                    if ( '' !== trim( $name ) ) {
                        $people[] = [ '', trim( $name ) ];
                    }
                }
            }

            if ( $people ) {
                $parts[] = [
                    'acronym' => $acronym,
                    'label'   => $column->filters_label,
                    'people'  => $people,
                ];
            }
        }

        return $parts;
    }

    /**
     * Texto puro de uma coluna composta — "A.T: X, Y; A.M: Z" — ou '' quando
     * nenhum papel tem pessoas. Usado como chave/ordenação nos agrupados.
     *
     * @param array<string,mixed> $row
     */
    public static function composite_text( array $row, PresentationColumn $composite ): string {
        $parts = [];
        foreach ( self::composite_parts( $row, $composite ) as $part ) {
            $parts[] = $part['acronym'] . ': ' . implode( ', ', array_column( $part['people'], 1 ) );
        }
        return implode( '; ', $parts );
    }

    /**
     * HTML (já escapado) de uma coluna composta: cada papel com pessoas vira
     * `<abbr title="Autoria do Texto">A.T</abbr>: ` + um link por Pessoa unidos
     * por ", "; os papéis são unidos por "; ". Sem pessoas, "—".
     *
     * @param array<string,mixed> $row
     */
    public static function composite_html( array $row, PresentationColumn $composite ): string {
        $parts = [];
        foreach ( self::composite_parts( $row, $composite ) as $part ) {
            $links = [];
            foreach ( $part['people'] as [ $item_slug, $name ] ) {
                $links[] = self::item_link( $name, $item_slug, TainacanIds::PESSOA );
            }
            $parts[] = self::abbr( $part['acronym'], $part['label'] ) . ': ' . implode( ', ', $links );
        }
        return $parts ? implode( '; ', $parts ) : esc_html( '—' );
    }
}
