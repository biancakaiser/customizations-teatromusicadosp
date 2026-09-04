// mock-espetaculos.js
// Dados falsos para validar visualmente o Form Hook (spike de usabilidade).
// NÃO representa integração real com a coleção Espetáculos — apenas simula
// o formato que a tabela vai exibir/manipular depois de conectada à API.

export const TEATROS_MOCK = [
  { id: 101, nome: 'Teatro Municipal de São Paulo' },
  { id: 102, nome: 'Teatro São José' },
  { id: 103, nome: 'Teatro Boa Vista' },
  { id: 104, nome: 'Teatro Apolo' },
  { id: 105, nome: 'Teatro Casino Antarctica' },
  { id: 106, nome: 'Teatro Colombo' },
];

export const TIPOS_ESPETACULO = ['Sessão', 'Completo', 'Palco e Tela'];

// Distribuição proposital: sessoesNumero concentrado em 1–2 (~70% dos registros),
// como indicado nas premissas de dados reais.
export const ESPETACULOS_MOCK = [
  { id: 1,  teatroId: 101, tipo: 'Completo',     data: '1914-03-12', sessoesNumero: 1 },
  { id: 2,  teatroId: 101, tipo: 'Sessão',        data: '1914-03-12', sessoesNumero: 2 },
  { id: 3,  teatroId: 102, tipo: 'Completo',     data: '1916-07-04', sessoesNumero: 1 },
  { id: 4,  teatroId: 103, tipo: 'Palco e Tela', data: '1918-11-22', sessoesNumero: 3 },
  { id: 5,  teatroId: 101, tipo: 'Sessão',        data: '1919-01-09', sessoesNumero: 2 },
  { id: 6,  teatroId: 104, tipo: 'Completo',     data: '1920-05-30', sessoesNumero: 1 },
  { id: 7,  teatroId: 105, tipo: 'Sessão',        data: '1921-09-14', sessoesNumero: 2 },
  { id: 8,  teatroId: 102, tipo: 'Completo',     data: '1922-02-18', sessoesNumero: 1 },
  { id: 9,  teatroId: 106, tipo: 'Palco e Tela', data: '1923-06-25', sessoesNumero: 4 },
  { id: 10, teatroId: 101, tipo: 'Sessão',        data: '1924-10-03', sessoesNumero: 1 },
  { id: 11, teatroId: 103, tipo: 'Completo',     data: '1925-12-19', sessoesNumero: 2 },
  { id: 12, teatroId: 104, tipo: 'Sessão',        data: '1926-04-07', sessoesNumero: 1 },
  { id: 13, teatroId: 105, tipo: 'Completo',     data: '1927-08-21', sessoesNumero: 5 },
  { id: 14, teatroId: 106, tipo: 'Sessão',        data: '1929-01-15', sessoesNumero: 2 },
  { id: 15, teatroId: 102, tipo: 'Palco e Tela', data: '1930-03-29', sessoesNumero: 1 },
];

// Helper opcional: gerador procedural, caso queira testar volumes maiores
// (ex: 30+ linhas) sem digitar tudo manualmente.
export function gerarEspetaculosMock(quantidade = 15) {
  const pesosSessoes = [1, 1, 1, 2, 2, 2, 3, 4, 5]; // favorece 1–2
  const inicio = new Date('1914-01-01').getTime();
  const fim = new Date('1930-12-31').getTime();

  return Array.from({ length: quantidade }, (_, i) => {
    const teatro = TEATROS_MOCK[Math.floor(Math.random() * TEATROS_MOCK.length)];
    const tipo = TIPOS_ESPETACULO[Math.floor(Math.random() * TIPOS_ESPETACULO.length)];
    const dataAleatoria = new Date(inicio + Math.random() * (fim - inicio));
    const sessoes = pesosSessoes[Math.floor(Math.random() * pesosSessoes.length)];

    return {
      id: i + 1,
      teatroId: teatro.id,
      tipo,
      data: dataAleatoria.toISOString().slice(0, 10),
      sessoesNumero: sessoes,
    };
  });
}
