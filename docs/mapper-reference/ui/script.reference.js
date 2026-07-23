/* READ-ONLY REFERENCE: behaviours and component rendering from Company Mapper.
   DO NOT copy persistence, auth, localStorage, API or state management into GesTISSER.
   Reimplement behaviours against existing PHP/SQLite modules. */

(() => {
  'use strict';

  const STORAGE_KEY = 'tisser-company-mapper-v1';
  const ACCESS = window.TISSER_ACCESS || {role:'viewer',canEdit:false,apiUrl:'api.php',csrfToken:''};
  const CAN_EDIT = Boolean(ACCESS.canEdit);
  let serverRevision = 0;
  let serverSaveTimer = null;
  let serverSaveInFlight = false;
  let serverSavePending = false;
  let serverSaveBlocked = false;
  const viewMeta = {
    dashboard: ['CENTRO DE CONHECIMENTO', 'Visão geral'],
    org: ['PESSOAS E RESPONSABILIDADES', 'Organograma'],
    duties: ['FUNÇÕES E CONTINUIDADE', 'Cadernos de encargos'],
    flows: ['OPERAÇÕES E MELHORIA', 'Fluxos de produção'],
    calendar: ['PLANEAMENTO E RITMO', 'Calendário anual'],
    systems: ['TECNOLOGIA E CUSTOS', 'Sistemas e aplicações'],
    machines: ['ATIVOS PRODUTIVOS', 'Máquinas e equipamentos'],
    competencies: ['PESSOAS E CAPACIDADE', 'Matriz de competências'],
    improvements: ['MELHORIA CONTÍNUA', 'Melhorias identificadas'],
    data: ['PORTABILIDADE E SEGURANÇA', 'Dados e cópias']
  };
  const monthNames = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  const weekDays = ['S','T','Q','Q','S','S','D'];
  const nodeTypeLabels = { operation: 'Operação', decision: 'Decisão', control: 'Controlo', external: 'Entrada / saída' };
  const statusLabels = { active: 'Ativo', review: 'A rever', risk: 'Risco', inactive: 'Inativo', hypothesis: 'Hipótese', validated: 'Validado', draft: 'Rascunho' };
  const improvementAreaLabels = { production: 'Produção', process: 'Processos', supplier: 'Fornecedores' };
  const improvementPriorityLabels = { critical: 'Crítica', high: 'Alta', medium: 'Média', low: 'Baixa' };
  const improvementImpactLabels = { high: 'Alto', medium: 'Médio', low: 'Baixo' };
  const improvementStatusLabels = { identified: 'Identificada', analysis: 'Em análise', planned: 'Planeada', in_progress: 'Em execução', blocked: 'Bloqueada', implemented: 'Implementada', measured: 'Medida / concluída' };
  const shiftStatusLabels = { active: 'Ativo', inactive: 'Inativo' };
  const dutyStatusLabels = { draft: 'Rascunho', validated: 'Validado', review: 'A rever' };
  const machineStatusLabels = { operational:'Operacional', limited:'Limitada', maintenance:'Em manutenção', stopped:'Parada', inactive:'Desativada' };
  const machineCriticalityLabels = { high:'Alta', medium:'Média', low:'Baixa' };
  const competencyLevelLabels = { 0:'Sem conhecimento', 1:'Em formação', 2:'Com supervisão', 3:'Autónomo', 4:'Formador / especialista' };

  let state = null;
  let currentView = 'dashboard';
  let currentFlowId = '';
  let selectedPersonId = '';
  let selectedNodeId = '';
  let calendarYear = new Date().getFullYear();
  let connectMode = false;
  let connectSourceId = '';
  let dialogSubmitHandler = null;
  let confirmHandler = null;
  let dragState = null;
  let toastTimer = null;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const deepClone = value => JSON.parse(JSON.stringify(value));
  const uid = prefix => `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`;
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const normalise = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const formatCurrency = value => new Intl.NumberFormat('pt-PT', {style:'currency',currency:'EUR'}).format(Number(value) || 0);
  const formatDate = (value, options = {day:'2-digit',month:'short',year:'numeric'}) => value ? new Intl.DateTimeFormat('pt-PT', options).format(new Date(`${value}T12:00:00`)) : '—';
  const formatDateTime = value => value ? new Intl.DateTimeFormat('pt-PT', {dateStyle:'medium',timeStyle:'short'}).format(new Date(value)) : '—';
  const getPerson = id => state.people.find(person => person.id === id);
  const getDepartment = id => state.departments.find(department => department.id === id);
  const getShift = id => state.shifts.find(shift => shift.id === id);
  const getCalendarType = id => state.calendarTypes.find(type => type.id === id);
  const getFlow = () => state.flows.find(flow => flow.id === currentFlowId) || state.flows[0];
  const mapLogoPath = source => source === 'assets/logo-tisser-blue.png' ? 'app/logo.php?name=blue' : (source === 'assets/logo-tisser-green.png' ? 'app/logo.php?name=green' : source);
  const getCompanyLogoSource = () => mapLogoPath(state.company?.logoDataUrl || state.company?.logoPath || 'app/logo.php?name=blue');
  const getAbsoluteLogoSource = () => {
    const source = getCompanyLogoSource();
    if (/^(data:|https?:|blob:)/i.test(source)) return source;
    try { return new URL(source, document.baseURI).href; } catch { return source; }
  };

  async function initialise() {
    try {
      const response = await fetch(ACCESS.apiUrl, {cache:'no-store',credentials:'same-origin'});
      if (!response.ok) throw new Error('Falha ao carregar dados centrais');
      const payload = await response.json();
      if (!payload.ok || !payload.data) throw new Error(payload.error || 'Resposta inválida');
      state = payload.data;
      serverRevision = Number(payload.revision) || 0;
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (error) {
      const local = localStorage.getItem(STORAGE_KEY);
      if (local) { try { state = JSON.parse(local); } catch { state = null; } }
      state = state || deepClone(window.TISSER_INITIAL_DATA);
      setTimeout(() => showToast('Não foi possível ligar ao servidor. Foi carregada a cópia local.'), 350);
    }
    ensureSchema();
    currentFlowId = state.flows[0]?.id || '';
    calendarYear = Number(state.meta.year) || new Date().getFullYear();
    bindEvents();
    renderAll();
  }

  function ensureSchema() {
    state.meta ||= {};
    state.meta.schemaVersion = Math.max(Number(state.meta.schemaVersion) || 0, 5);
    state.company ||= {};
    state.company.name ||= state.meta.companyName || 'TISSER';
    state.company.address ||= '';
    state.company.contact ||= '';
    state.company.email ||= '';
    state.company.website ||= 'https://tisser.pt';
    state.company.footerNote ||= 'Documento interno · Organograma';
    state.company.logoPath ||= 'app/logo.php?name=blue';
    state.company.logoDataUrl ||= '';
    state.departments ||= [];
    state.shifts ||= [
      {id:'shift-admin',name:'Administrativo',code:'ADM',start:'09:00',end:'18:00',hoursPerDay:8,daysPerWeek:5,color:'#2D69A1',status:'active'},
      {id:'shift-1',name:'Turno 1',code:'T1',start:'06:00',end:'14:00',hoursPerDay:8,daysPerWeek:5,color:'#5EAD41',status:'active'},
      {id:'shift-2',name:'Turno 2',code:'T2',start:'14:00',end:'22:00',hoursPerDay:8,daysPerWeek:5,color:'#2D69A1',status:'active'},
      {id:'shift-3',name:'Turno 3',code:'T3',start:'22:00',end:'06:00',hoursPerDay:8,daysPerWeek:5,color:'#212124',status:'active'}
    ];
    state.people ||= [];
    state.people.forEach(person => {
      if (person.shiftId === undefined) person.shiftId = '';
      if (person.capacityPercent === undefined || person.capacityPercent === '') person.capacityPercent = 100;
      person.capacityPercent = Math.max(0, Number(person.capacityPercent) || 0);
    });
    state.flows ||= [];
    state.calendarTypes ||= [];
    state.calendarEvents ||= [];
    state.systems ||= [];
    state.improvements ||= [];
    state.machines ||= [];
    state.competencies ||= [];
    state.machines.forEach(machine => { machine.status ||= 'operational'; machine.criticality ||= 'medium'; machine.departmentId ||= ''; machine.ownerId ||= ''; });
    state.competencies.forEach(item => { item.level = Math.max(0, Math.min(4, Number(item.level) || 0)); item.personId ||= ''; item.machineId ||= ''; item.assessedAt ||= ''; item.expiryDate ||= ''; item.notes ||= ''; });
    state.dutySheets ||= [];
    state.dutySheets.forEach(sheet => {
      sheet.title ||= ''; sheet.departmentId ||= ''; sheet.responsibleId ||= ''; sheet.backupId ||= ''; sheet.secondaryBackupId ||= '';
      sheet.status ||= 'draft'; sheet.reviewDate ||= ''; sheet.purpose ||= ''; sheet.responsibilities ||= ''; sheet.dailyTasks ||= '';
      sheet.periodicTasks ||= ''; sheet.authority ||= ''; sheet.kpis ||= ''; sheet.systems ||= ''; sheet.absenceInstructions ||= '';
      sheet.documents ||= ''; sheet.notes ||= '';
    });
  }

  function saveState(message = 'Alterações registadas') {
    if (!CAN_EDIT) { showToast('A tua conta tem acesso apenas de consulta.'); return; }
    if (serverSaveBlocked) { showToast('Existe um conflito de edição. Atualize a página antes de continuar.'); return; }
    state.meta.lastUpdated = new Date().toISOString();
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    $('#saveStateText').textContent = 'A guardar no servidor…';
    $('#lastUpdated').textContent = formatDateTime(state.meta.lastUpdated);
    clearTimeout(serverSaveTimer);
    serverSaveTimer = setTimeout(persistStateToServer, 420);
    if (message) showToast(message);
  }

  async function persistStateToServer() {
    if (!CAN_EDIT || serverSaveBlocked) return;
    if (serverSaveInFlight) { serverSavePending = true; return; }
    serverSaveInFlight = true;
    serverSavePending = false;
    try {
      const response = await fetch(ACCESS.apiUrl, {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':ACCESS.csrfToken},
        body:JSON.stringify({revision:serverRevision,data:state})
      });
      const payload = await response.json().catch(() => ({}));
      if (response.status === 409) {
        serverSaveBlocked = true;
        $('#saveStateText').textContent = 'Conflito de edição';
        showToast(payload.error || 'Os dados foram alterados por outro utilizador. Atualize a página.');
        return;
      }
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Erro ao guardar');
      serverRevision = Number(payload.revision) || serverRevision;
      $('#saveStateText').textContent = 'Guardado no servidor';
    } catch (error) {
      $('#saveStateText').textContent = 'Erro ao guardar';
      showToast(error.message || 'Não foi possível guardar no servidor.');
    } finally {
      serverSaveInFlight = false;
      if (serverSavePending && !serverSaveBlocked) persistStateToServer();
    }
  }

  function renderAll() {
    renderNavigation();
    renderDashboard();
    renderOrg();
    renderDutySheets();
    renderFlowControls();
    renderFlow();
    renderCalendar();
    renderSystems();
    renderMachines();
    renderCompetencies();
    renderImprovements();
    renderCompanySettings();
    renderJsonPreview();
    $('#lastUpdated').textContent = formatDateTime(state.meta.lastUpdated);
    applyAccessMode();
  }

  function bindEvents() {
    $$('.nav-item').forEach(button => button.addEventListener('click', () => switchView(button.dataset.view)));
    $$('[data-jump]').forEach(button => button.addEventListener('click', () => switchView(button.dataset.jump)));
    $('#menuButton').addEventListener('click', () => $('#sidebar').classList.toggle('open'));
    $('#quickExportButton').addEventListener('click', exportData);
    $('#quickAddButton').addEventListener('click', handleQuickAdd);
    $('#globalSearch').addEventListener('input', handleGlobalSearch);
    $('#closeSearchButton').addEventListener('click', closeSearch);
    $('#searchOverlay').addEventListener('click', event => { if (event.target === $('#searchOverlay')) closeSearch(); });

    $('#orgDepartmentFilter').addEventListener('change', renderOrg);
    $('#orgShiftFilter').addEventListener('change', renderOrg);
    $('#orgShowInactive').addEventListener('change', renderOrg);
    $('#exportOrgPdfButton').addEventListener('click', exportOrgToPdf);
    $('#addShiftButton').addEventListener('click', () => openShiftForm());
    $('#addDepartmentButton').addEventListener('click', () => openDepartmentForm());
    $('#addPersonButton').addEventListener('click', () => openPersonForm());

    $('#dutySearch').addEventListener('input', renderDutySheets);
    $('#dutyDepartmentFilter').addEventListener('change', renderDutySheets);
    $('#dutyCoverageFilter').addEventListener('change', renderDutySheets);
    $('#dutyStatusFilter').addEventListener('change', renderDutySheets);
    $('#addDutySheetButton').addEventListener('click', () => openDutySheetForm());

    $('#flowSelector').addEventListener('change', event => { currentFlowId = event.target.value; selectedNodeId = ''; cancelConnect(); renderFlow(); });
    $('#addFlowButton').addEventListener('click', () => openFlowForm());
    $('#editFlowButton').addEventListener('click', () => openFlowForm(getFlow()));
    $('#addNodeButton').addEventListener('click', () => openNodeForm());
    $('#connectNodesButton').addEventListener('click', startConnect);
    $('#cancelConnectButton').addEventListener('click', cancelConnect);

    $('#prevYearButton').addEventListener('click', () => { calendarYear--; renderCalendar(); });
    $('#nextYearButton').addEventListener('click', () => { calendarYear++; renderCalendar(); });
    $('#todayYearButton').addEventListener('click', () => { calendarYear = new Date().getFullYear(); renderCalendar(); });
    $('#calendarTypeFilter').addEventListener('change', renderCalendar);
    $('#addCalendarEventButton').addEventListener('click', () => openCalendarEventForm());

    $('#systemSearch').addEventListener('input', renderSystems);
    $('#systemCategoryFilter').addEventListener('change', renderSystems);
    $('#addSystemButton').addEventListener('click', () => openSystemForm());

    $('#machineSearch').addEventListener('input', renderMachines);
    $('#machineStatusFilter').addEventListener('change', renderMachines);
    $('#machineDepartmentFilter').addEventListener('change', renderMachines);
    $('#addMachineButton').addEventListener('click', () => openMachineForm());

    $('#competencySearch').addEventListener('input', renderCompetencies);
    $('#competencyShiftFilter').addEventListener('change', renderCompetencies);
    $('#competencyDepartmentFilter').addEventListener('change', renderCompetencies);
    $('#competencyMachineFilter').addEventListener('change', renderCompetencies);
    $('#addCompetencyButton').addEventListener('click', () => openCompetencyForm());

    $('#improvementSearch').addEventListener('input', renderImprovements);
    $('#improvementAreaFilter').addEventListener('change', renderImprovements);
    $('#improvementStatusFilter').addEventListener('change', renderImprovements);
    $('#improvementPriorityFilter').addEventListener('change', renderImprovements);
    $('#addImprovementButton').addEventListener('click', () => openImprovementForm());
    $$('[data-improvement-area]').forEach(button => button.addEventListener('click', () => { $('#improvementAreaFilter').value = button.dataset.improvementArea; renderImprovements(); }));

    $('#saveCompanyDetailsButton').addEventListener('click', saveCompanyDetails);
    $('#companyLogoInput').addEventListener('change', handleCompanyLogoUpload);
    $$('[data-logo-preset]').forEach(button => button.addEventListener('click', () => selectLogoPreset(button.dataset.logoPreset)));

    $('#exportDataButton').addEventListener('click', exportData);
    $('#importDataInput').addEventListener('change', importData);
    $('#resetDataButton').addEventListener('click', confirmReset);
    $('#copyJsonButton').addEventListener('click', copyJson);
    $('#refreshJsonButton').addEventListener('click', renderJsonPreview);

    $('#dynamicForm').addEventListener('submit', event => {
      event.preventDefault();
      if (!dialogSubmitHandler) return;
      const values = Object.fromEntries(new FormData(event.currentTarget).entries());
      dialogSubmitHandler(values);
    });
    $$('.dialog-close').forEach(button => button.addEventListener('click', () => $('#formDialog').close()));
    $('#confirmDialog form').addEventListener('submit', event => { event.preventDefault(); if (confirmHandler) confirmHandler(); $('#confirmDialog').close(); });
    $('.confirm-close').addEventListener('click', () => $('#confirmDialog').close());
    window.addEventListener('resize', () => { if (currentView === 'flows') renderEdges(); });
  }

  function switchView(view) {
    currentView = view;
    $$('.nav-item').forEach(item => item.classList.toggle('active', item.dataset.view === view));
    $$('.view').forEach(section => section.classList.toggle('active', section.id === `view-${view}`));
    $('#pageKicker').textContent = viewMeta[view][0];
    $('#pageTitle').textContent = viewMeta[view][1];
    $('#sidebar').classList.remove('open');
    closeSearch();
    if (view === 'flows') requestAnimationFrame(renderEdges);
    if (view === 'duties') renderDutySheets();
    if (view === 'machines') renderMachines();
    if (view === 'competencies') renderCompetencies();
    if (view === 'improvements') renderImprovements();
    if (view === 'data') renderJsonPreview();
  }

  function renderNavigation() {
    $('#pageKicker').textContent = viewMeta[currentView][0];
    $('#pageTitle').textContent = viewMeta[currentView][1];
  }

  function renderDashboard() {
    const activePeople = state.people.filter(person => person.status !== 'inactive');
    const nodeCount = state.flows.reduce((sum, flow) => sum + flow.nodes.length, 0);
    const monthly = state.systems.reduce((sum, system) => sum + (system.billing === 'monthly' ? Number(system.cost || 0) : 0), 0);
    const today = new Date().toISOString().slice(0,10);
    const futureEvents = state.calendarEvents.filter(event => event.end >= today).sort((a,b) => a.start.localeCompare(b.start));

    $('#statPeople').textContent = activePeople.length;
    $('#statDepartments').textContent = `${state.departments.length} departamentos`;
    $('#statFlows').textContent = state.flows.length;
    $('#statFlowNodes').textContent = `${nodeCount} etapas mapeadas`;
    $('#statEvents').textContent = state.calendarEvents.length;
    $('#statNextEvent').textContent = futureEvents[0] ? `${formatDate(futureEvents[0].start, {day:'2-digit',month:'short'})} · ${futureEvents[0].title}` : 'Sem próximos eventos';
    $('#statSystems').textContent = state.systems.length;
    $('#statSystemCost').textContent = `${formatCurrency(monthly)} / mês`;
    const openImprovements = state.improvements.filter(item => !['implemented','measured'].includes(item.status));
    const urgentImprovements = openImprovements.filter(item => ['critical','high'].includes(item.priority));
    $('#statImprovements').textContent = openImprovements.length;
    $('#statPriorityImprovements').textContent = `${urgentImprovements.length} de prioridade alta/crítica`;
    const activeShifts = state.shifts.filter(shift => shift.status !== 'inactive');
    const totalDailyShiftHours = activeShifts.reduce((sum, shift) => sum + calculateShiftCapacity(shift).hoursDay, 0);
    $('#statShifts').textContent = activeShifts.length;
    $('#statShiftCapacity').textContent = `${formatNumber(totalDailyShiftHours)} h disponíveis / dia`;
    const dutyCount = state.dutySheets.length;
    const coveredDuties = state.dutySheets.filter(sheet => sheet.backupId || sheet.secondaryBackupId).length;
    const dutyCoverage = dutyCount ? Math.round(coveredDuties / dutyCount * 100) : 0;
    $('#statDutySheets').textContent = dutyCount;
    $('#statDutyCoverage').textContent = `${dutyCoverage}% com substituto`;

    $('#dashboardFlows').innerHTML = state.flows.slice(0,5).map(flow => {
      const completed = flow.nodes.filter(node => node.ownerId && node.kpi && node.system).length;
      const pct = flow.nodes.length ? Math.round(completed / flow.nodes.length * 100) : 0;
      return `<div class="flow-summary-item"><div><strong>${esc(flow.name)}</strong><small>${flow.nodes.length} etapas · ${esc(statusLabels[flow.status] || flow.status)}</small></div><div><div class="progress"><i style="width:${pct}%"></i></div><small>${pct}% detalhado</small></div></div>`;
    }).join('') || emptyInline('Ainda não existem fluxos.');

    $('#dashboardEvents').innerHTML = futureEvents.slice(0,5).map(event => {
      const date = new Date(`${event.start}T12:00:00`);
      return `<div class="timeline-item"><div class="date-box"><strong>${date.getDate()}</strong><span>${monthNames[date.getMonth()].slice(0,3)}</span></div><div><strong>${esc(event.title)}</strong><p>${esc(getCalendarType(event.typeId)?.name || 'Evento')}</p></div></div>`;
    }).join('') || emptyInline('Sem eventos futuros.');

    $('#dashboardCosts').innerHTML = [...state.systems].sort((a,b) => monthlyEquivalent(b) - monthlyEquivalent(a)).slice(0,5).map(system => `<div class="cost-row"><div><strong>${esc(system.name)}</strong><small>${esc(system.supplier || 'Fornecedor por validar')}</small></div><strong>${formatCurrency(monthlyEquivalent(system))}/mês</strong></div>`).join('') || emptyInline('Sem custos registados.');

    const leaders = activePeople.filter(person => !person.managerId || person.managerId === state.people.find(p => !p.managerId)?.id).slice(0,8);
    $('#dashboardLeaders').innerHTML = leaders.map(person => { const shift = getShift(person.shiftId); return `<div class="person-mini"><div class="avatar">${initials(person.name)}</div><div><strong>${esc(person.name)}</strong><small>${esc(person.title)}${shift ? ` · ${esc(shift.code || shift.name)}` : ''}</small></div></div>`; }).join('') || emptyInline('Defina os responsáveis de área.');
    renderShiftCapacity($('#dashboardShiftCapacity'), true);

    $('#dashboardDutySheets').innerHTML = [...state.dutySheets]
      .sort((a,b) => Number(Boolean(a.backupId || a.secondaryBackupId)) - Number(Boolean(b.backupId || b.secondaryBackupId)) || String(a.title).localeCompare(String(b.title), 'pt'))
      .slice(0,6)
      .map(sheet => {
        const responsible = getPerson(sheet.responsibleId);
        const backup = getPerson(sheet.backupId) || getPerson(sheet.secondaryBackupId);
        return `<button class="duty-summary-item ${backup ? 'covered' : 'uncovered'}" data-dashboard-duty="${esc(sheet.id)}"><span class="duty-coverage-icon">${backup ? '✓' : '!'}</span><div><strong>${esc(sheet.title)}</strong><small>${esc(responsible?.name || 'Responsável por definir')} · ${backup ? `Substituto: ${esc(backup.name)}` : 'Sem substituto definido'}</small></div><span class="duty-status ${esc(sheet.status)}">${esc(dutyStatusLabels[sheet.status] || sheet.status)}</span></button>`;
      }).join('') || emptyInline('Ainda não existem cadernos de função.');
    $$('[data-dashboard-duty]').forEach(button => button.addEventListener('click', () => { switchView('duties'); const sheet = state.dutySheets.find(item => item.id === button.dataset.dashboardDuty); $('#dutySearch').value = sheet?.title || ''; renderDutySheets(); }));

    $('#dashboardImprovements').innerHTML = [...state.improvements]
      .filter(item => !['implemented','measured'].includes(item.status))
      .sort(compareImprovements)
      .slice(0,6)
      .map(item => `<button class="improvement-summary-item" data-dashboard-improvement="${esc(item.id)}"><span class="area-pill ${esc(item.area)}">${esc(improvementAreaLabels[item.area] || item.area)}</span><div><strong>${esc(item.title)}</strong><small>${esc(getPerson(item.ownerId)?.name || 'Responsável por definir')} · ${esc(improvementStatusLabels[item.status] || item.status)}</small></div><span class="priority-pill ${esc(item.priority)}">${esc(improvementPriorityLabels[item.priority] || item.priority)}</span></button>`).join('') || emptyInline('Ainda não existem melhorias abertas.');
    $$('[data-dashboard-improvement]').forEach(button => button.addEventListener('click', () => { switchView('improvements'); $('#improvementSearch').value = state.improvements.find(item => item.id === button.dataset.dashboardImprovement)?.title || ''; renderImprovements(); }));
  }

  function renderOrg() {
    populateSelect($('#orgDepartmentFilter'), state.departments.map(dep => ({value:dep.id,label:dep.name})), $('#orgDepartmentFilter').value, 'Todos os departamentos');
    populateSelect($('#orgShiftFilter'), state.shifts.map(shift => ({value:shift.id,label:`${shift.code || shift.name} · ${shift.name}`})), $('#orgShiftFilter').value, 'Todos os turnos');
    const departmentFilter = $('#orgDepartmentFilter').value;
    const shiftFilter = $('#orgShiftFilter').value;
    const showInactive = $('#orgShowInactive').checked;
    let visible = state.people.filter(person => showInactive || person.status !== 'inactive');
    if (shiftFilter) {
      const directIds = new Set(visible.filter(person => person.shiftId === shiftFilter).map(person => person.id));
      directIds.forEach(id => {
        let person = getPerson(id);
        while (person?.managerId) { directIds.add(person.managerId); person = getPerson(person.managerId); }
      });
      visible = visible.filter(person => directIds.has(person.id));
    }
    if (departmentFilter) {
      const directIds = new Set(visible.filter(person => person.departmentId === departmentFilter).map(person => person.id));
      directIds.forEach(id => {
        let person = getPerson(id);
        while (person?.managerId) { directIds.add(person.managerId); person = getPerson(person.managerId); }
      });
      visible = visible.filter(person => directIds.has(person.id));
    }
    const visibleIds = new Set(visible.map(person => person.id));
    const roots = visible.filter(person => !person.managerId || !visibleIds.has(person.managerId));
    const chart = $('#orgChart');
    renderShiftCapacity($('#shiftCapacityCards'));
    if (!roots.length) { chart.innerHTML = emptyState('Sem pessoas', 'Adicione pessoas e relações de reporte para criar o organograma.'); renderOrgInspector(); return; }
    const levels = buildOrgPrintLevels(visible);
    chart.innerHTML = `<div class="org-browser-levels">${levels.map((people, index) => `<section class="org-browser-level"><div class="org-browser-level-heading"><span>Nível ${index + 1}</span><small>${people.length} pessoa${people.length === 1 ? '' : 's'}</small></div><div class="org-browser-grid">${people.map(person => renderOrgBrowserCard(person, visible)).join('')}</div></section>`).join('')}</div>`;
    $$('.org-card', chart).forEach(card => card.addEventListener('click', () => { selectedPersonId = card.dataset.id; renderOrg(); renderOrgInspector(); }));
    renderOrgInspector();
  }

  function renderOrgBranch(person, visiblePeople) {
    const department = getDepartment(person.departmentId);
    const shift = getShift(person.shiftId);
    const children = visiblePeople.filter(candidate => candidate.managerId === person.id);
    const shiftText = shift ? `${shift.code || shift.name} · ${shift.start || '—'}–${shift.end || '—'}` : 'Sem turno definido';
    return `<li><button class="org-card ${person.status === 'inactive' ? 'inactive' : ''} ${selectedPersonId === person.id ? 'selected' : ''}" data-id="${esc(person.id)}" style="--dept-color:${esc(department?.color || '#2D69A1')};--shift-color:${esc(shift?.color || '#9aa2a8')}"><span class="dept-line"></span><strong>${esc(person.name)}</strong><small>${esc(person.title)}</small><span class="org-shift"><i></i>${esc(shiftText)} · ${formatNumber(Number(person.capacityPercent ?? 100))}%</span><span class="org-dept">${esc(department?.name || 'Sem departamento')}</span></button>${children.length ? `<ul>${children.map(child => renderOrgBranch(child, visiblePeople)).join('')}</ul>` : ''}</li>`;
  }

  function renderOrgBrowserCard(person, visiblePeople) {
    const department = getDepartment(person.departmentId);
    const shift = getShift(person.shiftId);
    const manager = visiblePeople.find(candidate => candidate.id === person.managerId);
    const shiftText = shift ? `${shift.code || shift.name} · ${shift.start || '—'}–${shift.end || '—'}` : 'Sem turno definido';
    return `<button class="org-card org-browser-card ${person.status === 'inactive' ? 'inactive' : ''} ${selectedPersonId === person.id ? 'selected' : ''}" data-id="${esc(person.id)}" style="--dept-color:${esc(department?.color || '#2D69A1')};--shift-color:${esc(shift?.color || '#9aa2a8')}"><span class="dept-line"></span><strong>${esc(person.name)}</strong><small>${esc(person.title)}</small><span class="org-shift"><i></i>${esc(shiftText)} · ${formatNumber(Number(person.capacityPercent ?? 100))}%</span>${manager ? `<span class="org-manager">Reporta a: ${esc(manager.name)}</span>` : `<span class="org-manager">Topo da estrutura</span>`}<span class="org-dept">${esc(department?.name || 'Sem departamento')}</span></button>`;
  }


  function renderOrgInspector() {
    const container = $('#orgInspector');
    const person = getPerson(selectedPersonId);
    if (!person) { container.innerHTML = `<div class="empty-state"><div class="empty-icon">⌘</div><h3>Selecione uma pessoa</h3><p>Consulte funções, contacto, reporte e notas.</p></div>`; return; }
    const manager = getPerson(person.managerId);
    const department = getDepartment(person.departmentId);
    const shift = getShift(person.shiftId);
    const reports = state.people.filter(candidate => candidate.managerId === person.id).length;
    const capacity = Number(person.capacityPercent ?? 100);
    const dailyHours = shift ? Number(shift.hoursPerDay || 0) * capacity / 100 : 0;
    const personDutySheets = state.dutySheets.filter(sheet => sheet.responsibleId === person.id);
    container.innerHTML = `<div class="inspector-content"><span class="eyebrow">${esc(department?.name || 'SEM DEPARTAMENTO')}</span><h3>${esc(person.name)}</h3><div class="subtitle">${esc(person.title)}</div>
      <div class="inspector-row"><span>Reporta a</span><strong>${esc(manager ? `${manager.name} · ${manager.title}` : 'Direção / topo da estrutura')}</strong></div>
      <div class="inspector-row"><span>Equipa direta</span><strong>${reports} reporte${reports === 1 ? '' : 's'}</strong></div>
      <div class="inspector-row"><span>Turno</span><strong>${esc(shift ? `${shift.name} · ${shift.start}–${shift.end}` : 'Por definir')}</strong></div>
      <div class="inspector-row"><span>Capacidade</span><strong>${formatNumber(capacity)}% · ${formatNumber(dailyHours)} h/dia</strong></div>
      <div class="inspector-row"><span>Email</span><strong>${esc(person.email || 'Por preencher')}</strong></div>
      <div class="inspector-row"><span>Telefone</span><strong>${esc(person.phone || 'Por preencher')}</strong></div>
      <div class="inspector-row"><span>Notas</span><strong>${esc(person.notes || 'Sem notas')}</strong></div>
      <div class="inspector-row"><span>Caderno de função</span><strong>${personDutySheets.length ? `${personDutySheets.length} registo${personDutySheets.length === 1 ? '' : 's'}` : 'Ainda não criado'}</strong></div>
      <div class="inspector-actions"><button class="button primary" id="editSelectedPerson">Editar</button><button class="button ghost" id="openPersonDuties">${personDutySheets.length ? 'Abrir caderno' : 'Criar caderno'}</button><button class="button danger" id="deleteSelectedPerson">Eliminar</button></div></div>`;
    $('#editSelectedPerson').addEventListener('click', () => openPersonForm(person));
    $('#openPersonDuties').addEventListener('click', () => {
      if (personDutySheets.length) { switchView('duties'); $('#dutySearch').value = person.name; renderDutySheets(); }
      else openDutySheetForm(null, {title:person.title,departmentId:person.departmentId,responsibleId:person.id});
    });
    $('#deleteSelectedPerson').addEventListener('click', () => confirmDeletePerson(person));
  }


  function renderDutySheets() {
    if (!$('#dutySheetsGrid')) return;
    populateSelect($('#dutyDepartmentFilter'), state.departments.map(dep => ({value:dep.id,label:dep.name})), $('#dutyDepartmentFilter').value, 'Todos os departamentos');
    populateSelect($('#dutyStatusFilter'), Object.entries(dutyStatusLabels).map(([value,label]) => ({value,label})), $('#dutyStatusFilter').value, 'Todos os estados');
    const query = normalise($('#dutySearch').value.trim());
    const departmentId = $('#dutyDepartmentFilter').value;
    const coverage = $('#dutyCoverageFilter').value;
    const status = $('#dutyStatusFilter').value;
    const today = new Date().toISOString().slice(0,10);
    const sheets = state.dutySheets.filter(sheet => {
      const responsible = getPerson(sheet.responsibleId);
      const backup = getPerson(sheet.backupId);
      const secondary = getPerson(sheet.secondaryBackupId);
      const haystack = normalise([sheet.title,sheet.purpose,sheet.responsibilities,sheet.dailyTasks,sheet.periodicTasks,sheet.authority,sheet.kpis,sheet.systems,sheet.absenceInstructions,sheet.documents,sheet.notes,responsible?.name,responsible?.title,backup?.name,secondary?.name].join(' '));
      const hasBackup = Boolean(sheet.backupId || sheet.secondaryBackupId);
      return (!query || haystack.includes(query)) && (!departmentId || sheet.departmentId === departmentId) && (!status || sheet.status === status) && (!coverage || (coverage === 'covered' ? hasBackup : !hasBackup));
    }).sort((a,b) => Number(Boolean(a.backupId || a.secondaryBackupId)) - Number(Boolean(b.backupId || b.secondaryBackupId)) || String(a.title).localeCompare(String(b.title), 'pt'));

    $('#dutyTotalCount').textContent = state.dutySheets.length;
    $('#dutyValidatedCount').textContent = state.dutySheets.filter(sheet => sheet.status === 'validated').length;
    $('#dutyUncoveredCount').textContent = state.dutySheets.filter(sheet => !(sheet.backupId || sheet.secondaryBackupId)).length;
    $('#dutyReviewDueCount').textContent = state.dutySheets.filter(sheet => sheet.reviewDate && sheet.reviewDate < today).length;

    $('#dutySheetsGrid').innerHTML = sheets.map(sheet => {
      const department = getDepartment(sheet.departmentId);
      const responsible = getPerson(sheet.responsibleId);
      const backup = getPerson(sheet.backupId);
      const secondary = getPerson(sheet.secondaryBackupId);
      const hasBackup = Boolean(backup || secondary);
      const responsibilities = splitLines(sheet.responsibilities).slice(0,3);
      return `<article class="duty-card" style="--dept-color:${esc(department?.color || '#2D69A1')}">
        <div class="duty-card-top"><span class="duty-department">${esc(department?.name || 'Sem departamento')}</span><span class="duty-status ${esc(sheet.status)}">${esc(dutyStatusLabels[sheet.status] || sheet.status)}</span></div>
        <h3>${esc(sheet.title || 'Função sem título')}</h3>
        <p class="duty-purpose">${esc(sheet.purpose || 'Objetivo da função por preencher.')}</p>
        <div class="duty-people-grid">
          <div><span>Responsável</span><strong>${esc(responsible ? `${responsible.name} · ${responsible.title}` : 'Por definir')}</strong></div>
          <div class="${hasBackup ? 'covered' : 'uncovered'}"><span>Na ausência</span><strong>${esc(backup ? `${backup.name} · ${backup.title}` : secondary ? `${secondary.name} · ${secondary.title}` : 'Sem substituto definido')}</strong>${secondary && backup ? `<small>2.º substituto: ${esc(secondary.name)}</small>` : ''}</div>
        </div>
        <div class="duty-responsibilities"><span>Responsabilidades principais</span>${responsibilities.length ? `<ul>${responsibilities.map(item => `<li>${esc(item)}</li>`).join('')}</ul>` : '<p>Por preencher</p>'}</div>
        <div class="duty-meta"><span>Revisão: <strong>${formatDate(sheet.reviewDate)}</strong></span><span>${hasBackup ? 'Cobertura definida' : 'Risco de continuidade'}</span></div>
        <div class="duty-actions"><button class="button ghost" data-print-duty="${esc(sheet.id)}">PDF</button><button class="button primary" data-edit-duty="${esc(sheet.id)}">Editar</button><button class="button danger" data-delete-duty="${esc(sheet.id)}">Eliminar</button></div>
      </article>`;
    }).join('') || `<div class="panel duty-empty">${emptyState('Sem cadernos encontrados', 'Crie um caderno para clarificar responsabilidades e definir quem assegura a função durante ausências.')}</div>`;
    $$('[data-edit-duty]').forEach(button => button.addEventListener('click', () => openDutySheetForm(state.dutySheets.find(sheet => sheet.id === button.dataset.editDuty))));
    $$('[data-delete-duty]').forEach(button => button.addEventListener('click', () => confirmDeleteDutySheet(state.dutySheets.find(sheet => sheet.id === button.dataset.deleteDuty))));
    $$('[data-print-duty]').forEach(button => button.addEventListener('click', () => exportDutySheetToPdf(state.dutySheets.find(sheet => sheet.id === button.dataset.printDuty))));
  }

  function renderFlowControls() {
    const selector = $('#flowSelector');
    selector.innerHTML = state.flows.map(flow => `<option value="${esc(flow.id)}">${esc(flow.name)}</option>`).join('');
    if (!state.flows.some(flow => flow.id === currentFlowId)) currentFlowId = state.flows[0]?.id || '';
    selector.value = currentFlowId;
  }

  function renderFlow() {
    renderFlowControls();
    const flow = getFlow();
    const nodesContainer = $('#flowNodes');
    if (!flow) {
      $('#flowTitle').textContent = 'Sem fluxos';
      $('#flowDescription').textContent = 'Crie o primeiro fluxo de produção.';
      $('#flowStatusText').textContent = 'FLUXO';
      nodesContainer.innerHTML = '';
      $('#flowEdges').innerHTML = '';
      renderFlowInspector();
      return;
    }
    $('#flowTitle').textContent = flow.name;
    $('#flowDescription').textContent = flow.description || 'Sem descrição.';
    $('#flowStatusText').textContent = `${statusLabels[flow.status] || flow.status} · V${flow.version || '1.0'}`.toUpperCase();
    nodesContainer.innerHTML = flow.nodes.map(node => {
      const owner = getPerson(node.ownerId);
      return `<article class="flow-node ${esc(node.type)} ${selectedNodeId === node.id ? 'selected' : ''} ${connectSourceId === node.id ? 'connect-source' : ''}" data-id="${esc(node.id)}" style="left:${Number(node.x)||0}px;top:${Number(node.y)||0}px"><span class="node-type">${esc(nodeTypeLabels[node.type] || node.type)}</span><strong>${esc(node.label)}</strong><small>${esc(owner?.name || 'Sem responsável')}</small>${node.kpi ? `<div class="node-kpi">KPI · ${esc(node.kpi)}</div>` : ''}</article>`;
    }).join('');
    $$('.flow-node', nodesContainer).forEach(node => {
      node.addEventListener('pointerdown', beginNodeDrag);
      node.addEventListener('click', handleNodeClick);
      node.addEventListener('dblclick', () => openNodeForm(flow.nodes.find(item => item.id === node.dataset.id)));
    });
    $('#connectBanner').classList.toggle('hidden', !connectMode);
    requestAnimationFrame(renderEdges);
    renderFlowInspector();
  }

  function renderEdges() {
    const flow = getFlow();
    const svg = $('#flowEdges');
    if (!flow) { svg.innerHTML = ''; return; }
    const parts = [`<defs><marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto"><polygon points="0 0, 10 3.5, 0 7" fill="#8a968d"></polygon></marker></defs>`];
    flow.edges.forEach(edge => {
      const from = flow.nodes.find(node => node.id === edge.from);
      const to = flow.nodes.find(node => node.id === edge.to);
      if (!from || !to) return;
      const x1 = Number(from.x) + 190, y1 = Number(from.y) + 46, x2 = Number(to.x), y2 = Number(to.y) + 46;
      const curve = Math.max(55, Math.abs(x2 - x1) * .45);
      const path = `M ${x1} ${y1} C ${x1 + curve} ${y1}, ${x2 - curve} ${y2}, ${x2} ${y2}`;
      parts.push(`<path class="flow-edge" d="${path}"></path>`);
      if (edge.label) parts.push(`<text class="flow-edge-label" x="${(x1+x2)/2}" y="${(y1+y2)/2 - 8}" text-anchor="middle">${esc(edge.label)}</text>`);
    });
    svg.innerHTML = parts.join('');
  }

  function renderFlowInspector() {
    const container = $('#flowInspector');
    const flow = getFlow();
    const node = flow?.nodes.find(item => item.id === selectedNodeId);
    if (!node) { container.innerHTML = `<div class="empty-state"><div class="empty-icon">⇢</div><h3>Selecione uma etapa</h3><p>Edite responsável, duração, sistema, KPI e observações.</p></div>`; return; }
    const owner = getPerson(node.ownerId);
    const incoming = flow.edges.filter(edge => edge.to === node.id).length;
    const outgoing = flow.edges.filter(edge => edge.from === node.id).length;
    container.innerHTML = `<div class="inspector-content"><span class="eyebrow">${esc(nodeTypeLabels[node.type] || node.type)}</span><h3>${esc(node.label)}</h3><div class="subtitle">${esc(owner?.name || 'Sem responsável')}</div>
      <div class="inspector-row"><span>Duração / prazo</span><strong>${esc(node.duration || 'Por definir')}</strong></div>
      <div class="inspector-row"><span>Sistema / suporte</span><strong>${esc(node.system || 'Por definir')}</strong></div>
      <div class="inspector-row"><span>KPI</span><strong>${esc(node.kpi || 'Por definir')}</strong></div>
      <div class="inspector-row"><span>Ligações</span><strong>${incoming} entrada(s) · ${outgoing} saída(s)</strong></div>
      <div class="inspector-row"><span>Notas</span><strong>${esc(node.notes || 'Sem notas')}</strong></div>
      <div class="inspector-actions"><button class="button primary" id="editSelectedNode">Editar</button><button class="button danger" id="deleteSelectedNode">Eliminar</button></div></div>`;
    $('#editSelectedNode').addEventListener('click', () => openNodeForm(node));
    $('#deleteSelectedNode').addEventListener('click', () => confirmDeleteNode(node));
  }

  function beginNodeDrag(event) {
    if (!CAN_EDIT) return;
    if (connectMode || event.button !== 0) return;
    const element = event.currentTarget;
    const flow = getFlow();
    const node = flow.nodes.find(item => item.id === element.dataset.id);
    dragState = { element, node, startX:event.clientX, startY:event.clientY, originalX:Number(node.x), originalY:Number(node.y), moved:false };
    element.setPointerCapture(event.pointerId);
    element.addEventListener('pointermove', moveNodeDrag);
    element.addEventListener('pointerup', endNodeDrag, {once:true});
  }

  function moveNodeDrag(event) {
    if (!dragState) return;
    const dx = event.clientX - dragState.startX, dy = event.clientY - dragState.startY;
    if (Math.abs(dx) + Math.abs(dy) > 4) dragState.moved = true;
    dragState.node.x = Math.max(10, Math.round(dragState.originalX + dx));
    dragState.node.y = Math.max(10, Math.round(dragState.originalY + dy));
    dragState.element.style.left = `${dragState.node.x}px`;
    dragState.element.style.top = `${dragState.node.y}px`;
    renderEdges();
  }

  function endNodeDrag(event) {
    if (!dragState) return;
    dragState.element.removeEventListener('pointermove', moveNodeDrag);
    if (dragState.moved) saveState('Posição da etapa atualizada');
    setTimeout(() => { dragState = null; }, 0);
  }

  function handleNodeClick(event) {
    if (dragState?.moved) return;
    const id = event.currentTarget.dataset.id;
    if (connectMode) {
      if (!connectSourceId) { connectSourceId = id; renderFlow(); return; }
      if (connectSourceId === id) { showToast('Escolha uma etapa diferente'); return; }
      const flow = getFlow();
      if (flow.edges.some(edge => edge.from === connectSourceId && edge.to === id)) { showToast('Esta ligação já existe'); return; }
      flow.edges.push({id:uid('edge'), from:connectSourceId, to:id, label:''});
      saveState('Etapas ligadas');
      cancelConnect();
      renderFlow();
      return;
    }
    selectedNodeId = id;
    renderFlow();
  }

  function startConnect() {
    if (!CAN_EDIT) { showToast('Acesso apenas de consulta.'); return; }
    if (!getFlow()?.nodes.length) { showToast('Adicione pelo menos duas etapas'); return; }
    connectMode = true; connectSourceId = ''; renderFlow();
  }
  function cancelConnect() { connectMode = false; connectSourceId = ''; $('#connectBanner')?.classList.add('hidden'); }

  function renderCalendar() {
    $('#calendarYear').textContent = calendarYear;
    populateSelect($('#calendarTypeFilter'), state.calendarTypes.map(type => ({value:type.id,label:type.name})), $('#calendarTypeFilter').value, 'Todos os tipos');
    const filter = $('#calendarTypeFilter').value;
    const events = state.calendarEvents.filter(event => event.start.startsWith(String(calendarYear)) && (!filter || event.typeId === filter)).sort((a,b) => a.start.localeCompare(b.start));
    $('#yearGrid').innerHTML = monthNames.map((name, monthIndex) => renderMonth(monthIndex, events)).join('');
    $$('.calendar-day[data-date]').forEach(day => day.addEventListener('click', () => openCalendarEventForm(null, day.dataset.date)));
    $('#calendarEventList').innerHTML = events.map(event => {
      const type = getCalendarType(event.typeId);
      return `<div class="event-list-item"><span class="event-color" style="--event-color:${esc(type?.color || '#2f7353')}"></span><div><strong>${esc(event.title)}</strong><small>${formatDate(event.start)}${event.end !== event.start ? ` — ${formatDate(event.end)}` : ''}<br>${esc(type?.name || 'Evento')}</small></div><button data-event-id="${esc(event.id)}" title="Editar">✎</button></div>`;
    }).join('') || emptyInline('Sem eventos para este ano.');
    $$('[data-event-id]', $('#calendarEventList')).forEach(button => button.addEventListener('click', () => openCalendarEventForm(state.calendarEvents.find(event => event.id === button.dataset.eventId))));
  }

  function renderMonth(monthIndex, events) {
    const first = new Date(calendarYear, monthIndex, 1);
    const startOffset = (first.getDay() + 6) % 7;
    const daysInMonth = new Date(calendarYear, monthIndex + 1, 0).getDate();
    const previousDays = new Date(calendarYear, monthIndex, 0).getDate();
    const today = new Date().toISOString().slice(0,10);
    const cells = [];
    for (let i = 0; i < 42; i++) {
      let day, other = false, date;
      if (i < startOffset) { day = previousDays - startOffset + i + 1; other = true; date = localDateString(new Date(calendarYear, monthIndex - 1, day)); }
      else if (i >= startOffset + daysInMonth) { day = i - startOffset - daysInMonth + 1; other = true; date = localDateString(new Date(calendarYear, monthIndex + 1, day)); }
      else { day = i - startOffset + 1; date = localDateString(new Date(calendarYear, monthIndex, day)); }
      const dayEvents = events.filter(event => event.start <= date && event.end >= date);
      const type = dayEvents[0] ? getCalendarType(dayEvents[0].typeId) : null;
      cells.push(`<button class="calendar-day ${other ? 'other' : ''} ${today === date ? 'today' : ''} ${dayEvents.length ? 'has-events' : ''}" data-date="${date}" title="${esc(dayEvents.map(event => event.title).join(' · '))}" style="--event-color:${esc(type?.color || '#2f7353')}">${day}</button>`);
    }
    return `<article class="month-card"><div class="month-title">${monthNames[monthIndex]}</div><div class="month-weekdays">${weekDays.map(day => `<span>${day}</span>`).join('')}</div><div class="month-days">${cells.join('')}</div></article>`;
  }


  function renderMachines() {
    populateSelect($('#machineStatusFilter'), Object.entries(machineStatusLabels).map(([value,label]) => ({value,label})), $('#machineStatusFilter').value, 'Todos os estados');
    populateSelect($('#machineDepartmentFilter'), state.departments.map(dep => ({value:dep.id,label:dep.name})), $('#machineDepartmentFilter').value, 'Todos os departamentos');
    const query = normalise($('#machineSearch').value);
    const status = $('#machineStatusFilter').value;
    const departmentId = $('#machineDepartmentFilter').value;
    const machines = state.machines.filter(machine => (!status || machine.status === status) && (!departmentId || machine.departmentId === departmentId) && (!query || normalise([machine.code,machine.name,machine.brand,machine.model,machine.serialNumber,machine.location,machine.supplier,machine.notes].join(' ')).includes(query)));
    $('#machineTotalCount').textContent = state.machines.length;
    $('#machineActiveCount').textContent = state.machines.filter(m => m.status === 'operational').length;
    $('#machineCriticalCount').textContent = state.machines.filter(m => m.criticality === 'high').length;
    $('#machineNoOwnerCount').textContent = state.machines.filter(m => !m.ownerId).length;
    $('#machinesGrid').innerHTML = machines.map(machine => {
      const owner = getPerson(machine.ownerId); const dep = getDepartment(machine.departmentId);
      const autonomous = state.competencies.filter(c => c.machineId === machine.id && Number(c.level) >= 3).length;
      return `<article class="machine-card"><div class="machine-card-head"><div><span class="badge ${esc(machine.status)}">${esc(machineStatusLabels[machine.status] || machine.status)}</span><h3>${esc(machine.code ? machine.code+' · ' : '')}${esc(machine.name)}</h3><small>${esc([machine.brand,machine.model].filter(Boolean).join(' · ') || 'Marca e modelo por definir')}</small></div><span class="criticality ${esc(machine.criticality)}">${esc(machineCriticalityLabels[machine.criticality] || machine.criticality)}</span></div><div class="machine-meta"><div><span>Localização</span><strong>${esc(machine.location || dep?.name || 'Por definir')}</strong></div><div><span>Responsável</span><strong>${esc(owner?.name || 'Por definir')}</strong></div><div><span>Capacidade nominal</span><strong>${esc(machine.capacity || 'Por definir')}</strong></div><div><span>Operadores autónomos</span><strong>${autonomous}</strong></div><div><span>N.º série</span><strong>${esc(machine.serialNumber || '—')}</strong></div><div><span>Próxima manutenção</span><strong>${formatDate(machine.nextMaintenance)}</strong></div></div><p>${esc(machine.notes || 'Sem observações.')}</p><div class="machine-actions"><button class="button ghost" data-machine-competencies="${esc(machine.id)}">Competências</button><button class="button primary" data-edit-machine="${esc(machine.id)}">Editar</button><button class="button danger" data-delete-machine="${esc(machine.id)}">Eliminar</button></div></article>`;
    }).join('') || `<div class="panel">${emptyState('Sem máquinas encontradas','Adicione o primeiro equipamento produtivo.')}</div>`;
    $$('[data-edit-machine]').forEach(b => b.addEventListener('click', () => openMachineForm(state.machines.find(m => m.id === b.dataset.editMachine))));
    $$('[data-delete-machine]').forEach(b => b.addEventListener('click', () => confirmDeleteMachine(state.machines.find(m => m.id === b.dataset.deleteMachine))));
    $$('[data-machine-competencies]').forEach(b => b.addEventListener('click', () => { switchView('competencies'); $('#competencyMachineFilter').value=b.dataset.machineCompetencies; renderCompetencies(); }));
  }

  function renderCompetencies() {
    populateSelect($('#competencyShiftFilter'), state.shifts.map(s => ({value:s.id,label:`${s.code || s.name} · ${s.name}`})), $('#competencyShiftFilter').value, 'Todos os turnos');
    populateSelect($('#competencyDepartmentFilter'), state.departments.map(dep => ({value:dep.id,label:dep.name})), $('#competencyDepartmentFilter').value, 'Todos os departamentos');
    populateSelect($('#competencyMachineFilter'), state.machines.map(m => ({value:m.id,label:`${m.code ? m.code+' · ' : ''}${m.name}`})), $('#competencyMachineFilter').value, 'Todas as máquinas');
    const query=normalise($('#competencySearch').value), shiftId=$('#competencyShiftFilter').value, departmentId=$('#competencyDepartmentFilter').value, machineId=$('#competencyMachineFilter').value;
    const rows=state.competencies.filter(c => { const p=getPerson(c.personId), m=state.machines.find(x=>x.id===c.machineId); return p&&m&&(!shiftId||p.shiftId===shiftId)&&(!departmentId||p.departmentId===departmentId)&&(!machineId||c.machineId===machineId)&&(!query||normalise([p.name,p.title,m.name,m.code,competencyLevelLabels[c.level],c.notes].join(' ')).includes(query)); });
    $('#competencyTotalCount').textContent=state.competencies.length;
    $('#competencyAutonomousCount').textContent=state.competencies.filter(c=>Number(c.level)>=3).length;
    $('#competencyTrainingCount').textContent=state.competencies.filter(c=>[1,2].includes(Number(c.level))).length;
    $('#competencyUncoveredMachines').textContent=state.machines.filter(m=>!state.competencies.some(c=>c.machineId===m.id&&Number(c.level)>=3)).length;
    $('#competencyTableBody').innerHTML=rows.map(c=>{ const p=getPerson(c.personId),m=state.machines.find(x=>x.id===c.machineId),shift=getShift(p.shiftId); return `<tr><td><strong>${esc(p.name)}</strong><small>${esc(p.title)}</small></td><td>${esc(shift?.code||shift?.name||'—')}</td><td><strong>${esc(m.code?m.code+' · ':'')}${esc(m.name)}</strong><small>${esc([m.brand,m.model].filter(Boolean).join(' · '))}</small></td><td><span class="level-pill level-${Number(c.level)}">${Number(c.level)} · ${esc(competencyLevelLabels[c.level])}</span></td><td>${formatDate(c.assessedAt)}</td><td>${formatDate(c.expiryDate)}</td><td>${esc(c.notes||'—')}</td><td><div class="row-actions"><button data-edit-competency="${esc(c.id)}">✎</button><button data-delete-competency="${esc(c.id)}">×</button></div></td></tr>`;}).join('')||'<tr><td colspan="8">Sem competências para os filtros selecionados.</td></tr>';
    $$('[data-edit-competency]').forEach(b=>b.addEventListener('click',()=>openCompetencyForm(state.competencies.find(c=>c.id===b.dataset.editCompetency))));
    $$('[data-delete-competency]').forEach(b=>b.addEventListener('click',()=>confirmDeleteCompetency(state.competencies.find(c=>c.id===b.dataset.deleteCompetency))));
  }

  function renderSystems() {
    const categoryOptions = [...new Set(state.systems.map(system => system.category).filter(Boolean))].sort().map(category => ({value:category,label:category}));
    populateSelect($('#systemCategoryFilter'), categoryOptions, $('#systemCategoryFilter').value, 'Todas as categorias');
    const query = normalise($('#systemSearch').value);
    const category = $('#systemCategoryFilter').value;
    const systems = state.systems.filter(system => (!category || system.category === category) && (!query || normalise([system.name,system.category,system.supplier,getPerson(system.ownerId)?.name,system.notes].join(' ')).includes(query)));
    const monthly = state.systems.reduce((sum, system) => sum + (system.billing === 'monthly' ? Number(system.cost || 0) : 0), 0);
    const annual = state.systems.reduce((sum, system) => sum + (system.billing === 'monthly' ? Number(system.cost || 0) * 12 : Number(system.cost || 0)), 0);
    const in90 = new Date(); in90.setDate(in90.getDate()+90); const in90String = localDateString(in90); const today = localDateString(new Date());
    $('#monthlySystemCost').textContent = formatCurrency(monthly);
    $('#annualSystemCost').textContent = formatCurrency(annual);
    $('#renewalsCount').textContent = state.systems.filter(system => system.renewal && system.renewal >= today && system.renewal <= in90String).length;
    $('#systemsWithoutOwner').textContent = state.systems.filter(system => !system.ownerId).length;
    $('#systemsTableBody').innerHTML = systems.map(system => `<tr><td><strong>${esc(system.name)}</strong><small>${esc(system.url || '')}</small></td><td>${esc(system.category || '—')}</td><td>${esc(system.supplier || '—')}</td><td>${esc(getPerson(system.ownerId)?.name || 'Por definir')}</td><td>${Number(system.users)||0}</td><td><strong>${formatCurrency(system.cost)}</strong><small>${system.billing === 'monthly' ? 'mensal' : 'anual'}</small></td><td>${formatDate(system.renewal)}</td><td><span class="badge ${esc(system.status)}">${esc(statusLabels[system.status] || system.status)}</span></td><td><div class="row-actions"><button data-edit-system="${esc(system.id)}" title="Editar">✎</button><button data-delete-system="${esc(system.id)}" title="Eliminar">×</button></div></td></tr>`).join('') || `<tr><td colspan="9">${emptyInline('Nenhum sistema encontrado.')}</td></tr>`;
    $$('[data-edit-system]').forEach(button => button.addEventListener('click', () => openSystemForm(state.systems.find(system => system.id === button.dataset.editSystem))));
    $$('[data-delete-system]').forEach(button => button.addEventListener('click', () => confirmDeleteSystem(state.systems.find(system => system.id === button.dataset.deleteSystem))));
  }

  function renderImprovements() {
    populateSelect($('#improvementAreaFilter'), Object.entries(improvementAreaLabels).map(([value,label]) => ({value,label})), $('#improvementAreaFilter').value, 'Todas as áreas');
    populateSelect($('#improvementStatusFilter'), Object.entries(improvementStatusLabels).map(([value,label]) => ({value,label})), $('#improvementStatusFilter').value, 'Todos os estados');
    populateSelect($('#improvementPriorityFilter'), Object.entries(improvementPriorityLabels).map(([value,label]) => ({value,label})), $('#improvementPriorityFilter').value, 'Todas as prioridades');

    const query = normalise($('#improvementSearch').value);
    const area = $('#improvementAreaFilter').value;
    const status = $('#improvementStatusFilter').value;
    const priority = $('#improvementPriorityFilter').value;
    const rows = state.improvements
      .filter(item => (!area || item.area === area) && (!status || item.status === status) && (!priority || item.priority === priority))
      .filter(item => !query || normalise([item.title,item.description,item.supplier,item.expectedBenefit,item.evidence,getPerson(item.ownerId)?.name,getFlowById(item.flowId)?.name].join(' ')).includes(query))
      .sort(compareImprovements);

    ['production','process','supplier'].forEach(key => {
      const element = $(`#${key}ImprovementCount`);
      if (element) element.textContent = state.improvements.filter(item => item.area === key).length;
    });
    const open = state.improvements.filter(item => !['implemented','measured'].includes(item.status));
    $('#openImprovementsCount').textContent = open.length;
    $('#highImprovementsCount').textContent = open.filter(item => ['critical','high'].includes(item.priority)).length;
    $('#doingImprovementsCount').textContent = state.improvements.filter(item => item.status === 'in_progress').length;
    $('#implementedImprovementsCount').textContent = state.improvements.filter(item => ['implemented','measured'].includes(item.status)).length;

    $('#improvementsTableBody').innerHTML = rows.map(item => {
      const flow = getFlowById(item.flowId);
      const relation = item.supplier || flow?.name || '—';
      return `<tr>
        <td class="improvement-title-cell"><strong>${esc(item.title)}</strong><small>${esc(item.description || 'Sem descrição')}</small></td>
        <td><span class="area-pill ${esc(item.area)}">${esc(improvementAreaLabels[item.area] || item.area)}</span></td>
        <td><span class="priority-pill ${esc(item.priority)}">${esc(improvementPriorityLabels[item.priority] || item.priority)}</span></td>
        <td><strong>${esc(improvementImpactLabels[item.impact] || item.impact || '—')}</strong><small>impacto · ${esc(improvementImpactLabels[item.effort] || item.effort || '—')} esforço</small></td>
        <td>${esc(getPerson(item.ownerId)?.name || 'Por definir')}</td>
        <td class="relation-cell">${esc(relation)}</td>
        <td>${formatDate(item.dueDate)}</td>
        <td><span class="improvement-status ${esc(item.status)}">${esc(improvementStatusLabels[item.status] || item.status)}</span></td>
        <td><div class="row-actions"><button data-edit-improvement="${esc(item.id)}" title="Editar">✎</button><button data-delete-improvement="${esc(item.id)}" title="Eliminar">×</button></div></td>
      </tr>`;
    }).join('') || `<tr><td colspan="9">${emptyInline('Nenhuma melhoria encontrada.')}</td></tr>`;
    $$('[data-edit-improvement]').forEach(button => button.addEventListener('click', () => openImprovementForm(state.improvements.find(item => item.id === button.dataset.editImprovement))));
    $$('[data-delete-improvement]').forEach(button => button.addEventListener('click', () => confirmDeleteImprovement(state.improvements.find(item => item.id === button.dataset.deleteImprovement))));
  }

  function renderJsonPreview() {
    const preview = deepClone(state);
    if (preview.company?.logoDataUrl) preview.company.logoDataUrl = `[imagem incorporada · ${Math.round(preview.company.logoDataUrl.length / 1024)} KB]`;
    $('#jsonPreview').textContent = JSON.stringify(preview, null, 2);
  }

  function renderCompanySettings() {
    if (!$('#companyNameInput')) return;
    $('#companyNameInput').value = state.company.name || '';
    $('#companyAddressInput').value = state.company.address || '';
    $('#companyContactInput').value = state.company.contact || '';
    $('#companyEmailInput').value = state.company.email || '';
    $('#companyWebsiteInput').value = state.company.website || '';
    $('#companyFooterNoteInput').value = state.company.footerNote || '';
    const logo = getCompanyLogoSource();
    $('#companyLogoPreview').src = logo;
    $('#sidebarLogo').src = logo;
    $('#sidebarLogo').alt = `Logótipo ${state.company.name || 'TISSER'}`;
    $$('[data-logo-preset]').forEach(button => button.classList.toggle('active', !state.company.logoDataUrl && state.company.logoPath === button.dataset.logoPreset));
  }

  function saveCompanyDetails() {
    if (!CAN_EDIT) { showToast('Acesso apenas de consulta.'); return; }
    Object.assign(state.company, {
      name: $('#companyNameInput').value.trim() || 'TISSER',
      address: $('#companyAddressInput').value.trim(),
      contact: $('#companyContactInput').value.trim(),
      email: $('#companyEmailInput').value.trim(),
      website: $('#companyWebsiteInput').value.trim(),
      footerNote: $('#companyFooterNoteInput').value.trim() || 'Documento interno · Organograma'
    });
    state.meta.companyName = state.company.name;
    saveState('Identidade da empresa guardada');
    renderCompanySettings();
  }

  function selectLogoPreset(path) {
    if (!CAN_EDIT) { showToast('Acesso apenas de consulta.'); return; }
    state.company.logoPath = path;
    state.company.logoDataUrl = '';
    saveState('Versão do logótipo aplicada');
    renderCompanySettings();
  }

  async function handleCompanyLogoUpload(event) {
    if (!CAN_EDIT) { showToast('Acesso apenas de consulta.'); event.target.value=''; return; }
    const file = event.target.files?.[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) { showToast('Selecione um ficheiro de imagem'); return; }
    try {
      state.company.logoDataUrl = await resizeLogoFile(file);
      state.company.logoPath = '';
      saveState('Logótipo guardado localmente');
      renderCompanySettings();
    } catch (error) {
      showToast(`Não foi possível carregar o logótipo: ${error.message}`);
    }
    event.target.value = '';
  }

  function resizeLogoFile(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error('leitura falhou'));
      reader.onload = () => {
        if (file.type === 'image/svg+xml') { resolve(String(reader.result)); return; }
        const image = new Image();
        image.onerror = () => reject(new Error('imagem inválida'));
        image.onload = () => {
          const maxWidth = 1400, maxHeight = 500;
          const scale = Math.min(1, maxWidth / image.naturalWidth, maxHeight / image.naturalHeight);
          const canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
          canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
          const context = canvas.getContext('2d');
          context.clearRect(0, 0, canvas.width, canvas.height);
          context.drawImage(image, 0, 0, canvas.width, canvas.height);
          resolve(canvas.toDataURL('image/png'));
        };
        image.src = String(reader.result);
      };
      reader.readAsDataURL(file);
    });
  }

  function exportOrgToPdf() {
    const visiblePeople = state.people.filter(person => person.status !== 'inactive');
    if (!visiblePeople.length) { showToast('O organograma não tem pessoas para exportar'); return; }

    const company = state.company || {};
    const logo = getAbsoluteLogoSource();
    const generatedAt = new Intl.DateTimeFormat('pt-PT', {dateStyle:'long'}).format(new Date());
    const levels = buildOrgPrintLevels(visiblePeople);
    const levelHtml = levels.map((people, levelIndex) => {
      const cards = people.map(person => renderOrgPrintCard(person, visiblePeople)).join('');
      return `<section class="print-level"><div class="level-label">${levelIndex === 0 ? 'Direção' : `Nível ${levelIndex + 1}`}</div><div class="print-level-grid">${cards}</div></section>`;
    }).join('');

    const shiftSummary = state.shifts.filter(shift => shift.status !== 'inactive').map(shift => {
      const capacity = calculateShiftCapacity(shift);
      return `<div class="print-shift" style="--shift:${esc(shift.color || '#2D69A1')}"><span>${esc(shift.code || shift.name)}</span><strong>${capacity.headcount} pessoas · ${formatNumber(capacity.hoursDay)} h/dia</strong><small>${esc(shift.start || '—')}–${esc(shift.end || '—')} · ${formatNumber(capacity.hoursWeek)} h/semana</small></div>`;
    }).join('');

    const footerParts = [company.address, company.contact, company.email, company.website].filter(Boolean);
    const printWindow = window.open('', '_blank', 'width=1300,height=900');
    if (!printWindow) { showToast('O browser bloqueou a janela de exportação'); return; }

    printWindow.document.open();
    printWindow.document.write(`<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><title>Organograma · ${esc(company.name || 'TISSER')}</title><style>
      @page { size: A4 landscape; margin: 8mm 9mm 18mm; }
      * { box-sizing:border-box; }
      html,body { margin:0; color:#212124; font-family:Arial,Helvetica,sans-serif; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      body { padding:0 0 15mm; background:#fff; }
      .print-header { display:flex; align-items:center; justify-content:space-between; gap:18px; padding-bottom:4mm; border-bottom:1.5px solid #212124; }
      .print-header img { width:145px; max-height:45px; object-fit:contain; object-position:left center; }
      .print-title { text-align:right; }
      .print-title span { display:block; color:#5EAD41; font-size:7px; font-weight:800; letter-spacing:.15em; text-transform:uppercase; }
      .print-title h1 { margin:2px 0 1px; font-size:18px; line-height:1.05; }
      .print-title small { color:#687079; font-size:7px; }
      .print-shifts { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:4px; padding-top:3mm; }
      .print-shift { position:relative; min-width:0; padding:5px 6px 5px 9px; border:1px solid #d8dde1; border-radius:5px; overflow:hidden; }
      .print-shift::before { content:''; position:absolute; inset:0 auto 0 0; width:3px; background:var(--shift); }
      .print-shift span,.print-shift strong,.print-shift small { display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
      .print-shift span { color:var(--shift); font-size:6px; font-weight:800; letter-spacing:.06em; }
      .print-shift strong { margin-top:1px; font-size:7px; }
      .print-shift small { margin-top:1px; color:#687079; font-size:5.8px; }
      .org-wrap { padding-top:3.5mm; }
      .print-level { position:relative; padding-top:3.5mm; margin-top:2mm; border-top:1px solid #cfd5d9; break-inside:avoid; }
      .print-level:first-child { margin-top:0; border-top:0; padding-top:0; }
      .level-label { display:inline-block; margin-bottom:2mm; padding:2px 6px; border-radius:999px; background:#eef1f3; color:#5b646b; font-size:6px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
      .print-level-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:5px 6px; align-items:stretch; }
      .print-card { position:relative; min-width:0; min-height:55px; padding:7px 7px 6px 9px; border:1px solid #cfd5d9; border-radius:6px; background:#fff; box-shadow:0 1px 4px rgba(33,33,36,.06); overflow:hidden; break-inside:avoid; }
      .print-card::before { content:''; position:absolute; inset:0 auto 0 0; width:4px; background:var(--dept); }
      .print-card strong,.print-card span,.print-card em,.print-card small,.print-card b { display:block; overflow-wrap:anywhere; }
      .print-card strong { font-size:8px; line-height:1.12; }
      .print-card span { margin-top:2px; font-size:6.8px; line-height:1.15; color:#4f5960; }
      .print-card em { margin-top:3px; color:#687079; font-size:5.8px; line-height:1.15; font-style:normal; }
      .print-card b { margin-top:3px; color:#687079; font-size:5.5px; line-height:1.15; font-weight:600; }
      .print-card small { margin-top:3px; color:var(--dept); font-size:5.7px; line-height:1.1; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
      .print-footer { position:fixed; left:9mm; right:9mm; bottom:5mm; display:grid; grid-template-columns:1fr auto; gap:10px; align-items:end; padding-top:2.5mm; border-top:1px solid #cfd5d9; color:#5d666c; font-size:6px; }
      .print-footer strong { display:block; margin-bottom:1px; color:#212124; font-size:7px; }
      .footer-note { text-align:right; }
      @media print {
        html,body { width:100%; }
        .print-level-grid { grid-template-columns:repeat(5,minmax(0,1fr)); }
      }
    </style></head><body>
      <header class="print-header"><img src="${esc(logo)}" alt="Logótipo"><div class="print-title"><span>Estrutura da empresa</span><h1>Organograma</h1><small>Gerado em ${esc(generatedAt)}</small></div></header>
      ${shiftSummary ? `<section class="print-shifts">${shiftSummary}</section>` : ''}
      <main class="org-wrap">${levelHtml}</main>
      <footer class="print-footer"><div><strong>${esc(company.name || 'TISSER')}</strong>${esc(footerParts.join(' · ') || 'Preencher morada e contactos na área Dados e cópias')}</div><div class="footer-note">${esc(company.footerNote || 'Documento interno · Organograma')}</div></footer>
      <script>window.addEventListener('load',()=>setTimeout(()=>window.print(),500));window.addEventListener('afterprint',()=>window.close());<\/script>
    </body></html>`);
    printWindow.document.close();
    showToast('A preparar o organograma A4, ordenado por turno e função');
  }

  function buildOrgPrintLevels(visiblePeople) {
    const byId = new Map(visiblePeople.map(person => [person.id, person]));
    const depthCache = new Map();
    function getDepth(person, trail = new Set()) {
      if (depthCache.has(person.id)) return depthCache.get(person.id);
      if (!person.managerId || !byId.has(person.managerId) || trail.has(person.id)) {
        depthCache.set(person.id, 0);
        return 0;
      }
      const nextTrail = new Set(trail);
      nextTrail.add(person.id);
      const depth = Math.min(8, getDepth(byId.get(person.managerId), nextTrail) + 1);
      depthCache.set(person.id, depth);
      return depth;
    }
    const levels = [];
    visiblePeople.forEach(person => {
      const depth = getDepth(person);
      if (!levels[depth]) levels[depth] = [];
      levels[depth].push(person);
    });
    levels.forEach(level => level.sort((a,b) => {
      // Ordenar primeiro pela sequência dos turnos configurados e, dentro
      // de cada turno, agrupar por função para facilitar a leitura da ocupação.
      const shiftA = getShift(a.shiftId);
      const shiftB = getShift(b.shiftId);
      const shiftIndexA = shiftA ? state.shifts.findIndex(shift => shift.id === shiftA.id) : Number.MAX_SAFE_INTEGER;
      const shiftIndexB = shiftB ? state.shifts.findIndex(shift => shift.id === shiftB.id) : Number.MAX_SAFE_INTEGER;
      if (shiftIndexA !== shiftIndexB) return shiftIndexA - shiftIndexB;

      const startA = String(shiftA?.start || '99:99');
      const startB = String(shiftB?.start || '99:99');
      const startOrder = startA.localeCompare(startB, 'pt', { numeric:true });
      if (startOrder) return startOrder;

      const titleA = String(a.title || '').trim();
      const titleB = String(b.title || '').trim();
      const titleOrder = titleA.localeCompare(titleB, 'pt', { sensitivity:'base', numeric:true });
      if (titleOrder) return titleOrder;

      const depA = getDepartment(a.departmentId)?.name || '';
      const depB = getDepartment(b.departmentId)?.name || '';
      const departmentOrder = depA.localeCompare(depB, 'pt', { sensitivity:'base', numeric:true });
      if (departmentOrder) return departmentOrder;

      return String(a.name || '').localeCompare(String(b.name || ''), 'pt', { sensitivity:'base', numeric:true });
    }));
    return levels.filter(Boolean);
  }


  function renderOrgPrintCard(person, visiblePeople) {
    const department = getDepartment(person.departmentId);
    const shift = getShift(person.shiftId);
    const manager = visiblePeople.find(candidate => candidate.id === person.managerId);
    const shiftText = shift ? `${shift.code || shift.name} · ${shift.start}–${shift.end} · ${formatNumber(Number(person.capacityPercent ?? 100))}%` : 'Sem turno definido';
    return `<article class="print-card" style="--dept:${esc(department?.color || '#2D69A1')}"><strong>${esc(person.name)}</strong><span>${esc(person.title)}</span><em>${esc(shiftText)}</em>${manager ? `<b>Reporta a: ${esc(manager.name)}</b>` : ''}<small>${esc(department?.name || 'Sem departamento')}</small></article>`;
  }


  function renderOrgPrintBranch(person, visiblePeople) {
    const department = getDepartment(person.departmentId);
    const shift = getShift(person.shiftId);
    const children = visiblePeople.filter(candidate => candidate.managerId === person.id);
    const shiftText = shift ? `${shift.code || shift.name} · ${shift.start}–${shift.end} · ${formatNumber(Number(person.capacityPercent ?? 100))}%` : 'Sem turno definido';
    return `<li><div class="print-card" style="--dept:${esc(department?.color || '#2D69A1')};--shift:${esc(shift?.color || '#9aa2a8')}"><strong>${esc(person.name)}</strong><span>${esc(person.title)}</span><em><i></i>${esc(shiftText)}</em><small>${esc(department?.name || 'Sem departamento')}</small></div>${children.length ? `<ul>${children.map(child => renderOrgPrintBranch(child, visiblePeople)).join('')}</ul>` : ''}</li>`;
  }

  function handleQuickAdd() {
    if (currentView === 'org') openPersonForm();
    else if (currentView === 'duties') openDutySheetForm();
    else if (currentView === 'flows') openNodeForm();
    else if (currentView === 'calendar') openCalendarEventForm();
    else if (currentView === 'machines') openMachineForm();
    else if (currentView === 'competencies') openCompetencyForm();
    else if (currentView === 'systems') openSystemForm();
    else if (currentView === 'improvements') openImprovementForm();
    else openQuickAddMenu();
  }

  function openQuickAddMenu() {
    openForm({title:'Adicionar informação',kicker:'ATALHO',fields:[{name:'kind',label:'Tipo de registo',type:'select',options:[{value:'person',label:'Pessoa / função'},{value:'shift',label:'Turno'},{value:'duty',label:'Caderno de função'},{value:'flow',label:'Fluxo de produção'},{value:'event',label:'Evento anual'},{value:'system',label:'Sistema / aplicação'},{value:'improvement',label:'Melhoria identificada'}]}],initial:{kind:'person'},onSave: values => { $('#formDialog').close(); ({person:openPersonForm,shift:openShiftForm,duty:openDutySheetForm,flow:openFlowForm,event:openCalendarEventForm,system:openSystemForm,improvement:openImprovementForm}[values.kind])(); }});
  }


  function openDutySheetForm(sheet = null, defaults = {}) {
    const peopleOptions = state.people.filter(person => person.status !== 'inactive').map(person => ({value:person.id,label:`${person.name} · ${person.title}`}));
    const initial = sheet || {
      title:defaults.title || '', departmentId:defaults.departmentId || '', responsibleId:defaults.responsibleId || '', backupId:'', secondaryBackupId:'',
      status:'draft', reviewDate:'', purpose:'', responsibilities:'', dailyTasks:'', periodicTasks:'', authority:'', kpis:'', systems:'', absenceInstructions:'', documents:'', notes:''
    };
    openForm({title:sheet ? 'Editar caderno de função' : 'Novo caderno de função',kicker:'FUNÇÕES E CONTINUIDADE',fields:[
      {name:'title',label:'Nome da função / caderno',required:true,full:true},
      {name:'departmentId',label:'Departamento',type:'select',options:state.departments.map(dep => ({value:dep.id,label:dep.name})),emptyLabel:'Por definir'},
      {name:'status',label:'Estado',type:'select',options:Object.entries(dutyStatusLabels).map(([value,label]) => ({value,label})),required:true},
      {name:'responsibleId',label:'Responsável principal',type:'select',options:peopleOptions,emptyLabel:'Por definir'},
      {name:'backupId',label:'Substituto na ausência',type:'select',options:peopleOptions,emptyLabel:'Sem substituto definido'},
      {name:'secondaryBackupId',label:'2.º substituto',type:'select',options:peopleOptions,emptyLabel:'Opcional'},
      {name:'reviewDate',label:'Data da próxima revisão',type:'date'},
      {name:'purpose',label:'Objetivo e resultado esperado da função',type:'textarea',full:true},
      {name:'responsibilities',label:'Responsabilidades principais — uma por linha',type:'textarea',full:true},
      {name:'dailyTasks',label:'Tarefas diárias / por turno',type:'textarea',full:true},
      {name:'periodicTasks',label:'Tarefas semanais, mensais e anuais',type:'textarea',full:true},
      {name:'authority',label:'Decisões que pode tomar e limites de autoridade',type:'textarea',full:true},
      {name:'kpis',label:'Indicadores / KPI da função',type:'textarea',full:true},
      {name:'systems',label:'Sistemas, aplicações e acessos necessários',type:'textarea',full:true},
      {name:'absenceInstructions',label:'Procedimento na ausência do responsável',type:'textarea',full:true},
      {name:'documents',label:'Documentos, pastas, links e contactos críticos',type:'textarea',full:true},
      {name:'notes',label:'Competências, riscos e observações',type:'textarea',full:true}
    ],initial,onSave: values => {
      if (values.responsibleId && values.backupId === values.responsibleId) { showToast('O substituto deve ser uma pessoa diferente do responsável'); return; }
      if (values.responsibleId && values.secondaryBackupId === values.responsibleId) { showToast('O segundo substituto deve ser diferente do responsável'); return; }
      if (values.backupId && values.secondaryBackupId === values.backupId) { showToast('Os dois substitutos devem ser diferentes'); return; }
      const now = new Date().toISOString();
      if (sheet) Object.assign(sheet, values, {updatedAt:now});
      else state.dutySheets.push({id:uid('duty'),...values,createdAt:now,updatedAt:now});
      saveState(); $('#formDialog').close(); renderAll(); switchView('duties');
    },deleteAction:sheet ? () => confirmDeleteDutySheet(sheet) : null});
  }

  function exportDutySheetToPdf(sheet) {
    if (!sheet) return;
    const company = state.company || {};
    const logo = getAbsoluteLogoSource();
    const department = getDepartment(sheet.departmentId);
    const responsible = getPerson(sheet.responsibleId);
    const backup = getPerson(sheet.backupId);
    const secondary = getPerson(sheet.secondaryBackupId);
    const footerParts = [company.address,company.contact,company.email,company.website].filter(Boolean);
    const generatedAt = new Intl.DateTimeFormat('pt-PT', {dateStyle:'long'}).format(new Date());
    const sections = [
      ['Objetivo da função',sheet.purpose],
      ['Responsabilidades principais',sheet.responsibilities],
      ['Tarefas diárias / por turno',sheet.dailyTasks],
      ['Tarefas periódicas',sheet.periodicTasks],
      ['Decisões e limites de autoridade',sheet.authority],
      ['Indicadores / KPI',sheet.kpis],
      ['Sistemas e acessos necessários',sheet.systems],
      ['Procedimento na ausência',sheet.absenceInstructions],
      ['Documentos, links e contactos críticos',sheet.documents],
      ['Competências, riscos e observações',sheet.notes]
    ].filter(([,value]) => String(value || '').trim()).map(([title,value]) => `<section><h2>${esc(title)}</h2><div class="section-text">${printMultiline(value)}</div></section>`).join('');
    const printWindow = window.open('', '_blank', 'width=1000,height=900');
    if (!printWindow) { showToast('O browser bloqueou a janela de exportação'); return; }
    printWindow.document.open();
    printWindow.document.write(`<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><title>${esc(sheet.title)} · ${esc(company.name || 'TISSER')}</title><style>
      @page { size:A4 portrait; margin:16mm 15mm 22mm; }
      *{box-sizing:border-box} html,body{margin:0;color:#212124;font-family:Arial,Helvetica,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact} body{padding-bottom:18mm}
      header{display:flex;justify-content:space-between;align-items:center;gap:24px;padding-bottom:8mm;border-bottom:3px solid #212124} header img{width:220px;max-height:72px;object-fit:contain;object-position:left center}
      .title{text-align:right}.title span{display:block;color:#2D69A1;font-size:9px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.title h1{margin:5px 0 3px;font-size:24px}.title small{color:#687079}
      .summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:8mm 0}.summary div{padding:10px 11px;border:1px solid #d9dfe3;border-left:5px solid #2D69A1;border-radius:8px}.summary span,.summary strong,.summary small{display:block}.summary span{color:#687079;font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}.summary strong{margin-top:3px;font-size:11px}.summary small{margin-top:3px;color:#687079;font-size:9px}
      .coverage{border-left-color:${backup || secondary ? '#5EAD41' : '#a94444'}!important} section{break-inside:avoid;margin:0 0 6mm} section h2{margin:0 0 3mm;padding-bottom:2mm;border-bottom:1px solid #d9dfe3;color:#2D69A1;font-size:12px;text-transform:uppercase;letter-spacing:.06em}.section-text{font-size:10.5px;line-height:1.55;white-space:normal}
      footer{position:fixed;left:15mm;right:15mm;bottom:7mm;display:grid;grid-template-columns:1fr auto;gap:12px;padding-top:4mm;border-top:1px solid #cfd5d9;color:#5d666c;font-size:8.5px}footer strong{display:block;margin-bottom:3px;color:#212124}.footer-note{text-align:right}
    </style></head><body>
      <header><img src="${esc(logo)}" alt="Logótipo"><div class="title"><span>Caderno de encargos da função</span><h1>${esc(sheet.title)}</h1><small>Gerado em ${esc(generatedAt)}</small></div></header>
      <div class="summary">
        <div><span>Departamento</span><strong>${esc(department?.name || 'Por definir')}</strong><small>Estado: ${esc(dutyStatusLabels[sheet.status] || sheet.status)}</small></div>
        <div><span>Responsável principal</span><strong>${esc(responsible ? `${responsible.name} · ${responsible.title}` : 'Por definir')}</strong><small>Revisão: ${esc(formatDate(sheet.reviewDate))}</small></div>
        <div class="coverage"><span>Substituto na ausência</span><strong>${esc(backup ? `${backup.name} · ${backup.title}` : 'Sem substituto definido')}</strong><small>${secondary ? `2.º substituto: ${esc(secondary.name)} · ${esc(secondary.title)}` : 'Definir cobertura para reduzir dependência'}</small></div>
        <div><span>Continuidade</span><strong>${backup || secondary ? 'Cobertura identificada' : 'Risco de continuidade'}</strong><small>${esc(sheet.absenceInstructions ? 'Procedimento de ausência documentado' : 'Procedimento de ausência por preencher')}</small></div>
      </div>
      <main>${sections || '<section><h2>Conteúdo</h2><div class="section-text">Caderno ainda sem conteúdo detalhado.</div></section>'}</main>
      <footer><div><strong>${esc(company.name || 'TISSER')}</strong>${esc(footerParts.join(' · ') || 'Preencher morada e contactos na área Dados e cópias')}</div><div class="footer-note">${esc(company.footerNote || 'Documento interno')} · Caderno de função</div></footer>
      <script>window.addEventListener('load',()=>{setTimeout(()=>{const tree=document.querySelector('.print-org-tree');const wrap=document.querySelector('.org-wrap');if(tree&&wrap){const available=wrap.clientWidth;const needed=tree.scrollWidth;const scale=Math.min(1,available/needed);tree.style.transform='scale('+scale+')';tree.style.transformOrigin='top center';wrap.style.height=(tree.scrollHeight*scale+20)+'px';}document.title='';window.print();},700)});window.addEventListener('afterprint',()=>window.close());<\/script>
    </body></html>`);
    printWindow.document.close();
    showToast('A preparar o caderno para guardar em PDF');
  }

  function openDepartmentForm(department = null) {
    openForm({title:department ? 'Editar departamento' : 'Novo departamento',kicker:'ORGANOGRAMA',fields:[
      {name:'name',label:'Nome',required:true},{name:'color',label:'Cor',type:'color'},{name:'description',label:'Descrição',type:'textarea',full:true}
    ],initial:department || {name:'',color:'#2f7353',description:''},onSave: values => {
      if (department) Object.assign(department, values);
      else state.departments.push({id:uid('dep'),...values});
      saveState(); $('#formDialog').close(); renderAll();
    }});
  }

  function openShiftForm(shift = null) {
    openForm({title:shift ? 'Editar turno' : 'Novo turno',kicker:'TURNOS E CAPACIDADE',fields:[
      {name:'name',label:'Nome do turno',required:true},{name:'code',label:'Código curto',required:true},
      {name:'start',label:'Hora de início',type:'time',required:true},{name:'end',label:'Hora de fim',type:'time',required:true},
      {name:'hoursPerDay',label:'Horas úteis / dia',type:'number',step:'0.25',min:'0',required:true},{name:'daysPerWeek',label:'Dias / semana',type:'number',step:'0.5',min:'0',max:'7',required:true},
      {name:'color',label:'Cor',type:'color'},{name:'status',label:'Estado',type:'select',options:[{value:'active',label:'Ativo'},{value:'inactive',label:'Inativo'}]},
      {name:'notes',label:'Pausas, rotação e observações',type:'textarea',full:true}
    ],initial:shift || {name:'',code:'',start:'06:00',end:'14:00',hoursPerDay:8,daysPerWeek:5,color:'#5EAD41',status:'active',notes:''},onSave: values => {
      values.hoursPerDay = Math.max(0, Number(values.hoursPerDay || 0));
      values.daysPerWeek = Math.max(0, Number(values.daysPerWeek || 0));
      if (shift) Object.assign(shift, values); else state.shifts.push({id:uid('shift'),...values});
      saveState(); $('#formDialog').close(); renderAll(); switchView('org');
    },deleteAction:shift ? () => confirmDeleteShift(shift) : null});
  }

  function openPersonForm(person = null) {
    const managerOptions = state.people.filter(candidate => !person || candidate.id !== person.id).map(candidate => ({value:candidate.id,label:`${candidate.name} · ${candidate.title}`}));
    openForm({title:person ? 'Editar pessoa / função' : 'Nova pessoa / função',kicker:'ORGANOGRAMA',fields:[
      {name:'name',label:'Nome',required:true},{name:'title',label:'Função',required:true},
      {name:'departmentId',label:'Departamento',type:'select',options:state.departments.map(dep => ({value:dep.id,label:dep.name})),required:true},
      {name:'managerId',label:'Reporta a',type:'select',options:managerOptions,emptyLabel:'Direção / ninguém'},
      {name:'shiftId',label:'Turno',type:'select',options:state.shifts.map(shift => ({value:shift.id,label:`${shift.code || shift.name} · ${shift.name} · ${shift.start}–${shift.end}`})),emptyLabel:'Sem turno definido'},
      {name:'capacityPercent',label:'Capacidade disponível (%)',type:'number',step:'5',min:'0',max:'200'},
      {name:'email',label:'Email',type:'email'},{name:'phone',label:'Telefone'},
      {name:'status',label:'Estado',type:'select',options:[{value:'active',label:'Ativo'},{value:'inactive',label:'Inativo'}]},
      {name:'notes',label:'Notas e responsabilidades',type:'textarea',full:true}
    ],initial:person || {name:'',title:'',departmentId:state.departments[0]?.id || '',managerId:'',shiftId:'',capacityPercent:100,email:'',phone:'',status:'active',notes:''},onSave: values => {
      values.capacityPercent = Math.max(0, Number(values.capacityPercent || 0));
      if (person) Object.assign(person, values);
      else { const newPerson = {id:uid('person'),...values}; state.people.push(newPerson); selectedPersonId = newPerson.id; }
      saveState(); $('#formDialog').close(); renderAll();
    }});
  }

  function openFlowForm(flow = null) {
    openForm({title:flow ? 'Editar fluxo' : 'Novo fluxo',kicker:'PROCESSOS',fields:[
      {name:'name',label:'Nome do fluxo',required:true,full:true},{name:'description',label:'Objetivo / descrição',type:'textarea',full:true},
      {name:'ownerId',label:'Dono do processo',type:'select',options:state.people.map(person => ({value:person.id,label:`${person.name} · ${person.title}`})),emptyLabel:'Por definir'},
      {name:'status',label:'Estado',type:'select',options:[{value:'draft',label:'Rascunho'},{value:'hypothesis',label:'Hipótese a validar'},{value:'validated',label:'Validado'}]},
      {name:'version',label:'Versão'}
    ],initial:flow || {name:'',description:'',ownerId:'',status:'draft',version:'0.1'},onSave: values => {
      if (flow) Object.assign(flow, values);
      else { const newFlow = {id:uid('flow'),...values,nodes:[],edges:[]}; state.flows.push(newFlow); currentFlowId = newFlow.id; }
      saveState(); $('#formDialog').close(); renderAll(); switchView('flows');
    }});
  }

  function openNodeForm(node = null) {
    const flow = getFlow();
    if (!flow) { showToast('Crie primeiro um fluxo'); openFlowForm(); return; }
    openForm({title:node ? 'Editar etapa' : 'Nova etapa',kicker:'FLUXO DE PRODUÇÃO',fields:[
      {name:'label',label:'Nome da etapa',required:true,full:true},
      {name:'type',label:'Tipo',type:'select',options:Object.entries(nodeTypeLabels).map(([value,label]) => ({value,label}))},
      {name:'ownerId',label:'Responsável',type:'select',options:state.people.map(person => ({value:person.id,label:`${person.name} · ${person.title}`})),emptyLabel:'Por definir'},
      {name:'duration',label:'Duração / SLA'},{name:'system',label:'Sistema / suporte'},
      {name:'kpi',label:'KPI principal',full:true},{name:'notes',label:'Notas, entradas e saídas',type:'textarea',full:true}
    ],initial:node || {label:'',type:'operation',ownerId:'',duration:'',system:'',kpi:'',notes:''},onSave: values => {
      if (node) Object.assign(node, values);
      else { const offset = flow.nodes.length * 35; const newNode = {id:uid('node'),...values,x:60 + (offset % 900),y:90 + (offset % 400)}; flow.nodes.push(newNode); selectedNodeId = newNode.id; }
      saveState(); $('#formDialog').close(); renderAll();
    }});
  }

  function openCalendarEventForm(event = null, defaultDate = '') {
    const selectedDate = defaultDate || `${calendarYear}-01-01`;
    openForm({title:event ? 'Editar evento' : 'Novo evento anual',kicker:'CALENDÁRIO',fields:[
      {name:'title',label:'Título',required:true,full:true},{name:'start',label:'Data inicial',type:'date',required:true},{name:'end',label:'Data final',type:'date',required:true},
      {name:'typeId',label:'Tipo',type:'select',options:state.calendarTypes.map(type => ({value:type.id,label:type.name})),required:true},
      {name:'ownerId',label:'Responsável',type:'select',options:state.people.map(person => ({value:person.id,label:`${person.name} · ${person.title}`})),emptyLabel:'Por definir'},
      {name:'description',label:'Descrição / preparação necessária',type:'textarea',full:true}
    ],initial:event || {title:'',start:selectedDate,end:selectedDate,typeId:state.calendarTypes[0]?.id || '',ownerId:'',description:''},onSave: values => {
      if (values.end < values.start) { showToast('A data final não pode ser anterior'); return; }
      if (event) Object.assign(event, values, {allDay:true});
      else state.calendarEvents.push({id:uid('event'),...values,allDay:true});
      saveState(); $('#formDialog').close(); calendarYear = Number(values.start.slice(0,4)); renderAll();
    },deleteAction:event ? () => confirmDeleteEvent(event) : null});
  }

  function openSystemForm(system = null) {
    openForm({title:system ? 'Editar sistema / aplicação' : 'Novo sistema / aplicação',kicker:'TECNOLOGIA',fields:[
      {name:'name',label:'Nome',required:true},{name:'category',label:'Categoria',required:true},
      {name:'supplier',label:'Fornecedor / parceiro'},{name:'ownerId',label:'Responsável interno',type:'select',options:state.people.map(person => ({value:person.id,label:`${person.name} · ${person.title}`})),emptyLabel:'Por definir'},
      {name:'users',label:'N.º de utilizadores',type:'number',min:'0'},{name:'billing',label:'Periodicidade',type:'select',options:[{value:'monthly',label:'Mensal'},{value:'annual',label:'Anual'}]},
      {name:'cost',label:'Custo sem IVA (€)',type:'number',step:'0.01',min:'0'},{name:'renewal',label:'Renovação / fim do contrato',type:'date'},
      {name:'status',label:'Estado',type:'select',options:[{value:'active',label:'Ativo'},{value:'review',label:'A rever'},{value:'risk',label:'Risco'},{value:'inactive',label:'Inativo'}]},
      {name:'url',label:'URL / consola',type:'url',full:true},{name:'notes',label:'Notas, licenças, backups e dependências',type:'textarea',full:true}
    ],initial:system || {name:'',category:'',supplier:'',ownerId:'',users:0,billing:'annual',cost:0,renewal:'',status:'review',url:'',notes:''},onSave: values => {
      values.users = Number(values.users || 0); values.cost = Number(values.cost || 0);
      if (system) Object.assign(system, values); else state.systems.push({id:uid('system'),...values});
      saveState(); $('#formDialog').close(); renderAll();
    }});
  }


  function openMachineForm(machine = null) {
    openForm({title:machine?'Editar máquina':'Nova máquina',kicker:'ATIVOS PRODUTIVOS',fields:[
      {name:'code',label:'Código interno'},{name:'name',label:'Designação',required:true},{name:'brand',label:'Marca'},{name:'model',label:'Modelo'},
      {name:'serialNumber',label:'Número de série'},{name:'year',label:'Ano de fabrico',type:'number',min:'1900',max:'2100'},
      {name:'departmentId',label:'Departamento / setor',type:'select',options:state.departments.map(d=>({value:d.id,label:d.name})),emptyLabel:'Por definir'},
      {name:'location',label:'Localização física'},{name:'ownerId',label:'Responsável técnico',type:'select',options:state.people.map(p=>({value:p.id,label:`${p.name} · ${p.title}`})),emptyLabel:'Por definir'},
      {name:'status',label:'Estado',type:'select',options:Object.entries(machineStatusLabels).map(([value,label])=>({value,label}))},
      {name:'criticality',label:'Criticidade',type:'select',options:Object.entries(machineCriticalityLabels).map(([value,label])=>({value,label}))},
      {name:'capacity',label:'Capacidade nominal / unidade'},{name:'cycleTime',label:'Tempo de ciclo'},
      {name:'operatorsRequired',label:'Operadores necessários',type:'number',min:'0'},{name:'supplier',label:'Fornecedor / assistência'},
      {name:'purchaseDate',label:'Data de aquisição',type:'date'},{name:'nextMaintenance',label:'Próxima manutenção',type:'date'},
      {name:'manualUrl',label:'Manual / ligação documental',type:'url',full:true},{name:'notes',label:'Características, riscos, limitações e observações',type:'textarea',full:true}
    ],initial:machine||{code:'',name:'',brand:'',model:'',serialNumber:'',year:'',departmentId:'',location:'',ownerId:'',status:'operational',criticality:'medium',capacity:'',cycleTime:'',operatorsRequired:1,supplier:'',purchaseDate:'',nextMaintenance:'',manualUrl:'',notes:''},onSave:values=>{
      values.year=Number(values.year)||''; values.operatorsRequired=Number(values.operatorsRequired)||0;
      if(machine) Object.assign(machine,values); else state.machines.push({id:uid('machine'),...values});
      saveState(); $('#formDialog').close(); renderAll(); switchView('machines');
    },deleteAction:machine?()=>confirmDeleteMachine(machine):null});
  }

  function openCompetencyForm(item = null) {
    openForm({title:item?'Editar competência':'Nova competência',kicker:'MATRIZ DE COMPETÊNCIAS',fields:[
      {name:'personId',label:'Colaborador',type:'select',options:state.people.filter(p=>p.status!=='inactive').map(p=>({value:p.id,label:`${p.name} · ${p.title}`})),emptyLabel:'Selecionar',required:true},
      {name:'machineId',label:'Máquina',type:'select',options:state.machines.map(m=>({value:m.id,label:`${m.code?m.code+' · ':''}${m.name}`})),emptyLabel:'Selecionar',required:true},
      {name:'level',label:'Nível de conhecimento',type:'select',options:Object.entries(competencyLevelLabels).map(([value,label])=>({value,label:`${value} · ${label}`})),required:true},
      {name:'assessedAt',label:'Data da avaliação',type:'date'},{name:'expiryDate',label:'Validade / reavaliação',type:'date'},
      {name:'notes',label:'Formação, limitações e evidências',type:'textarea',full:true}
    ],initial:item||{personId:'',machineId:'',level:0,assessedAt:new Date().toISOString().slice(0,10),expiryDate:'',notes:''},onSave:values=>{
      values.level=Number(values.level)||0;
      const duplicate=state.competencies.find(c=>c.personId===values.personId&&c.machineId===values.machineId&&(!item||c.id!==item.id));
      if(duplicate){ showToast('Já existe uma competência deste colaborador para esta máquina.'); return; }
      if(item) Object.assign(item,values); else state.competencies.push({id:uid('competency'),...values});
      saveState(); $('#formDialog').close(); renderAll(); switchView('competencies');
    },deleteAction:item?()=>confirmDeleteCompetency(item):null});
  }

  function openImprovementForm(improvement = null) {
    openForm({title:improvement ? 'Editar melhoria identificada' : 'Nova melhoria identificada',kicker:'MELHORIA CONTÍNUA',fields:[
      {name:'title',label:'Título da melhoria',required:true,full:true},
      {name:'area',label:'Área',type:'select',options:Object.entries(improvementAreaLabels).map(([value,label]) => ({value,label})),required:true},
      {name:'status',label:'Estado',type:'select',options:Object.entries(improvementStatusLabels).map(([value,label]) => ({value,label})),required:true},
      {name:'priority',label:'Prioridade',type:'select',options:Object.entries(improvementPriorityLabels).map(([value,label]) => ({value,label})),required:true},
      {name:'impact',label:'Impacto esperado',type:'select',options:Object.entries(improvementImpactLabels).map(([value,label]) => ({value,label})),required:true},
      {name:'effort',label:'Esforço estimado',type:'select',options:Object.entries(improvementImpactLabels).map(([value,label]) => ({value,label})),required:true},
      {name:'ownerId',label:'Responsável',type:'select',options:state.people.map(person => ({value:person.id,label:`${person.name} · ${person.title}`})),emptyLabel:'Por definir'},
      {name:'dueDate',label:'Prazo-alvo',type:'date'},
      {name:'supplier',label:'Fornecedor relacionado',full:true},
      {name:'flowId',label:'Fluxo / processo relacionado',type:'select',options:state.flows.map(flow => ({value:flow.id,label:flow.name})),emptyLabel:'Sem fluxo associado',full:true},
      {name:'description',label:'Problema observado e ação proposta',type:'textarea',full:true},
      {name:'expectedBenefit',label:'Benefício esperado / KPI',type:'textarea',full:true},
      {name:'evidence',label:'Evidências, origem e notas de validação',type:'textarea',full:true}
    ],initial:improvement || {title:'',area:'production',status:'identified',priority:'medium',impact:'medium',effort:'medium',ownerId:'',dueDate:'',supplier:'',flowId:'',description:'',expectedBenefit:'',evidence:''},onSave: values => {
      const now = new Date().toISOString();
      if (improvement) Object.assign(improvement, values, {updatedAt:now});
      else state.improvements.push({id:uid('improvement'),...values,createdAt:now,updatedAt:now});
      saveState(); $('#formDialog').close(); renderAll(); switchView('improvements');
    },deleteAction:improvement ? () => confirmDeleteImprovement(improvement) : null});
  }

  function openForm({title,kicker='EDITAR',fields,initial={},onSave,deleteAction=null}) {
    if (!CAN_EDIT) { showToast('A tua conta tem acesso apenas de consulta.'); return; }
    $('#dialogTitle').textContent = title;
    $('#dialogKicker').textContent = kicker;
    $('#dialogFields').innerHTML = `<div class="form-grid">${fields.map(field => renderField(field, initial[field.name])).join('')}</div>${deleteAction ? '<div style="margin-top:18px"><button type="button" class="text-button" id="dialogDeleteButton" style="color:#a13d3d">Eliminar este registo</button></div>' : ''}`;
    dialogSubmitHandler = onSave;
    if (deleteAction) $('#dialogDeleteButton').addEventListener('click', () => { $('#formDialog').close(); deleteAction(); });
    $('#formDialog').showModal();
    setTimeout(() => $('#dialogFields input:not([type="color"]), #dialogFields select, #dialogFields textarea')?.focus(), 30);
  }

  function renderField(field, value) {
    const classes = `field ${field.full ? 'full' : ''}`;
    const required = field.required ? 'required' : '';
    const common = `${required} ${field.min !== undefined ? `min="${esc(field.min)}"` : ''} ${field.max !== undefined ? `max="${esc(field.max)}"` : ''} ${field.step ? `step="${esc(field.step)}"` : ''}`;
    let input;
    if (field.type === 'select') {
      input = `<select name="${esc(field.name)}" ${required}>${field.emptyLabel !== undefined ? `<option value="">${esc(field.emptyLabel)}</option>` : ''}${(field.options || []).map(option => `<option value="${esc(option.value)}" ${String(option.value) === String(value ?? '') ? 'selected' : ''}>${esc(option.label)}</option>`).join('')}</select>`;
    } else if (field.type === 'textarea') {
      input = `<textarea name="${esc(field.name)}" ${required}>${esc(value ?? '')}</textarea>`;
    } else {
      input = `<input name="${esc(field.name)}" type="${esc(field.type || 'text')}" value="${esc(value ?? '')}" ${common}>`;
    }
    return `<div class="${classes}"><label>${esc(field.label)}</label>${input}</div>`;
  }

  function confirmAction(title, message, handler) {
    $('#confirmTitle').textContent = title; $('#confirmMessage').textContent = message; confirmHandler = handler; $('#confirmDialog').showModal();
  }

  function confirmDeletePerson(person) {
    confirmAction('Eliminar pessoa / função', `Eliminar “${person.name}”? Os reportes diretos passarão para o nível superior.`, () => {
      state.people.filter(candidate => candidate.managerId === person.id).forEach(candidate => candidate.managerId = person.managerId || '');
      state.flows.forEach(flow => flow.nodes.forEach(node => { if (node.ownerId === person.id) node.ownerId = ''; }));
      state.systems.forEach(system => { if (system.ownerId === person.id) system.ownerId = ''; });
      state.calendarEvents.forEach(event => { if (event.ownerId === person.id) event.ownerId = ''; });
      state.improvements.forEach(item => { if (item.ownerId === person.id) item.ownerId = ''; });
      state.competencies = state.competencies.filter(item => item.personId !== person.id);
      state.machines.forEach(machine => { if (machine.ownerId === person.id) machine.ownerId = ''; });
      state.dutySheets.forEach(sheet => { if (sheet.responsibleId === person.id) sheet.responsibleId = ''; if (sheet.backupId === person.id) sheet.backupId = ''; if (sheet.secondaryBackupId === person.id) sheet.secondaryBackupId = ''; });
      state.people = state.people.filter(candidate => candidate.id !== person.id); selectedPersonId = ''; saveState('Pessoa eliminada'); renderAll();
    });
  }
  function confirmDeleteNode(node) {
    confirmAction('Eliminar etapa', `Eliminar a etapa “${node.label}” e todas as suas ligações?`, () => { const flow = getFlow(); flow.nodes = flow.nodes.filter(item => item.id !== node.id); flow.edges = flow.edges.filter(edge => edge.from !== node.id && edge.to !== node.id); selectedNodeId=''; saveState('Etapa eliminada'); renderAll(); });
  }
  function confirmDeleteEvent(event) { confirmAction('Eliminar evento', `Eliminar “${event.title}”?`, () => { state.calendarEvents = state.calendarEvents.filter(item => item.id !== event.id); saveState('Evento eliminado'); renderAll(); }); }
  function confirmDeleteMachine(machine) { if (!machine) return; const linked=state.competencies.filter(c=>c.machineId===machine.id).length; confirmAction('Eliminar máquina', `Eliminar “${machine.name}”? ${linked?linked+' competência(s) associada(s) serão também removidas.':''}`, ()=>{ state.competencies=state.competencies.filter(c=>c.machineId!==machine.id); state.machines=state.machines.filter(m=>m.id!==machine.id); saveState('Máquina eliminada'); renderAll(); }); }
  function confirmDeleteCompetency(item) { if (!item) return; const p=getPerson(item.personId),m=state.machines.find(x=>x.id===item.machineId); confirmAction('Eliminar competência', `Eliminar a competência de ${p?.name||'colaborador'} em ${m?.name||'máquina'}?`, ()=>{ state.competencies=state.competencies.filter(c=>c.id!==item.id); saveState('Competência eliminada'); renderAll(); }); }
  function confirmDeleteSystem(system) { confirmAction('Eliminar sistema', `Eliminar “${system.name}” do inventário?`, () => { state.systems = state.systems.filter(item => item.id !== system.id); saveState('Sistema eliminado'); renderAll(); }); }
  function confirmDeleteDutySheet(sheet) { if (!sheet) return; confirmAction('Eliminar caderno de função', `Eliminar “${sheet.title}”?`, () => { state.dutySheets = state.dutySheets.filter(item => item.id !== sheet.id); saveState('Caderno eliminado'); renderAll(); }); }
  function confirmDeleteImprovement(improvement) { if (!improvement) return; confirmAction('Eliminar melhoria', `Eliminar “${improvement.title}”?`, () => { state.improvements = state.improvements.filter(item => item.id !== improvement.id); saveState('Melhoria eliminada'); renderAll(); }); }
  function confirmDeleteShift(shift) { if (!shift) return; const assigned = state.people.filter(person => person.shiftId === shift.id).length; confirmAction('Eliminar turno', `Eliminar “${shift.name}”? ${assigned ? `${assigned} pessoa(s) ficarão sem turno atribuído.` : ''}`, () => { state.people.forEach(person => { if (person.shiftId === shift.id) person.shiftId = ''; }); state.shifts = state.shifts.filter(item => item.id !== shift.id); saveState('Turno eliminado'); renderAll(); }); }
  function confirmReset() { if (!CAN_EDIT) { showToast('Acesso apenas de consulta.'); return; } confirmAction('Repor dados iniciais', 'Todas as alterações locais serão apagadas. Exporte uma cópia antes de continuar.', () => { state = deepClone(window.TISSER_INITIAL_DATA); ensureSchema(); currentFlowId = state.flows[0]?.id || ''; selectedPersonId=''; selectedNodeId=''; calendarYear=Number(state.meta.year)||new Date().getFullYear(); saveState('Dados iniciais repostos'); renderAll(); }); }

  function exportData() {
    const blob = new Blob([JSON.stringify(state, null, 2)], {type:'application/json'});
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url; link.download = `tisser-company-mapper-${new Date().toISOString().slice(0,10)}.json`; link.click();
    URL.revokeObjectURL(url); showToast('Cópia JSON exportada');
  }

  async function importData(event) {
    if (!CAN_EDIT) { showToast('Acesso apenas de consulta.'); event.target.value=''; return; }
    const file = event.target.files?.[0]; if (!file) return;
    try {
      const imported = JSON.parse(await file.text());
      if (!imported.meta || !Array.isArray(imported.people) || !Array.isArray(imported.flows)) throw new Error('Estrutura inválida');
      state = imported; ensureSchema(); currentFlowId = state.flows[0]?.id || ''; calendarYear = Number(state.meta.year) || new Date().getFullYear(); saveState('Cópia importada'); renderAll();
    } catch (error) { showToast(`Não foi possível importar: ${error.message}`); }
    event.target.value = '';
  }

  async function copyJson() {
    try { await navigator.clipboard.writeText(JSON.stringify(state, null, 2)); showToast('JSON copiado'); }
    catch { showToast('O browser bloqueou a cópia'); }
  }

  function handleGlobalSearch(event) {
    const query = normalise(event.target.value.trim());
    if (query.length < 2) { closeSearch(false); return; }
    const results = [];
    state.people.forEach(person => { const shift = getShift(person.shiftId); if (normalise([person.name,person.title,person.email,person.notes,shift?.name,shift?.code].join(' ')).includes(query)) results.push({type:'Pessoa',title:person.name,subtitle:`${person.title}${shift ? ` · ${shift.code || shift.name}` : ''}`,view:'org',id:person.id}); });
    state.dutySheets.forEach(sheet => { const responsible=getPerson(sheet.responsibleId), backup=getPerson(sheet.backupId), secondary=getPerson(sheet.secondaryBackupId); if (normalise([sheet.title,sheet.purpose,sheet.responsibilities,sheet.dailyTasks,sheet.periodicTasks,sheet.authority,sheet.kpis,sheet.systems,sheet.absenceInstructions,sheet.documents,sheet.notes,responsible?.name,backup?.name,secondary?.name].join(' ')).includes(query)) results.push({type:'Caderno',title:sheet.title,subtitle:`${responsible?.name || 'Responsável por definir'} · ${backup || secondary ? 'Com substituto' : 'Sem substituto'}`,view:'duties',id:sheet.id}); });
    state.shifts.forEach(shift => { if (normalise([shift.name,shift.code,shift.start,shift.end,shift.notes].join(' ')).includes(query)) results.push({type:'Turno',title:shift.name,subtitle:`${shift.start}–${shift.end} · ${formatNumber(calculateShiftCapacity(shift).hoursDay)} h/dia`,view:'org',id:''}); });
    state.flows.forEach(flow => {
      if (normalise([flow.name,flow.description].join(' ')).includes(query)) results.push({type:'Fluxo',title:flow.name,subtitle:flow.description,view:'flows',id:flow.id});
      flow.nodes.forEach(node => { if (normalise([node.label,node.system,node.kpi,node.notes].join(' ')).includes(query)) results.push({type:'Etapa',title:node.label,subtitle:flow.name,view:'flows',id:flow.id,nodeId:node.id}); });
    });
    state.systems.forEach(system => { if (normalise([system.name,system.category,system.supplier,system.notes].join(' ')).includes(query)) results.push({type:'Sistema',title:system.name,subtitle:system.category,view:'systems',id:system.id}); });
    state.machines.forEach(machine => { if (normalise([machine.code,machine.name,machine.brand,machine.model,machine.serialNumber,machine.location,machine.supplier].join(' ')).includes(query)) results.push({type:'Máquina',title:machine.name,subtitle:[machine.code,machine.brand,machine.model].filter(Boolean).join(' · '),view:'machines',id:machine.id}); });
    state.competencies.forEach(item => { const person=getPerson(item.personId), machine=state.machines.find(x=>x.id===item.machineId); if (person&&machine&&normalise([person.name,person.title,machine.name,machine.code,competencyLevelLabels[item.level],item.notes].join(' ')).includes(query)) results.push({type:'Competência',title:`${person.name} · ${machine.name}`,subtitle:`Nível ${item.level} · ${competencyLevelLabels[item.level]}`,view:'competencies',id:item.id}); });
    state.calendarEvents.forEach(item => { if (normalise([item.title,item.description].join(' ')).includes(query)) results.push({type:'Evento',title:item.title,subtitle:formatDate(item.start),view:'calendar',id:item.id}); });
    state.improvements.forEach(item => { if (normalise([item.title,item.description,item.supplier,item.expectedBenefit,item.evidence].join(' ')).includes(query)) results.push({type:'Melhoria',title:item.title,subtitle:`${improvementAreaLabels[item.area] || item.area} · ${improvementStatusLabels[item.status] || item.status}`,view:'improvements',id:item.id}); });
    $('#searchResults').innerHTML = results.slice(0,30).map((result,index) => `<div class="search-result" data-result-index="${index}"><span class="search-result-type">${esc(result.type)}</span><div><strong>${esc(result.title)}</strong><small>${esc(result.subtitle || '')}</small></div></div>`).join('') || emptyInline('Sem resultados.');
    $('#searchOverlay').classList.remove('hidden');
    $$('[data-result-index]').forEach(element => element.addEventListener('click', () => openSearchResult(results[Number(element.dataset.resultIndex)])));
  }

  function openSearchResult(result) {
    switchView(result.view);
    if (result.view === 'org') { selectedPersonId = result.id; renderOrg(); }
    if (result.view === 'duties') { const sheet=state.dutySheets.find(item => item.id === result.id); $('#dutySearch').value=sheet?.title || ''; renderDutySheets(); }
    if (result.view === 'flows') { currentFlowId = result.id; selectedNodeId = result.nodeId || ''; renderFlow(); }
    if (result.view === 'calendar') { const event = state.calendarEvents.find(item => item.id === result.id); if (event) { calendarYear=Number(event.start.slice(0,4)); renderCalendar(); } }
    if (result.view === 'systems') { $('#systemSearch').value = result.title; renderSystems(); }
    if (result.view === 'machines') { $('#machineSearch').value = result.title; renderMachines(); }
    if (result.view === 'competencies') { $('#competencySearch').value = result.title.split(' · ')[0]; renderCompetencies(); }
    if (result.view === 'improvements') { $('#improvementSearch').value = result.title; renderImprovements(); }
    $('#globalSearch').value = ''; closeSearch();
  }
  function closeSearch(clear = true) { $('#searchOverlay').classList.add('hidden'); if (clear) $('#globalSearch').value = ''; }

  function populateSelect(select, options, selected, placeholder) {
    const current = selected ?? select.value;
    select.innerHTML = `${placeholder !== undefined ? `<option value="">${esc(placeholder)}</option>` : ''}${options.map(option => `<option value="${esc(option.value)}">${esc(option.label)}</option>`).join('')}`;
    select.value = options.some(option => String(option.value) === String(current)) || current === '' ? current : '';
  }
  function splitLines(value) { return String(value || '').split(/\n+/).map(item => item.replace(/^[-•\s]+/, '').trim()).filter(Boolean); }
  function printMultiline(value) { return esc(value || '').replace(/\n/g, '<br>'); }
  function formatNumber(value) { return new Intl.NumberFormat('pt-PT', {maximumFractionDigits:2}).format(Number(value) || 0); }
  function calculateShiftCapacity(shift) {
    const people = state.people.filter(person => person.status !== 'inactive' && person.shiftId === shift.id);
    const fte = people.reduce((sum, person) => sum + Number(person.capacityPercent ?? 100) / 100, 0);
    const hoursDay = fte * Number(shift.hoursPerDay || 0);
    const hoursWeek = hoursDay * Number(shift.daysPerWeek || 0);
    const departments = new Map();
    people.forEach(person => {
      const name = getDepartment(person.departmentId)?.name || 'Sem departamento';
      departments.set(name, (departments.get(name) || 0) + Number(person.capacityPercent ?? 100) / 100);
    });
    return {people,headcount:people.length,fte,hoursDay,hoursWeek,departments};
  }
  function renderShiftCapacity(container, compact = false) {
    if (!container) return;
    const visibleShifts = compact ? state.shifts.filter(shift => shift.status !== 'inactive') : state.shifts;
    const cards = visibleShifts.map(shift => {
      const capacity = calculateShiftCapacity(shift);
      const departmentText = [...capacity.departments.entries()].sort((a,b) => b[1]-a[1]).map(([name,fte]) => `${name}: ${formatNumber(fte)} FTE`).join(' · ');
      return `<article class="shift-capacity-card ${shift.status === 'inactive' ? 'inactive' : ''}" style="--shift-color:${esc(shift.color || '#2D69A1')}">
        <div class="shift-card-head"><div><span>${esc(shift.code || '—')}</span><strong>${esc(shift.name)}</strong></div>${compact ? '' : `<button class="shift-edit-button" type="button" data-edit-shift="${esc(shift.id)}">Editar</button>`}</div>
        <div class="shift-time">${esc(shift.start || '—')} <b>→</b> ${esc(shift.end || '—')} · ${formatNumber(shift.hoursPerDay)} h/dia</div>
        <div class="shift-metrics"><div><strong>${capacity.headcount}</strong><small>Pessoas</small></div><div><strong>${formatNumber(capacity.fte)}</strong><small>FTE</small></div><div><strong>${formatNumber(capacity.hoursDay)} h</strong><small>Capacidade/dia</small></div><div><strong>${formatNumber(capacity.hoursWeek)} h</strong><small>Capacidade/semana</small></div></div>
        ${compact ? '' : `<div class="shift-departments">${esc(departmentText || 'Ainda sem pessoas atribuídas')}</div>`}
      </article>`;
    });
    const unassigned = state.people.filter(person => person.status !== 'inactive' && !person.shiftId);
    if (unassigned.length) cards.push(`<article class="shift-capacity-card unassigned"><div class="shift-card-head"><div><span>!</span><strong>Sem turno definido</strong></div></div><div class="shift-time">Atribuir turno para incluir na capacidade</div><div class="shift-metrics"><div><strong>${unassigned.length}</strong><small>Pessoas</small></div><div><strong>0 h</strong><small>Capacidade calculada</small></div></div></article>`);
    container.innerHTML = cards.join('') || emptyInline('Crie o primeiro turno para calcular a capacidade.');
    $$('[data-edit-shift]', container).forEach(button => button.addEventListener('click', () => openShiftForm(getShift(button.dataset.editShift))));
  }
  function initials(name) { const parts = String(name || '?').trim().split(/\s+/); return (parts[0]?.[0] || '?') + (parts.length > 1 ? parts.at(-1)[0] : ''); }
  function monthlyEquivalent(system) { return system.billing === 'monthly' ? Number(system.cost || 0) : Number(system.cost || 0) / 12; }
  function getFlowById(id) { return state.flows.find(flow => flow.id === id); }
  function compareImprovements(a,b) {
    const priorityOrder = {critical:0,high:1,medium:2,low:3};
    const statusOrder = {in_progress:0,blocked:1,analysis:2,planned:3,identified:4,implemented:5,measured:6};
    return (priorityOrder[a.priority] ?? 9) - (priorityOrder[b.priority] ?? 9) || (statusOrder[a.status] ?? 9) - (statusOrder[b.status] ?? 9) || String(a.dueDate || '9999').localeCompare(String(b.dueDate || '9999'));
  }
  function localDateString(date) { const y=date.getFullYear(),m=String(date.getMonth()+1).padStart(2,'0'),d=String(date.getDate()).padStart(2,'0'); return `${y}-${m}-${d}`; }
  function emptyInline(message) { return `<div style="padding:18px;color:var(--muted);text-align:center">${esc(message)}</div>`; }
  function emptyState(title, message) { return `<div class="empty-state"><div class="empty-icon">＋</div><h3>${esc(title)}</h3><p>${esc(message)}</p></div>`; }
  function showToast(message) { const toast=$('#toast'); toast.textContent=message; toast.classList.add('show'); clearTimeout(toastTimer); toastTimer=setTimeout(() => toast.classList.remove('show'),2400); }

  function applyAccessMode() {
    document.body.classList.toggle('read-only', !CAN_EDIT);
    document.body.dataset.accessRole = ACCESS.role || 'viewer';
    if (!CAN_EDIT) {
      $('#saveStateText').textContent = 'Modo de consulta';
      $$('#view-data input, #view-data textarea, #view-data select').forEach(field => { field.disabled = true; });
    } else if (!serverSaveBlocked && $('#saveStateText').textContent === 'A ligar ao servidor…') {
      $('#saveStateText').textContent = 'Ligado ao servidor';
    }
  }

  initialise();
})();
