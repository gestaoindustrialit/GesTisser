const data = {
  clientes: [
    { nome: 'A. Costa & Silva, Lda', codigo: '034', nif: '500629196', pais: 'Portugal', morada: 'Rua das Bouças, 2 - Vila do Conde' },
    { nome: 'Bograo, SL', codigo: '227', nif: 'B15792625', pais: 'Espanha', morada: 'C/ Iglesias 14 - Merin' },
    { nome: 'Born to Grill', codigo: '424', nif: '898712799', pais: 'França', morada: '4 Impasse des Lilas' }
  ],
  artigos: [
    { codigo: 'RFBRLA476020', descricao: 'Rafia branca laminada 47cm, 60+20gr/m2', unidade: 'kg', stock: 84200 },
    { codigo: 'BPBRXX556020', descricao: '39+(2x8) x 80 districoal barbecue XXL', unidade: 'un', stock: 11200 },
    { codigo: 'RTFRLA506020', descricao: '38+(2x6) x 95 agribar 30kg', unidade: 'un', stock: 9500 }
  ],
  ofs: [
    { numero: '20260409', cliente: 'A. Costa & Silva, Lda', artigo: 'BPBRXX556020', descricao: 'Districoal Barbecue XXL', quantidade: 14000, produzida: 0, operacao: 'Impressão', estado: 'Planeada' },
    { numero: '20260408', cliente: 'Born to Grill', artigo: 'RTFRLA555522', descricao: '41+(2x7) x 70 Primenergia 10kg', quantidade: 5000, produzida: 1200, operacao: 'Laminação', estado: 'Em preparação' },
    { numero: '20260407', cliente: 'Bograo, SL', artigo: 'RFBRLA476020', descricao: 'Mistura de cereais', quantidade: 4000, produzida: 0, operacao: 'Corte e costura', estado: 'Planeada' }
  ],
  expedicao: [
    { guia: 'EXP-2026-071', cliente: 'Born to Grill', of: '20260408', volumes: 18, estado: 'A preparar' },
    { guia: 'EXP-2026-072', cliente: 'A. Costa & Silva, Lda', of: '20260409', volumes: 44, estado: 'Pendente' }
  ],
  operacoes: ['Extrusão', 'Laminação', 'Impressão', 'Corte e costura', 'Embalamento', 'Expedição'],
  presencas: [
    { colaborador: 'Fernanda Gomes', entrada: '21/07/2026 13:37', saida: '21/07/2026 23:00', horas: 9.37 },
    { colaborador: 'Rosa Couto', entrada: '21/07/2026 05:55', saida: '21/07/2026 14:04', horas: 8.47 }
  ],
  ausencias: [
    { colaborador: 'Diogo Ferreira', tipo: 'Férias', inicio: '03/08/2026', fim: '14/08/2026', estado: 'Aprovação' }
  ],
  colaboradores: [
    { nome: 'Patrícia', equipa: 'Turno A', funcao: 'Supervisora' },
    { nome: 'Bruna Varandas', equipa: 'Turno B', funcao: 'Operadora' }
  ],
  equipas: [
    { equipa: 'Turno A', responsavel: 'Patrícia', elementos: 8 },
    { equipa: 'Turno B', responsavel: 'Marta', elementos: 7 }
  ]
};

const labels = {
  clientes: ['ERP', 'Clientes'], artigos: ['ERP', 'Artigos'], ofs: ['Produção', 'Ordens de fabrico'],
  expedicao: ['Logística', 'Expedição'], operacoes: ['Produção', 'Operações'], presencas: ['RH', 'Presenças'],
  ausencias: ['RH', 'Comunicação de ausências'], colaboradores: ['RH', 'Colaboradores'], equipas: ['RH', 'Equipas'], shopfloor: ['Shopfloor', 'Iniciar OF por operação']
};

let selectedOperation = 'Impressão';
let selectedOf = data.ofs[0].numero;

const content = document.querySelector('#content');
const viewTitle = document.querySelector('#viewTitle');
const viewType = document.querySelector('#viewType');
const operationSelect = document.querySelector('#operationSelect');

data.operacoes.forEach((op) => operationSelect.add(new Option(op, op, op === selectedOperation, op === selectedOperation)));
operationSelect.addEventListener('change', (event) => { selectedOperation = event.target.value; render('shopfloor'); });
document.querySelectorAll('[data-view]').forEach((button) => button.addEventListener('click', () => render(button.dataset.view)));
document.querySelector('#printButton').addEventListener('click', () => window.print());

function render(view) {
  [viewType.textContent, viewTitle.textContent] = labels[view];
  document.querySelectorAll('[data-view]').forEach((button) => button.classList.toggle('active', button.dataset.view === view));
  if (view === 'shopfloor') return renderShopfloor();
  if (view === 'operacoes') return renderTable(data.operacoes.map((nome, ordem) => ({ ordem: ordem + 1, nome, posto: `PT-${String(ordem + 1).padStart(2, '0')}`, ativa: 'Sim' })));
  renderTable(data[view]);
}

function renderTable(rows) {
  if (!rows?.length) { content.innerHTML = '<p>Sem registos.</p>'; return; }
  const columns = Object.keys(rows[0]);
  content.innerHTML = `<table><thead><tr>${columns.map((col) => `<th>${title(col)}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${columns.map((col) => `<td>${row[col]}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}

function renderShopfloor() {
  const candidates = data.ofs.filter((of) => of.operacao === selectedOperation || of.estado !== 'Terminada');
  if (!candidates.find((of) => of.numero === selectedOf)) selectedOf = candidates[0]?.numero;
  const current = candidates.find((of) => of.numero === selectedOf);
  content.innerHTML = `<div class="shopfloor-panel"><aside>${candidates.map((of) => `<button class="of-card ${of.numero === selectedOf ? 'selected' : ''}" data-of="${of.numero}"><strong>OF ${of.numero}</strong><br>${of.descricao}<br><span>${of.operacao} · ${of.quantidade} un.</span></button>`).join('')}</aside><section>${current ? details(current) : '<p>Não existem OFs para iniciar.</p>'}</section></div>`;
  document.querySelectorAll('[data-of]').forEach((button) => button.addEventListener('click', () => { selectedOf = button.dataset.of; renderShopfloor(); }));
  document.querySelector('#startOf')?.addEventListener('click', () => {
    current.estado = 'Em curso';
    current.operacao = selectedOperation;
    renderShopfloor();
  });
}

function details(of) {
  return `<h3>Operação selecionada: ${selectedOperation}</h3><p>Confirme a OF e carregue em iniciar para abrir o trabalho neste posto.</p><table><tbody><tr><th>OF</th><td>${of.numero}</td></tr><tr><th>Cliente</th><td>${of.cliente}</td></tr><tr><th>Artigo</th><td>${of.artigo}</td></tr><tr><th>Quantidade</th><td>${of.quantidade}</td></tr><tr><th>Produzida</th><td>${of.produzida}</td></tr><tr><th>Estado</th><td><span class="status ${of.estado === 'Em curso' ? 'running' : ''}">${of.estado}</span></td></tr></tbody></table><button class="start-button" id="startOf">Iniciar OF ${of.numero} em ${selectedOperation}</button>`;
}

function title(value) { return value.replace(/([A-Z])/g, ' $1').replace(/^./, (char) => char.toUpperCase()); }

render('clientes');
