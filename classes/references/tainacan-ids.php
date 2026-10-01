<?php
/**
 * Referência única dos IDs do Tainacan usados por este plugin.
 *
 * Coleções, taxonomias e metadados são posts do WordPress: seus IDs mudam de
 * um ambiente para outro (uma reimportação gera IDs novos). Por isso nenhum
 * outro arquivo do plugin deve escrever um desses IDs como literal — PHP usa
 * as constantes abaixo, e o JS (bundles Vue em components/) as recebe via
 * `window.TMSP_TAINACAN_IDS` (ver `enqueue_for_js()` e
 * components/src/tainacan-ids.js).
 *
 * Os valores refletem o ambiente atual (fonte da verdade). Ao migrar para
 * outro banco, atualize apenas este arquivo.
 */

namespace TeatroMusicadoSP\Customizations\References;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

final class TainacanIds
{
    // Coleções.
    const ESPETACULOS = 9500;
    const PECA        = 9438;
    const COMPANHIA   = 9354;
    const TEATRO      = 9392;
    const PESSOA      = 9289;
    const MEMBROS     = 9566;
    const FUNCOES     = 9596;

    // Taxonomias.
    const TAX_IDIOMA        = 9202;
    const TAX_NACIONALIDADE = 9217;
    const TAX_GENERO        = 9257;
    const TAX_ESPECIALIDADE = 9348;

    // Metadados de Espetáculos.
    const META_ESPETACULO_PECA      = 9508; // Relacionamento → Peça
    const META_ESPETACULO_COMPANHIA = 9511; // Relacionamento → Companhia
    const META_ESPETACULO_IDIOMA    = 9514; // Taxonomia Idioma
    const META_ESPETACULO_TEATRO    = 9554; // Relacionamento → Teatro
    const META_ESPETACULO_TIPO      = 9557; // Selectbox (Completo / Sessões / Palco e Tela)
    const META_ESPETACULO_DATA      = 9560; // Data (Y-m-d)
    const META_ESPETACULO_SESSOES   = 9563; // Numérico

    // Metadados de Peça e Companhia.
    const META_PECA_GENERO             = 9460; // Taxonomia Gênero
    const META_PECA_NACIONALIDADE      = 9464; // Taxonomia Nacionalidade
    const META_COMPANHIA_NACIONALIDADE = 9388; // Taxonomia Nacionalidade

    // Metadados de Membros (filhos do composto "Elenco").
    const META_MEMBROS_ELENCO_PESSOA = 9592;
    const META_MEMBROS_ELENCO_FUNCAO = 9603;

    // Metadado de repositório (todas as coleções): ID numérico legado.
    const META_SLUG_ID = 9610;

    /** Handle do script inline que expõe os IDs ao JS. */
    const JS_HANDLE = 'tmsp-tainacan-ids';

    /**
     * Post type dos itens de uma coleção (ex.: `tnc_col_9500_item`).
     */
    public static function item_post_type( int $collection_id ): string {
        return "tnc_col_{$collection_id}_item";
    }

    /**
     * Nome da taxonomia do WordPress de uma taxonomia Tainacan (ex.: `tnc_tax_9202`).
     */
    public static function taxonomy( int $taxonomy_id ): string {
        return "tnc_tax_{$taxonomy_id}";
    }

    /**
     * IDs usados pelos componentes Vue.
     *
     * @return array<string,array<string,int>>
     */
    public static function for_js(): array {
        return [
            'collections' => [
                'espetaculos' => self::ESPETACULOS,
                'peca'        => self::PECA,
                'companhia'   => self::COMPANHIA,
                'teatro'      => self::TEATRO,
                'pessoa'      => self::PESSOA,
                'membros'     => self::MEMBROS,
                'funcoes'     => self::FUNCOES,
            ],
            'metadata'    => [
                'membrosElencoPessoa' => self::META_MEMBROS_ELENCO_PESSOA,
                'membrosElencoFuncao' => self::META_MEMBROS_ELENCO_FUNCAO,
            ],
        ];
    }

    /**
     * Enfileira um script sem arquivo que define `window.TMSP_TAINACAN_IDS`.
     * Chamado em `wp_enqueue_scripts` e `admin_enqueue_scripts`; os bundles
     * leem o objeto só na hora do uso, então a ordem de carregamento entre
     * este handle e os bundles do Tainacan não importa.
     */
    public static function enqueue_for_js(): void {
        if ( ! wp_script_is( self::JS_HANDLE, 'registered' ) ) {
            wp_register_script( self::JS_HANDLE, false, [], TMSP_CUSTOMIZATIONS_VERSION, false );
            wp_add_inline_script(
                self::JS_HANDLE,
                'window.TMSP_TAINACAN_IDS = ' . wp_json_encode( self::for_js() ) . ';'
            );
        }
        wp_enqueue_script( self::JS_HANDLE );
    }
}
