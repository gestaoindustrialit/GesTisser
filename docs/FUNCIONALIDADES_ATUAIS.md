# GesTisser — funcionalidades atuais e regras de cálculo

> **Referência funcional do estado atual da solução**  
> Data da revisão: 25 de setembro de 2026 · Base: código existente no repositório.

## 1. Objetivo e forma de usar este documento

Este documento tem dois níveis de leitura:

1. **Resumo executivo** — explica rapidamente o que a solução cobre;
2. **Referência detalhada** — descreve cada área, os cálculos, as fontes dos dados e os pontos que podem ser ajustados à realidade da empresa.

Não é uma especificação de funcionalidades futuras. Uma capacidade só é apresentada como disponível quando existe no código atual. Os campos meramente informativos não são confundidos com automatismos. Em particular, indicadores sem dados suficientes são identificados como **não calculáveis**, em vez de lhes ser atribuído um valor estimado.

---

# Parte I — resumo executivo

## 2. O que é a solução

O GesTisser é uma aplicação web interna, em PHP e SQLite, que reúne:

- gestão de utilizadores, acessos, equipas, projetos, tarefas e pedidos internos;
- recursos humanos, assiduidade, horários, ausências, férias, banco de horas, avaliações e exportação para payroll;
- CRM, desde leads e atividades até oportunidades e clientes;
- ERP industrial, incluindo clientes, fornecedores, artigos, matérias-primas, compras, stock, máquinas, operações, gamas, ordens de fabrico e dossiers de produção;
- shopfloor para ponto, pausas, ausências e execução das operações;
- planeamento e indicadores de produção;
- integrações HTTP configuráveis, com execução e histórico;
- branding, auditoria e parâmetros da empresa.

## 3. Visão rápida por área

| Área | Capacidades atuais | Principais ajustes empresariais |
|---|---|---|
| Acesso e segurança | Login por palavra-passe, sessão por PIN no shopfloor, perfis, permissões, CSRF, rate limiting e auditoria | Perfis, permissões por utilizador, política de sessão e utilizadores ativos |
| Colaboração | Equipas, membros, projetos, tarefas/subtarefas, Kanban, checklists, notas, anexos e recorrências | Campos da tarefa, estados, responsáveis e modelos de checklist |
| Pedidos internos | Formulários globais com campos personalizados e encaminhamento para equipas | Formulários, campos, equipa destinatária e tratamento do ticket |
| RH | Cadastro, departamentos, horários, calendário, férias, ausências, banco de horas, competências, avaliações e alertas | Horários, motivos, tolerâncias, regras de prémio, alertas e aprovações |
| Payroll | Apuramento mensal de presença, trabalho noturno, horas extra, ausências e variáveis; exportação XLSX | Janela noturna, horários, códigos e variáveis salariais |
| CRM | Leads, pipeline, clientes, contactos, projetos, agenda, tarefas, atividades, relatórios e lembretes | Visibilidade, fases, probabilidades e prazo de lead inativo |
| ERP mestre | Clientes, fornecedores, artigos, matérias-primas, documentos, fichas técnicas e importação/exportação | Unidades, famílias, tipos, preços, documentos e sequências |
| Compras e stock | Encomendas, receções parciais, movimentos, transferências, reservas e inventário | Armazéns, localizações, stock mínimo, custos e regras de receção |
| Produção | Operações, máquinas, centros, gamas versionadas, OF, execução, consumos, qualidade, etiquetas e dossier | Tempos padrão, eficiência, desperdício, taxas horárias e validações de fecho |
| BI | Produção, eficiência, prazo, paragens, setup, desperdício, gráficos e alertas | Limiares, filtros e calendários/capacidade ainda em falta |
| Integrações | API genérica e TOConline, autenticação, mapeamentos, paginação, testes, agendamento e logs | Endpoints, credenciais, payloads, frequência e política de itens sem correspondência |

## 4. Fluxos principais de ponta a ponta

### 4.1 Do artigo à produção

1. Criar clientes, fornecedores, matérias-primas, artigo acabado, documentos e ficha técnica.
2. Definir operações, máquinas compatíveis e centros de trabalho.
3. Criar uma versão da gama do artigo, com materiais e dependências; ativá-la.
4. Criar/libertar a ordem de fabrico (OF). A aplicação copia uma fotografia da ficha técnica e da gama, para preservar o histórico.
5. Reservar materiais disponíveis, planear a capacidade e disponibilizar a OF no shopfloor.
6. O operador consulta documentos/checklists, inicia, pausa, retoma e termina operações, registando quantidade boa, rejeitada e consumos.
7. Emitir etiquetas de rolo/tinta e consultar/imprimir o dossier.
8. Rever custos e fechar a OF; irregularidades bloqueiam o fecho, salvo override autorizado e justificado.

