// IDs de coleções/metadados do Tainacan, vindos da referência única do plugin
// (classes/references/tainacan-ids.php), que os injeta em
// window.TMSP_TAINACAN_IDS. Nenhum componente deve fixar esses IDs.
//
// Leitura preguiçosa (na hora da chamada, não no carregamento do bundle): o
// script inline e os bundles do Tainacan não têm ordem garantida entre si.

function ids() {
    return window.TMSP_TAINACAN_IDS || { collections: {}, metadata: {} };
}

export function collectionId(key) {
    return Number(ids().collections[key]);
}

export function metadatumId(key) {
    return Number(ids().metadata[key]);
}
