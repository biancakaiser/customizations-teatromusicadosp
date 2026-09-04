import { createApp } from 'vue';
import EspetaculosTable from './EspetaculosTable.vue';

// Bundle próprio e desacoplado da SPA do Tainacan: diferente dos view modes
// registrados em components.js (que viram componentes internos do Vue do
// Tainacan via window.tainacan_extra_components), este Form Hook só entrega
// HTML estático (ver classes/admin/collection-form.php) que a SPA injeta via
// v-html. Por isso montamos nossa própria instância Vue diretamente no
// container, sem depender da árvore de componentes do Tainacan.

const CONTAINER_ID = 'teatro-espetaculos-app';
const MOUNTED_FLAG = 'teatroEspetaculosMounted';

function mountIfPresent() {
    const container = document.getElementById(CONTAINER_ID);

    if (!container || container.dataset[MOUNTED_FLAG] === 'true') {
        return;
    }

    container.innerHTML = '';
    createApp(EspetaculosTable).mount(container);
    container.dataset[MOUNTED_FLAG] = 'true';
}

mountIfPresent();

// O container só existe depois que a SPA do Tainacan busca o item e injeta o
// HTML do Form Hook (via v-html), o que acontece depois deste script
// carregar. Também é recriado (sem a flag de montagem) sempre que o usuário
// navega para fora e de volta para a ficha do item, já que a SPA
// desmonta/remonta esse trecho do DOM. Por isso observamos o DOM em vez de
// montar uma única vez no load.
const observer = new MutationObserver(mountIfPresent);
observer.observe(document.body, { childList: true, subtree: true });