### 4.2 Da presença ao payroll

1. Associar colaborador a departamento e horário.
2. Registar entradas/saídas e pausas; submeter ausências e férias.
3. RH valida/corrige os registos diários em **Resultados**.
4. O banco de horas é reconstruído apenas com dias totalmente validados.
5. Payroll agrega horas diurnas/noturnas/extra, códigos de ausência e variáveis manuais.
6. Após resolução ou confirmação dos avisos, exportar um XLSX com Payroll, Assiduidade e Detalhe de Horas.

### 4.3 Do lead à oportunidade

1. Registar lead e responsável, respeitando visibilidade privada/partilhada.
2. Criar chamadas, reuniões, tarefas e lembretes relacionados.
3. Acompanhar leads novos, ativos e sem atividade.
4. Gerir oportunidade no pipeline; a mudança de fase atualiza a probabilidade automática, salvo probabilidade manual.
5. Consultar valor total e valor ponderado do pipeline.

---

# Parte II — referência funcional detalhada

## 5. Fundamentos, acessos e administração

### 5.1 Instalação e autenticação

- Instalação inicial e criação do primeiro administrador.
- Registo, login e logout por credenciais.
- Login simplificado por PIN para utilização operacional; esta sessão apresenta navegação reduzida e não expõe CRM.
- Sessão autenticada, proteção CSRF nas alterações, validação de uploads e limitação de tentativas em operações protegidas.
- Recuperação automática/migração incremental da base de dados no arranque das áreas aplicáveis.

### 5.2 Utilizadores, perfis e permissões

- Criar, editar, eliminar e importar utilizadores em massa.
- Guardar identificação, contactos profissional/pessoal, NIF, NISS, datas de admissão/saída, profissão/cargo, departamento, horário, chefia, perfil e dados operacionais.
- Carregar documentos do colaborador.
- Distinguir administrador, RH e perfis ERP; permissões ERP/CRM e payroll podem ter override por utilizador.
- Restringir utilizadores de shopfloor à navegação operacional.
- Consultar logs da aplicação e trilhos de auditoria das áreas críticas.

### 5.3 Empresa e configuração

- Dados legais e contactos da empresa.
- Logótipos para barra de navegação e relatórios.
- Configuração ERP: sequências documentais, catálogos, parâmetros de BI, permissões e outras tabelas auxiliares.
- A numeração documental usa sequências para evitar duplicações nas entidades suportadas.

## 6. Trabalho colaborativo

### 6.1 Dashboard, equipas e projetos

- Dashboard inicial com acesso às áreas e elementos relevantes para o utilizador.
- Criar equipas, adicionar membros e limitar o acesso aos respetivos membros.
- Criar, alterar e eliminar projetos quando o perfil o permite.
- Notas de equipa e projeto, respostas a notas e documentos do projeto.

### 6.2 Tarefas

- Criar tarefas e subtarefas.
- Vistas em lista e quadro Kanban.
- Alterar estado, responsável, prazo e tempo registado.
- Notas e anexos por tarefa.
- Checklists por tarefa, com criação, edição e conclusão dos itens.
- Eliminação com controlo de acesso.
- Campos personalizados de tarefa definidos ao nível da equipa.
- Configuração dos campos que devem aparecer no momento da criação.

### 6.3 Tarefas recorrentes

- Criar, editar e remover definições de recorrência.
- Concluir e reabrir ocorrências.
- Manter estado de checklist da tarefa recorrente.
- Parametrizar responsável, periodicidade e conteúdo operacional.

### 6.4 Relatório diário

- Envio manual a partir do projeto.
- Execução automática por cron.
- Entrega por `mail()` ou SMTP autenticado; se não existir transporte funcional, fica registo da tentativa.
- Consulta do relatório diário guardado.

### 6.5 Pedidos às equipas

- Administrador cria formulários globais e escolhe a equipa de destino.
- Campos disponíveis: texto, número, data, seleção e texto longo.
- Qualquer utilizador autenticado pode submeter o formulário.
- A submissão cria um ticket, que pode ser atualizado e acompanhado pela equipa responsável.
- Notas, anexos e detalhe do ticket estão disponíveis na área da equipa.

### 6.6 Modelos de checklist

