<?php
/**
 * Fachada de leitura dos valores distintos por coluna, para que os renderers não
 * precisem alcançar o singleton do repositório diretamente.
 */

namespace TeatroMusicadoSP\Customizations\Presentations\Filters;

use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class PresentationsFacets
{
    /** @var PresentationsRepository */
    private $repository;

    public function __construct( PresentationsRepository $repository ) {
        $this->repository = $repository;
    }

    /**
     * @return list<string|int>
     */
    public function options( string $column ): array {
        return $this->repository->facet_values( $column );
    }

    /**
     * @return array<string,list<string|int>>
     */
    public function all(): array {
        return $this->repository->all_facets();
    }
}
