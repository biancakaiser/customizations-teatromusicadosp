<?php

namespace TeatroMusicadoSP\Customizations\Settings;

use TeatroMusicadoSP\Customizations\Traits\Singleton;
use TeatroMusicadoSP\Customizations\Blocks\Bibliographic;
use TeatroMusicadoSP\Customizations\MetadataTypes\RegisterMetadatas;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsPage;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsRepository;
use TeatroMusicadoSP\Customizations\Presentations\PresentationsShortcode;
use TeatroMusicadoSP\Customizations\Presentations\Sync\PresentationsReconciler;
use TeatroMusicadoSP\Customizations\Presentations\Sync\PresentationsSync;
use TeatroMusicadoSP\Customizations\RelatedItems\PessoaRelatedItemsOrder;
use TeatroMusicadoSP\Customizations\ViewModes\RegisterViewModes;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Catálogo das funcionalidades que podem ser ligadas/desligadas na página de
 * configurações, e leitura do estado salvo.
 *
 * O estado vive numa única option (array `chave => 0|1`). Uma chave ausente
 * vale "desligado": a instalação nova começa com tudo desligado e o admin
 * escolhe o que aplicar. Instalações que já rodavam o plugin antes desta
 * página existir recebem tudo ligado uma única vez (ver maybe_migrate()).
 *
 * Sub-opções só valem quando a funcionalidade-mãe está ligada:
 * is_enabled( 'presentations_rest' ) já embute essa checagem.
 */
final class Features
{
    use Singleton;

    const OPTION = 'tmsp_customizations_features';

    /** Ligar o shortcode, a REST ou a sincronização exige a tabela; ver sanitize(). */
    const TABLE_KEY = 'presentations_table';

    /** Sincronização Tainacan → tabela (hooks + conferência a cada 3 dias). */
    const SYNC_KEY = 'presentations_sync';

    /** @var array<string,int>|null Estado salvo, em cache na requisição. */
    private $state = null;

    /**
     * Estrutura das funcionalidades: módulos que cada uma registra e suas
     * sub-opções. Sem textos traduzíveis de propósito — esta classe é lida no
     * carregamento do arquivo principal, antes do 'init' (rótulos e descrições
     * ficam em SettingsPage). Módulos vão como nome de classe para só serem
     * instanciados quando a funcionalidade estiver ligada.
     *
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array
    {
        return array(
            'metadata_types'      => array( 'modules' => array( RegisterMetadatas::class ) ),
            'view_modes'          => array( 'modules' => array( RegisterViewModes::class ) ),
            'related_items_order' => array( 'modules' => array( PessoaRelatedItemsOrder::class ) ),
            'bibliographic'       => array( 'modules' => array( Bibliographic::class ) ),
            'presentations'       => array(
                'modules'  => array( PresentationsRepository::class, PresentationsReconciler::class ),
                'children' => array(
                    'presentations_shortcode'  => array( 'modules' => array( PresentationsShortcode::class ) ),
                    'presentations_rest'       => array( 'modules' => array() ),
                    'presentations_admin_page' => array( 'modules' => array( PresentationsPage::class ) ),
                    self::TABLE_KEY            => array( 'modules' => array() ),
                    self::SYNC_KEY             => array( 'modules' => array( PresentationsSync::class ) ),
                ),
            ),
        );
    }

    /**
     * Todas as chaves (funcionalidades e sub-opções).
     *
     * @return string[]
     */
    public function keys(): array
    {
        $keys = array();
        foreach ( $this->definitions() as $key => $definition ) {
            $keys[] = $key;
            foreach ( array_keys( $definition['children'] ?? array() ) as $child ) {
                $keys[] = $child;
            }
        }
        return $keys;
    }

    /**
     * Sub-opção -> funcionalidade-mãe.
     */
    private function parent_of( string $key ): ?string
    {
        foreach ( $this->definitions() as $parent => $definition ) {
            if ( isset( $definition['children'][ $key ] ) ) {
                return $parent;
            }
        }
        return null;
    }