- Criar e manter checklists reutilizáveis.
- Associar checklist a operações e definir o momento da validação.
- O shopfloor impede/confirma passos operacionais conforme o modelo associado.

## 7. Recursos Humanos

### 7.1 Estrutura organizacional

- Grupos e departamentos.
- Associação do colaborador a departamento, cargo e chefia.
- Organograma visual e exportação PDF.
- Ordenação organizacional configurável.
- Cadernos de encargos/funções por departamento e estado.

### 7.2 Horários e calendário

- Horários ativos/inativos, com um ou dois períodos diários, pausa e máscara de dias da semana.
- Associação individual de horário.
- Calendário anual com feriados e outros eventos; os feriados alimentam o payroll.
- Calendário da empresa visível no shopfloor.

### 7.3 Competências

- Matriz colaborador × máquina.
- Nível de competência de 0 a 4, com notas.
- Filtro por máquina e utilização dos dados na organização industrial.

### 7.4 Ponto e pausas

- Ponto de entrada/saída na barra global e no shopfloor.
- Histórico diário.
- Início/fim de pausa com motivo, tipo e comentário obrigatório quando configurado.
- Catálogo de pausas e paragens, ativação/inativação e dashboard agregado.
- A duração de uma pausa é `fim − início`; quando está aberta, usa-se a hora atual para apresentação.

### 7.5 Ausências

- Pedido pelo colaborador, com intervalo, motivo, detalhe e justificativo.
- Aprovação/rejeição por chefia, RH ou administrador, conforme perfil.
- Edição administrativa e submissão posterior de justificação.
- Catálogo de motivos: criação, edição, importação em massa, ativação e visibilidade no shopfloor.
- Deteção, no payroll, de presença e ausência no mesmo dia.

### 7.6 Férias

- Pedido de férias e consulta no calendário.
- Estado de aprovação e integração no mapa mensal do payroll com o código `Fr`.

### 7.7 Resultados e validação diária

- Consulta por colaborador e período dos registos de ponto.
- Correção da hora de entrada/saída.
- Afetação de ausência.
- Validação de linha ou de dia e possibilidade de reabertura.
- Override manual do banco de horas diário, que prevalece sobre o cálculo automático desse dia.

### 7.8 Banco de horas

O saldo automático considera apenas dias em que **todos** os registos de ponto estão validados.

Para cada dia:

```text
segundos efetivos = Σ(saída − entrada) − Σ(sobreposição das pausas com os intervalos trabalhados)
saldo diário (min) = arredondar((segundos efetivos − objetivo diário × 60) / 60)
saldo total = Σ(saldos diários ou overrides diários)
```

Regras atuais:

- objetivo predefinido: **480 minutos/dia**;
- só se emparelham entradas seguidas de uma saída posterior;
- pausas são descontadas apenas na parte que intersecta um intervalo trabalhado;
- se existir override no dia, usa-se diretamente esse valor;
- sem qualquer dia validado, o resultado é `null`, permitindo conservar um saldo inicial manual.

**Ajustável:** objetivo diário, regra de arredondamento, tratamento de marcações incompletas e inclusão de feriados/ausências. Atualmente o calculador central recebe um único objetivo diário; para objetivos diferentes por horário seria necessária evolução.

### 7.9 Avaliações e prémios

- Três períodos: janeiro–abril, maio–agosto e setembro–dezembro.
- Perfis atuais: **Operador** e **Responsável / Support**.
- Regras podem ser definidas por ano, perfil, grupo de departamento ou departamento; a regra mais específica é escolhida primeiro, e podem ser herdadas regras de anos anteriores.
- Sugestão automática de pontualidade: conta dias em que a primeira entrada é posterior ao início do horário, sem tolerância.
- Sugestão automática de ausências: conta pedidos aprovados que se sobreponham ao período.
- Histórico, cálculo, fecho anual, PDF e envio por e-mail.

#### Fórmula do período

```text
valor performance = tabela_performance[pontuação]
valor comportamento = tabela_comportamento[pontuação]

valor pontualidade = valor_sem_atrasos, se número_atrasos = 0
                     número_atrasos × penalização_unitária, caso contrário

valor ausências = valor_sem_ausências, se número_ausências = 0
                  número_ausências × penalização_unitária, caso contrário

total período = performance + comportamento + pontualidade + ausências
desvio período = máximo período − total período
```

Valores default atuais:

