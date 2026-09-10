<?php
/**
 * Traduz uma busca livre + um `PresentationFilters` em um trecho de SQL `WHERE`
 * (sem a palavra `WHERE`) e a lista de parâmetros para o `$wpdb->prepare()`.
 *
 * Os nomes de coluna vêm sempre da whitelist do `PresentationsSchema`; os
 * valores entram sempre como placeholders (`%s`/`%d`) — nunca interpolados.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Filters;

use TeatroMusicadoSP\Customizations\Presentations\Schema\PresentationsSchema;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationFilterClause
{
    /** @var \wpdb */
    private $wpdb;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * @return array{sql:string,params:list<mixed>} `sql` não tem `WHERE` inicial.
     */
    public function build( PresentationFilters $filters, string $search ): array {
        $clauses = [];
        $params  = [];

        $search = trim( $search );
        if ( '' !== $search ) {
            $like = '%' . $this->wpdb->esc_like( $search ) . '%';
            $sub  = [];
            foreach ( PresentationsSchema::keys() as $column ) {
                $sub[]    = "`{$column}` LIKE %s";
                $params[] = $like;
            }
            $clauses[] = '(' . implode( ' OR ', $sub ) . ')';
        }

        foreach ( $filters->values() as $column => $value ) {
            $schema_column = PresentationsSchema::column( (string) $column );
            if ( null === $schema_column || ! $schema_column->facetable ) {
                continue;
            }
            $clauses[] = "`{$column}` = " . $schema_column->placeholder();
            $params[]  = $schema_column->is_numeric() ? (int) $value : (string) $value;
        }

        return [
            'sql'    => $clauses ? implode( ' AND ', $clauses ) : '',
            'params' => $params,
        ];
    }
}