    /**
     * Estado salvo, sem aplicar a regra da mãe.
     *
     * @return array<string,int>
     */
    public function saved(): array
    {
        if ( null === $this->state ) {
            $saved       = get_option( self::OPTION, array() );
            $this->state = is_array( $saved ) ? array_map( 'intval', $saved ) : array();
        }
        return $this->state;
    }

    /**
     * Descarta o cache de estado (usado após salvar a option).
     */
    public function flush(): void
    {
        $this->state = null;
    }

    /**
     * A funcionalidade (ou sub-opção) está ligada? Uma sub-opção só conta se a
     * mãe também estiver ligada.
     */
    public function is_enabled( string $key ): bool
    {
        return $this->is_enabled_in( $this->saved(), $key );
    }

    /**
     * Mesma regra de is_enabled(), sobre um estado arbitrário (ex.: o valor
     * antigo e o novo de uma atualização da option).
     *
     * @param array<string,mixed> $state
     */
    public function is_enabled_in( array $state, string $key ): bool
    {
        $parent = $this->parent_of( $key );
        if ( null !== $parent && ! $this->is_enabled_in( $state, $parent ) ) {
            return false;
        }

        return ! empty( $state[ $key ] );
    }

    /**
     * Classes dos módulos a registrar, conforme o estado atual.
     *
     * @return string[]
     */
    public function enabled_modules(): array
    {
        $modules = array();

        foreach ( $this->definitions() as $key => $definition ) {
            if ( ! $this->is_enabled( $key ) ) {
                continue;
            }

            $modules = array_merge( $modules, $definition['modules'] );

            foreach ( $definition['children'] ?? array() as $child_key => $child ) {
                if ( $this->is_enabled( $child_key ) ) {
                    $modules = array_merge( $modules, $child['modules'] );
                }
            }
        }

        return $modules;
    }

    /**
     * Normaliza o que veio do formulário: só chaves conhecidas, sempre 0|1, e
     * o banco de dados é forçado quando o shortcode, a REST ou a sincronização
     * estão ligados (todos usam a tabela).
     *
     * @param mixed $input
     * @return array<string,int>
     */
    public function sanitize( $input ): array
    {
        $input = is_array( $input ) ? $input : array();
        $clean = array();

        foreach ( $this->keys() as $key ) {
            $clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
        }

        if ( $clean['presentations'] && ( $clean['presentations_shortcode'] || $clean['presentations_rest'] || $clean[ self::SYNC_KEY ] ) ) {
            $clean[ self::TABLE_KEY ] = 1;
        }

        return $clean;
    }

    /**
     * Estado com todas as chaves no mesmo valor.
     *
     * @return array<string,int>
     */
    public function all( int $value ): array
    {
        return array_fill_keys( $this->keys(), $value );
    }

    /**
     * Instalação que já rodava o plugin antes desta página: sem option salva,
     * mas com o plugin ativo. Liga tudo uma única vez para não desligar o site
     * no deploy. Numa ativação nova o plugin ainda não consta como ativo neste
     * ponto (activate_plugin() só o lista depois do hook de ativação), então
     * nada é semeado aqui; ver on_activation().
     */
    public function maybe_migrate(): void
    {
        if ( false !== get_option( self::OPTION, false ) ) {
            return;
        }

        $basename = plugin_basename( TMSP_CUSTOMIZATIONS_FILE );
        $active   = in_array( $basename, (array) get_option( 'active_plugins', array() ), true )
            || ( is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )[ $basename ] ) );

        if ( ! $active ) {
            return;
        }

        add_option( self::OPTION, $this->all( 1 ) );
        $this->flush();
    }

    /**
     * Hook de ativação: começa com tudo desligado (sem sobrescrever uma
     * escolha anterior, caso o plugin seja reativado).
     */
    public static function on_activation(): void
    {
        $features = self::get_instance();

        add_option( self::OPTION, $features->all( 0 ) );
        $features->flush();

        if ( $features->is_enabled( self::TABLE_KEY ) ) {
            PresentationsRepository::install();
        }
    }
}