| Perfil | Performance (0/1/2/3) | Comportamento (0/1/2/3) | Pontualidade: zero / por ocorrência | Ausência: zero / por ocorrência | Máx. período |
|---|---:|---:|---:|---:|---:|
| Operador | 0 / 12,50 / 25 / 0 € | 0 / 12,50 / 25 / 0 € | 25 / −2,50 € | 50 / −50 € | 125 € |
| Responsável / Support | 0 / 37,50 / 75 / 0 € | 0 / 12,50 / 25 / 0 € | 50 / −5 € | 100 / −100 € | 250 € |

> A pontuação `3` vale atualmente `0` nas tabelas default. Esta regra é literal e deve ser revista com a empresa caso `3` deva representar a melhor avaliação.

#### Fecho anual

```text
bónus final por ausências: 0 → 250 €; 1 → 125 €; 2 → 62,50 €; 3 ou mais → 0 €
total anual = Σ(totais dos períodos) + bónus final
desvio anual = máximo anual − total anual
```

Máximos default: 625 € para Operador e 1 000 € para Responsável/Support. Todos os mapas, penalizações, bónus e máximos são parametrizáveis em regras de avaliação.

### 7.10 Alertas RH

- Configuração e execução de alertas.
- Pré-visualização PDF.
- Execução manual ou periódica por cron.
- Envio por correio e registo de sucesso/falha.

### 7.11 Sorteio RH

- Área de sorteio entre os colaboradores elegíveis, destinada a dinâmicas internas.

## 8. Payroll

### 8.1 Âmbito

- Seleção por mês, departamento, colaboradores e estado ativo.
- Exclusão de contas exclusivamente PIN.
- Variáveis manuais: comissões, ajudas de custo, quilómetros, prémio, subsídios extra, gratificação/saldo e outros.
- Avisos para horários em falta, entradas consecutivas, saída sem entrada, intervalo inválido, ponto aberto e conflito ausência/presença.
- Fecho e reabertura auditada do mês.
- Exportação XLSX com três folhas: **Payroll**, **Assiduidade** e **Detalhe Horas**.

### 8.2 Cálculos de horário e presença

```text
duração planeada = duração do 1.º período + duração do 2.º período − pausa configurada
tempo trabalhado = Σ(saída − entrada) − pausas que intersectam os intervalos
hora extra = max(0, tempo trabalhado − duração planeada)
tempo normal = tempo trabalhado − hora extra
```

- Períodos que atravessam a meia-noite são suportados.
- A janela noturna é configurável; default **22:00–07:00**.
- Trabalho noturno é a sobreposição real dos intervalos com essa janela, descontando pausas.
- Noturno normal = mínimo entre noturno e tempo normal.
- Extra noturna = `max(0, noturno − noturno normal)`.
- Primeira hora extra = até 1 hora; extra seguinte = excedente à primeira hora.
- Trabalho em dia fora da máscara semanal é acumulado como horas de descanso/folga.
- Cada dia com trabalho incrementa dias trabalhados e dias para subsídio de alimentação.

### 8.3 Códigos atuais

- `Fr` férias; `FJ` falta justificada; `FI` injustificada; `BX` baixa;
- `AD` admissão; `DM` saída; `OF` oferta; `Lt` luto; `LP` parental;
- `LM` maternidade; `DD` desconto; `LC` casamento.

Motivos internos `MOT-*` tornam-se `FJ`, exceto quando o texto identifica explicitamente outro código. **Ajustável:** códigos, precedências, direito a alimentação, feriados trabalhados e regras legais de majoração; o sistema exporta quantidades/horas, não calcula remuneração líquida, retenções ou contribuições.

## 9. CRM

### 9.1 Funcionalidades

- Dashboard, Meu Dia, Leads, Pipeline, Clientes, Contactos, Projetos, Atividades, Agenda, Tarefas, Relatórios e Configurações.
- Leads com empresa, contacto, canais, origem, prioridade, potencial, valor, probabilidade, segmento, notas e decisão esperada.
- Visibilidade partilhada ou privada; utilizador comum vê o que é partilhado, criado por si ou atribuído a si.
- Atividades com tipo, responsável, datas, prioridade, lembrete e relação a outra entidade.
- Contador na navegação para atividades pendentes vencidas ou com vencimento/lembrete próximo.
- Linha temporal de eventos.

### 9.2 Pipeline e indicadores

Fases e probabilidades automáticas atuais:

| Fase | Probabilidade |
|---|---:|
| NEW | 10% |
| CONTACTED | 20% |
| QUALIFIED | 40% |
| PROPOSAL | 60% |
| NEGOTIATION | 80% |
| WON | 100% |
| LOST | 0% |

Se a probabilidade tiver sido marcada como manual, a mudança de fase não a substitui.

```text
pipeline aberto = Σ(valor estimado das oportunidades não ganhas/perdidas)
pipeline ponderado = Σ(valor estimado × probabilidade / 100)
lead inativo = hoje − última atividade (ou criação) ≥ limite configurado
```

## 10. ERP — dados mestres e documentos

### 10.1 Clientes e fornecedores

- CRUD, estado ativo, contactos, condições comerciais e fiscais.
- Clientes: vendedor, desconto, saldo e plafond, entre outros dados.
- Fornecedores: catálogo e dados necessários à compra/importação.
- Importação por folha de cálculo e modelo para preenchimento; exportação de clientes.

### 10.2 Artigos acabados

- Código, descrição, cliente, tipo, unidade, dimensões, gramagem, cores, custo padrão, stock mínimo e estado.
- Materiais/BOM com quantidade por unidade e desperdício.
- Cores por face e ordem, Pantone e tinta associada.
- Documentos do artigo, incluindo arte principal, versão, miniatura e pré-visualização de PDF/imagem.
- Folha de cálculo de importação e ponte para produtos legados.

### 10.3 Matérias-primas

- Código, descrição, categoria/grupo, tipo, unidade, cor, largura, gramagem, preços, fornecedor preferencial, stock mínimo/máximo, ponto de reposição e prazo.
- Famílias normalizadas e referências auxiliares criadas/resolvidas durante importação, conforme modo.
- Modelo e importação CSV/XLSX.

### 10.4 Máquinas e equipamentos

- Código, nome, centro, departamento, responsável, fornecedor, estado, criticidade e disponibilidade.
- Anexos com validação de tipo de ficheiro.
- Competências dos operadores ligadas à máquina.

## 11. Operações, gamas e capacidade

### 11.1 Operações

- Catálogo de operações, instruções, unidade produtiva, operadores mínimos, confirmação, checklist e máquinas compatíveis.
- Metadados e parâmetros operacionais configuráveis.

### 11.2 Gamas/routings

- Versões por artigo: rascunho, ativa e arquivada.
- Etapas ordenadas, operação, centro, máquina principal/compatíveis, operadores, setup, velocidade/tempo, quantidade base, desperdício, espera, transferência, paralelismo, instruções, qualidade e dependências.
- Materiais por etapa e opção de reservar na libertação.
- Uma versão vazia, com operação inativa, máquina incompatível ou dependência circular não pode ser ativada.
- A OF recebe um snapshot; alterações futuras à gama não reescrevem o histórico da OF.

### 11.3 Tempo planeado da etapa

Primeiro:

```text
quantidade ajustada = quantidade da OF × (1 + desperdício % / 100)
```

Depois, conforme a unidade de cálculo:

```text
segundos por unidade: execução_min = quantidade_ajustada × valor / quantidade_base / 60
unidades por hora:    execução_min = quantidade_ajustada / valor × 60
metros por minuto:    execução_min = quantidade_ajustada / valor
outro/min por unidade: execução_min = quantidade_ajustada × valor / quantidade_base

tempo planeado = setup + execução_min + espera + transferência
```

O resultado é arredondado a três casas. Valor/quantidade base são protegidos contra divisão por zero.

### 11.4 Capacidade do centro

```text
capacidade líquida diária = max(0, minutos diários) × limitar(eficiência %, 0..100) / 100
fila = Σ(minutos planeados das operações ainda não concluídas/canceladas)
dias necessários = teto(max(0, fila) / capacidade líquida diária)
```

A data prevista avança apenas de segunda a sexta. Atualmente não desconta feriados, férias, turnos, manutenção nem capacidade por máquina. Se a capacidade diária for zero, não existe previsão.

## 12. Compras, armazém e stock

### 12.1 Compras e receções

- Encomendas a fornecedor e linhas de artigo/matéria-prima.
- Importação de encomenda em PDF, extração de texto, deteção do fornecedor, normalização e correspondência de linhas.
- Receção parcial ou total em armazém/localização.
- Não permite receber mais do que a quantidade em falta nem repetir a mesma linha na operação.
- Etiquetas por linha (0 a 1 000).
- Cada receção cria movimento de entrada e incrementa stock físico.
- Estado: **Recebida** se não restar nenhuma linha; caso contrário **Parcial**.

```text
quantidade em falta = max(0, encomendada − Σ entradas anteriores)
custo total do movimento = quantidade recebida × custo unitário
```

### 12.2 Movimentos e transferências

- Importação de movimentos e modelo de folha de cálculo.
- Transferência entre armazéns/localizações, preservando lote.
- Validação de armazém/localização, origem diferente do destino e stock suficiente.
- A transferência reduz stock físico na origem, cria/incrementa o destino e gera movimento auditável.

### 12.3 Inventário

Une matérias-primas e artigos acabados por armazém, localização e lote.

```text
stock disponível = stock físico − reservado − bloqueado
valor de stock = stock físico × custo unitário
```

- Matéria-prima: custo unitário usa preço médio não zero; em alternativa preço padrão; senão zero.
- Artigo acabado: usa custo padrão.
- Filtros: texto, cor, largura, gramagem, tipo, fornecedor, tipo de material, armazém e estado.
- Estado **baixo**: stock mínimo > 0 e disponível ≤ mínimo.
- Pesquisa ignora maiúsculas, acentos e pontuação e exige a presença de todas as palavras.

### 12.4 Reservas de produção

```text
necessidade = quantidade da OF × quantidade do material por unidade
disponível no saldo = físico − reservado − bloqueado
```

Quando configurado, a necessidade é reservada por ordem dos saldos disponíveis. Pode existir necessidade não coberta; as reservas guardam quantidade necessária e efetivamente reservada.

## 13. Ordens de fabrico, shopfloor e dossier

### 13.1 Ordens e planeamento

- Criação e acompanhamento de OF, artigo, cliente, quantidades, estado e data prevista.
- Plano de produção em ecrã próprio.
- Snapshot de ficha técnica aprovada e gama ativa na libertação.
- Libertação administrativa sem gama exige motivo e fica auditada.

### 13.2 Execução no shopfloor

- Seleção de centro de trabalho/dispositivo.
- Lista de operações atribuídas/disponíveis.
- Consulta e confirmação de documentos da OF.
- Validação de checklist operacional.
- Iniciar, pausar, retomar e terminar operação.
- Registar tempos, máquina, quantidade boa/rejeitada, desperdício, paragens e consumos.
- Comunicados internos com publicação, confirmação de leitura, ativação e eliminação.
- No mesmo portal: ponto, pausas, ausências, justificações e férias.

### 13.3 Métricas do dossier

Para cada operação, tempo real é:

```text
minutos reais = Σ((fim ou agora) − início) − pausas registadas
```

Consolidação da OF:

```text
quantidade boa = máximo da quantidade boa acumulada entre operações
rejeitada = soma das quantidades rejeitadas de todas as operações
em falta = max(0, planeada − boa)
excesso = max(0, boa − planeada)
execução do plano = boa / planeada × 100
desperdício = rejeitada / (boa + rejeitada) × 100
custo unitário = custo real / boa
custo por mil = custo unitário × 1 000
```

> A rejeição é somada entre operações, enquanto a produção boa usa o máximo. Isto evita multiplicar a quantidade boa ao longo da rota, mas pode somar refugo ocorrido em várias fases — comportamento que deve ser confirmado com o processo da empresa.

### 13.4 Custos industriais

**Planeado de materiais**

```text
quantidade prevista = quantidade_por_unidade × quantidade_planeada × (1 + desperdício % / 100)
custo previsto MP = Σ(quantidade prevista × preço padrão)
```

**Real de materiais**

```text
custo real MP = Σ(quantidade consumida × custo unitário do consumo)
```

**Máquina e mão de obra**

```text
custo máquina = minutos / 60 × taxa horária da máquina
custo mão de obra = minutos / 60 × taxa horária do operador × número de operadores
diferença por categoria = real − planeado
```

A taxa válida é a específica da referência, se existir; caso contrário a genérica. Tem de estar ativa e a data atual dentro da validade. A configuração usa a data atual, não a data histórica da execução.

### 13.5 Relatório administrativo e fecho

- Campos: quantidade, preço unitário de venda, desperdício kg/%, paletes e composição.
- Custos editáveis: matéria-prima, tintas, diluente, acelerador, retardador, outro, impressora, corte/costura, cliché, energia, embalagem, caixas e transporte.
- Os valores automáticos preenchem inicialmente matéria-prima, máquina e mão de obra; alterações ficam auditadas.

```text
custo total administrativo = soma de todos os campos cost_*
custo unitário administrativo = custo total / quantidade produzida
receita = quantidade produzida × preço de venda unitário
margem bruta = receita − custo total
```

Bloqueios normais ao fecho: operação não concluída, operação sem tempo, ausência de quantidade boa ou ausência de consumos/movimentos. Override requer autorização e motivo. O fecho guarda métricas/custos e atualiza a OF para **Fechada**.

### 13.6 Impressão e etiquetas

- Dossier de produção para visualização/impressão e PDF.
- Ficha técnica preparada para impressão.
- Etiqueta de rolo: rolo, lote, artigo, descrição, quantidade, pesos, comprimento e observações.
- Etiqueta de tinta: código, cor, Pantone, quantidade, lote e observações.
- Tokens públicos permitem o acesso especificamente previsto aos documentos/etiquetas sem expor toda a aplicação.

## 14. Business Intelligence industrial

### 14.1 Filtros e agrupamento

- Intervalo, cliente, fornecedor, artigo, OF, máquina, operação e estado.
- Datas invertidas são automaticamente trocadas.
- Gráficos agrupam por dia até 31 dias, por semana até 180 dias e por mês acima disso.
- Período de comparação é o intervalo imediatamente anterior com o mesmo número de dias, embora nem todos os cartões tenham comparação implementada.

### 14.2 Indicadores calculados

```text
produção realizada (%) = produzida / planeada × 100
horas produtivas = Σ(max(0, fim/agora − início − pausas))
horas padrão = Σ(qtd. boa × minutos_por_unidade / base) / 60
             ou Σ(qtd. boa × 60 / unidades_por_hora) / 60
eficiência = horas padrão / horas produtivas reais × 100
cumprimento = OF concluídas até à data prevista / OF concluídas com datas válidas × 100
atraso médio = média(hoje − data prevista) das OF abertas atrasadas
paragem = Σ(fim/agora − início da paragem)
desperdício = refugo / (quantidade boa + refugo) × 100
setup médio = média do intervalo entre fim de uma OF e início de outra na mesma máquina
```

- OF aberta: estado diferente de Concluída, Fechada e Cancelada.
- OF atrasada: aberta, com data prevista anterior a hoje.
- Refugo de arranque: motivo contém “arranque”, “afina” ou “setup”; restante refugo é de produção.
- Sem percentagem, o semáforo é neutro. Indicador positivo: verde ≥95%, amarelo ≥80%, vermelho abaixo. Indicador negativo: verde ≤3%, amarelo ≤8%, vermelho acima.
- Alerta de desperdício usa o parâmetro `bi_warning_waste_percent`, com default de 6%.

### 14.3 Indicadores deliberadamente indisponíveis

- **Horas trabalhadas vs. previstas**: não calculado no BI porque a camada atual não cruza calendário de presença.
- **Horas de máquina vs. disponíveis** e **disponibilidade**: não calculadas porque não existe calendário completo de máquina.
- O gráfico “mão de obra/máquina” usa atualmente as mesmas horas de execução para ambas as séries.
- O bloco de setup mede o intervalo entre OF, não um estado explícito de setup.

Estes pontos devem ser tratados como lacunas a implementar, e não como zeros de desempenho.

## 15. Integrações

- Conectores **Generic API** e **TOConline**.
- Ambientes sandbox/produção; URL base, timeout, tentativas, atraso e validação SSL.
- Autenticação: nenhuma, API key, Bearer, Basic e OAuth2.
- Credenciais cifradas.
- Bloqueio de URL inseguro/destino interno para reduzir SSRF.
- Fluxos de leitura, importação e exportação; GET, POST, PUT, PATCH e DELETE.
- Endpoint, agenda, payload JSON/template, caminhos de resposta/ID/código, paginação, limites e ação para itens sem correspondência.
- Mapeamento de campos, obrigatoriedade, default, tipo e transformação.
- Teste/simulação; operações não-GET só podem ser ativadas depois de uma simulação bem-sucedida.
- Execução manual ou por cron, retries e logs consultáveis.

## 16. Regras transversais e qualidade dos dados

- Alterações críticas de ERP, routing, produção, payroll, avaliações e integrações geram auditoria nas áreas implementadas.
- Uploads têm controlo de tamanho/MIME nos serviços suportados.
- Importações apresentam validações e modelos de colunas.
- Quantidades/custos negativos são normalmente rejeitados ou normalizados para zero.
- Snapshots preservam ficha técnica e gama usadas na OF.
- Datas e estados são usados literalmente em vários indicadores; padronização é essencial.
- A aplicação usa SQLite: backups do ficheiro da base de dados e dos uploads devem fazer parte do procedimento operacional.

## 17. Matriz de parametrização recomendada

Antes de colocar cada módulo em exploração, a empresa deve aprovar:

| Tema | Decisão necessária | Onde afeta |
|---|---|---|
| Perfis e acessos | Quem administra, vê RH/CRM/BI, fecha payroll/OF e pode fazer override | Toda a solução |
| Estrutura | Departamentos, chefias, centros e equipas | RH, projetos, produção |
| Calendário | Feriados, dias úteis, turnos e pausas | RH, payroll, capacidade |
| Assiduidade | Objetivo diário, tolerância de atraso, arredondamento e marcações incompletas | Banco de horas, avaliação |
| Payroll | Janela noturna, códigos, alimentação, extra e majorações | Export Payroll |
| Avaliações | Escala 0–3, tabelas monetárias, penalizações, bónus e máximos | Prémios RH |
| Artigos e materiais | Unidades, categorias, desperdício, custo e stock mínimo | Compras, stock, produção |
| Gamas | Unidades de cálculo, setup, espera, operadores e reservas | Planeamento e custos |
| Custos | Taxas de máquina/operador, validade e data de valorização | Dossier e margem |
| Qualidade | Checklists, documentos obrigatórios e critérios de fecho | Shopfloor/OF |
| CRM | Fases, probabilidade, visibilidade e dias sem atividade | Pipeline |
| BI | Limiares e calendários necessários para indicadores em falta | Gestão industrial |
| Integrações | URLs, credenciais, mapeamentos, frequência e tratamento de erros | Sistemas externos |

## 18. Limites importantes antes de adaptar

1. **Payroll não é motor salarial/legal**: apura e exporta dados; não calcula salário líquido, impostos ou contribuições.
2. **Calendário de capacidade é simplificado**: considera dias úteis de segunda a sexta, sem feriados nem indisponibilidade real.
3. **Disponibilidade de máquina no BI não é calculável** sem calendário próprio.
4. **Taxas de custo usam a validade à data atual**, mesmo ao consultar execução passada.
5. **Pontualidade não tem tolerância** e compara a primeira entrada com a hora exata do horário.
6. **Contagem de ausências na avaliação é por pedido sobreposto**, não necessariamente por dia/horas ausentes.
7. **Métricas dependem de disciplina de registo**: operações, pausas, rejeições, consumos, datas e estados incompletos produzem indicadores incompletos.
8. **As regras default de avaliação merecem validação**, sobretudo a pontuação 3 com valor zero.

## 19. Checklist para ajustar à empresa

1. Nomear responsáveis funcionais de RH, Produção, Logística, Comercial e IT.
2. Confirmar a matriz de perfis e segregação de funções.
3. Carregar estrutura, utilizadores, horários, calendário, centros, máquinas e catálogos.
4. Aprovar por escrito as fórmulas de banco de horas, payroll, avaliação, capacidade, desperdício e custos.
5. Parametrizar uma família/artigo piloto com gama e documentos completos.
6. Executar uma OF de teste de ponta a ponta e reconciliar tempos, stock e custos manualmente.
7. Executar um mês de assiduidade/payroll em paralelo com o processo atual.
8. Validar BI contra uma amostra conhecida e não publicar cartões sem fonte disponível.
9. Testar integrações primeiro em sandbox e manter a obrigatoriedade de dry-run.
10. Definir backup, retenção documental, auditoria e recuperação de desastre.

---

## 20. Glossário

- **BOM** — lista de materiais do artigo.
- **Centro de trabalho** — agrupamento de capacidade onde decorrem operações.
- **Gama/routing** — sequência versionada de operações necessárias para fabricar um artigo.
- **OF** — ordem de fabrico.
- **Override** — exceção autorizada a uma validação normal, com motivo auditado.
- **Snapshot** — cópia imutável dos dados técnicos usada numa OF.
- **Stock bloqueado** — quantidade física impedida de utilização.
- **Stock reservado** — quantidade comprometida para uma necessidade.
- **Tempo padrão** — tempo esperado segundo os parâmetros da gama.

## 21. Rastreabilidade técnica

As principais fontes funcionais desta referência são as páginas de navegação e operação, os serviços em `app/Services/`, as regras em `hr_evaluation_helpers.php` e `payroll_lib.php`, os módulos de integração em `includes/integrations/` e os testes em `tests/`. Quando a implementação e este documento divergirem, a implementação em produção é a fonte efetiva e o documento deve ser atualizado na mesma alteração.
