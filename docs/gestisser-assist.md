# GesTISSER Assist — fases 1 e 2

## Arquitetura e análise do projeto

O ERP é uma aplicação PHP com entradas `.php`, serviços em `app/Services`, SQLite e migrações idempotentes. O destino obrigatório é PHP 7.0.33, incluindo testes. A interface partilha `partials/header.php`, `partials/footer.php`, Bootstrap e os ficheiros CSS/JavaScript existentes. O Shopfloor usa a mesma sessão, incluindo utilizadores com PIN, e conserva o estado da operação na página.

A autenticação é reutilizada de `helpers.php`/bootstrap (`current_user`, sessão `user_id`). O módulo confirma também `users.is_active`. As capacidades reutilizam `gt_erp_user_can`, `erp_permissions`, `erp_user_permissions` e `erp_role_permissions`; uma negação por utilizador prevalece sobre o perfil. Não existe login, PIN ou gestor de perfis paralelo. Os endpoints de recurso verificam a sessão e a capacidade diretamente, evitando redirecionamentos para formulários do Shopfloor.

A configuração legada abre `database.sqlite` na raiz da instalação; o bootstrap também suporta configuração por ambiente. Esta implementação usa o `$pdo` existente, sem criar uma base separada. A migração Assist só é executada após a configuração normal do ERP. O marcador de versão permite que os pedidos seguintes apenas consultem o estado da migração. Não se alteram tabelas de produção ou RH.

A auditoria usa a tabela existente `audit_logs`, na transação da alteração, incluindo versão, aprovação/rejeição, publicação, arquivo, categorias, permissões, configurações, solução de problema, conversão e retenção. Os erros apresentados não contêm detalhes SQL nem credenciais.

O projeto tem armazenamento em `storage/uploads`, `UploadService` e serviços `SharePointIntegration`, `SharePointDocumentSync` e `SharePointArticleImport`. Estes últimos tratam integrações existentes de artigos/documentos e não constituem uma biblioteca técnica validada. Não são ligados automaticamente à pesquisa Assist. Não foi identificado um motor textual existente para conhecimento validado: foi criada uma projeção FTS5 opcional, com alternativa SQL `LIKE` e comparação de termos normalizados. Não são necessárias novas dependências Composer, serviços permanentes ou APIs externas, o que permite alojamento cPanel/PHP 7.0 com PDO SQLite.

## Implementado

- Categorias configuráveis e subcategorias; restrições herdadas dos ascendentes.
- Artigos com campos básicos e avançados opcionais, idioma, material, equipamento/operação, responsável pela validação e data limite de revisão.
- Versões imutáveis do conteúdo, revisão otimista para impedir gravações/aprovações de versões antigas, histórico de decisões humanas.
- Fluxo `draft → review → approved → published → archived`, com rejeição para `draft`. Guardar qualquer edição cria nova versão, retira a publicação e invalida a aprovação anterior.
- Pesquisa por título, categoria indexada, sintomas, causas, condições, palavras-chave, expressões alternativas aprovadas e restantes campos técnicos.
- Chat lateral integrado no rodapé comum do ERP e Shopfloor, sem submeter formulários da operação. Fontes abrem no próprio painel.
- Histórico privado de conversas/mensagens; nova conversa; feedback útil/não útil; sugestões derivadas apenas de títulos publicados e autorizados.
- Reporte textual de problema, prioridade, máquina/equipamento e referência livre de operação/OF. Administração regista solução eficaz e converte-a exclusivamente num novo rascunho.
- Contagens de resultados, perguntas sem resposta e fontes com feedback negativo.
- Configuração de ativação/pilotos, permissões específicas e retenção com execução manual.

## Garantia do motor de respostas

O motor não invoca IA nem rede. Cada resposta técnica é o texto exato do campo `procedure` de **uma única versão publicada e aprovada**, com os campos de segurança, condições e verificação dessa mesma fonte. Não mistura procedimentos. Todos os acessos verificam categoria, ascendentes, aprovação da versão atual, publicação e expiração no servidor.

As expressões alternativas são dados do próprio artigo, abrangidos pela mesma aprovação. A normalização trata acentos portugueses e conserva marcas Unicode para hindi. A pesquisa usa cobertura mínima de 60% dos termos significativos; isto é uma heurística de recuperação, não compreensão semântica. Conteúdo com instruções para o sistema é tratado como texto, nunca executado. A validação humana continua responsável pela correção do procedimento e dos seus sinónimos.

Vários candidatos, ou um material obrigatório não identificado por expressão completa, produzem um pedido de esclarecimento e fontes para seleção. A escolha abre o procedimento identificado, mostrando material, condições e versão. Não há continuação semântica automática de respostas curtas como “água”: o operador deve selecionar a fonte ou repetir a pergunta com o material.

Na ausência de fonte suficiente é devolvida a mensagem de abstenção. Nesta versão, pedidos de valores/parâmetros reconhecidos e perguntas contendo dígitos são recusados de forma conservadora; não é inferido que um número numa instrução responde à pergunta. Isto também limita pesquisas que incluam números de máquinas no próprio texto. Identificadores de máquina conhecidos podem seguir no contexto, sem conferir autorização adicional.

Histórico e fontes diretas são revalidados: uma versão alterada, retirada, expirada ou já não autorizada deixa de ser reproduzida no histórico. O pedido do documento inclui a versão esperada e recusa uma versão diferente. Informação já visualizada no navegador antes da retirada não pode ser apagada retroativamente.

O painel abre diretamente em português, sem seletor de idioma. A API e os artigos mantêm suporte a conteúdo em português, inglês e hindi, com pesquisa por idioma de conteúdo. Os textos técnicos nunca são traduzidos automaticamente; é necessário criar e validar cada versão linguística. A administração e parte das mensagens operacionais continuam em português.

## Estrutura SQLite

| Tabela | Conteúdo principal / relações |
| --- | --- |
| `assist_settings` | chave/valor: versão do esquema, ativação, IDs piloto, retenção |
| `assist_categories` | nome único, categoria superior FK, capacidade exigida, autor FK |
| `assist_articles` | categoria FK, versão atual, estado com CHECK, título, conteúdo JSON, texto normalizado, idioma com CHECK, autor/validador/aprovador FK, datas de aprovação/revisão/validade |
| `assist_versions` | artigo FK, versão, snapshot JSON e autor FK; UNIQUE(artigo, versão) |
| `assist_approvals` | artigo/versão FK composta, decisão humana com CHECK, responsável FK, motivo e data |
| `assist_conversations` | proprietário FK e data |
| `assist_messages` | conversa/proprietário FK, pergunta, resposta JSON, contexto JSON, resultado e data |
| `assist_sources` | mensagem FK, artigo/versão FK composta; PK(mensagem, artigo) |
| `assist_feedback` | mensagem/utilizador FK, útil 0/1 com CHECK; PK(mensagem, utilizador) |
| `assist_problems` | utilizador/mensagem/artigo FK, descrição, contexto declarado, prioridade com CHECK, solução e eficácia |
| `assist_search` (opcional) | tabela virtual FTS5, rowid=artigo, título e texto de pesquisa |

Índices adicionais: estado/categoria/idioma/validade de artigos; utilizador/data de mensagens; proprietário de conversas; artigo/versão das fontes; data de problemas. As tabelas usam chaves estrangeiras e queries preparadas. A retenção elimina mensagens e as respetivas fontes/feedback por cascata, mantém problemas e limpa a sua referência à mensagem. Artigos, versões, decisões e auditoria não são apagados pela retenção do chat.

Os campos de `content_json` são `description`, `symptoms`, `causes`, `conditions`, `procedure`, `safety`, `tools`, `expected`, `verification`, `keywords`, `synonyms`, `equipment`, `material`, `operation`. Os campos não aplicáveis podem estar vazios. Secções, documentos e associações relacionais a múltiplas entidades serão acrescentados nas fases seguintes, sem tabelas vazias especulativas nesta entrega.

## Rotas e endpoints

| Endpoint | Método | Acesso / função |
| --- | --- | --- |
| `assist_admin.php` | GET | `assist.edit`: artigos, pesquisa, editor e versões; áreas administrativas exigem também `assist.admin` |
| `assist_admin.php` | POST + CSRF | `save`, `submit`, `approve`, `reject`, `publish`, `archive`, `category`, `permission`, `configure`, `prune`, `resolve`, `convert`; capacidade específica verificada no serviço |
| `assist_api.php` | GET | `assist.use`: histórico privado e sugestões; `language=pt/en/hi` |
| `assist_api.php` | POST + CSRF | `ask` (pergunta, idioma, conversa, contexto), `feedback` (mensagem, 0/1), `report` (descrição, prioridade, contexto) |
| `assist_article.php?id=ID&version=N` | GET | `assist.use`: apenas versão atual publicada/autorizada; `embedded=1` devolve o cartão para o painel |

Todas as entradas exigem sessão válida e conta ativa, além de ativação do módulo. Há respostas 401/403/404, 409 para conflito de versão, 419 para CSRF, 422 para dados inválidos, 429 para limite, e 500 sem detalhe interno. Não usar GET para mutações. São registadas no mapa de rotas `assist.admin`, `assist.api`, `assist.article`. A API limita perguntas a 20/minuto/utilizador e reportes a 10/minuto/utilizador; há limites de tamanho de pergunta, conteúdo e reporte.

## Permissões e configuração

1. Instalar os ficheiros com backup normal da aplicação/base e permissões de escrita SQLite já exigidas pelo ERP. Não há mudança de credenciais ou configuração Composer.
2. Entrar como administrador e abrir `assist_admin.php` ou **ícone do SACO → Base de conhecimento**. A migração aditiva é aplicada automaticamente na primeira abertura do rodapé autenticado ou de um endpoint Assist.
3. A predefinição é `enabled=0` e lista piloto vazia. Administradores existentes têm acesso; os restantes utilizadores não veem o botão.
4. Em Configurações, indicar IDs piloto separados por vírgulas. Não basta inserir o ID: atribuir também capacidades pela área de permissões do módulo, que escreve nas tabelas **existentes do ERP**.
5. Operador: `assist.use`. Editor: `assist.use` e `assist.edit`. Validador: anteriores e `assist.validate` (todas as categorias autorizadas) ou `assist.validate.category.ID`. Publicação: `assist.edit` e `assist.publish`. Administração: `assist.edit` e `assist.admin`, mais outras capacidades se a conta não for um administrador global.
6. Categorias restritas exigem adicionalmente `assist.restricted`, incluindo nos descendentes e no histórico. As capacidades específicas por categoria são registadas na criação da categoria e na migração inicial.
7. Definir retenção entre 1 e 3650 dias (predefinição 180). O botão **Aplicar retenção** executa a limpeza de mensagens expiradas; não existe limpeza agendada nesta fase.
8. Após validação por utilizadores piloto, marcar **Ativar para todos os utilizadores com permissão**. Para recuar, desmarcar e limpar a lista piloto. As capacidades continuam a ser exigidas.

O utilizador escolhido como responsável pela validação deve possuir as capacidades de validação e edição necessárias. Se estiver indicado, apenas esse validador (ou administrador global) pode aprovar/rejeitar. Não se exige pessoa distinta do autor nesta primeira versão; quem não pode validar nunca se autoaprova. Uma política de separação obrigatória entre autor e aprovador pode ser acrescentada posteriormente.

### Criar e aprovar conhecimento

1. Criar artigo com título, categoria, idioma e procedimento. Colocar um passo numerado por linha. Acrescentar condições, avisos e sinónimos apenas com conteúdo interno conhecido.
2. Guardar rascunho. Consultar o histórico de versões quando necessário.
3. Submeter para validação. O validador consulta o artigo, verifica o conteúdo e aprova explicitamente ou devolve para correção com motivo.
4. Um utilizador com publicação publica a versão aprovada, desde que a revisão não esteja expirada.
5. Testar no chat com perguntas previstas, termos alternativos, material omitido, perguntas desconhecidas e pedidos de valores ausentes.
6. Qualquer alteração exige nova validação e publicação. Arquivar retira imediatamente o acesso através do Assist.

### Documentos e importação

Já existe importação de conhecimento estruturado por Excel/CSV, descrita abaixo. Ainda não há upload de documentos PDF/DOCX/HTML ou extração documental. Para usar conhecimento existente, um editor deve transcrever o excerto interno para um rascunho, identificar a referência documental na descrição e seguir a validação. Nunca tratar texto extraído ou copiado como automaticamente aprovado.

Na fase 3, reutilizar `UploadService` após confirmar a política de MIME/limites aplicável, guardar originais fora de acesso público, servir downloads por endpoint autorizado, e acrescentar documentos/secções com ligação de versão à aprovação. PDF/DOCX sem extrator disponível devem produzir aviso, sem obrigatoriedade de OCR. Esta documentação não apresenta esses fluxos como já disponíveis.

### Mascote SACO

O botão fechado mostra exclusivamente a imagem `assets/assist-saco.png`, sem texto visível ou fundo, mantendo um nome acessível para leitores de ecrã. A imagem tem 72 px no botão e 52 px no cabeçalho. Deve conservar-se o PNG original já instalado nesse caminho; o pacote não o substitui nem inclui uma reprodução. O painel abre diretamente em português, sem escolha de língua. O cabeçalho apresenta **SACO · Sistema de Apoio a Conhecimento Operacional**.

### Ativação no Shopfloor

Conceder primeiro acesso a um operador piloto num dispositivo de teste. Verificar entrada por PIN, abertura/fecho do painel, teclado virtual, legibilidade, fonte embebida, feedback e reporte. Iniciar/terminar operações pelo fluxo normal do Shopfloor e confirmar que abrir o Assist não submete formulários nem navega para outra página. O painel só envia contexto de máquina e OF quando os identificadores já estão efetivamente disponíveis na página; os restantes campos são referências declaradas. Não inicia, termina, modifica ou configura operações/máquinas. Depois do teste no tablet Android físico, alargar gradualmente a lista piloto antes da ativação geral.

## Testes

Runtime obrigatório:

```bash
source /workspace/gestisser-env/activate.sh
php -v
python3 scripts/test_php70.py assist_service_test.php shopfloor_page_sections_test.php shopfloor_operation_flow_test.php shopfloor_feedback_preview_test.php sharepoint_document_sync_test.php erp_migration_fast_path_test.php
python3 tests/assist_http.py
```

O primeiro comando recusa versões diferentes de PHP 7.0. O teste HTTP cria uma cópia temporária do código e uma instalação sintética, usa o instalador e o login existentes e elimina os dados no final. Necessita acesso a sockets locais, Python 3, Node, Playwright e Chromium; estas são ferramentas **de teste**, não dependências de produção. `CHROMIUM_PATH` permite selecionar o Chromium. Usa a porta local 8093. As imagens e diagnósticos ficam em `work/assist-http` (ignorado pelo Git).

O teste de serviço usa SQLite em memória, cobre publicação, rejeição, aprovação invalidada, expiração, arquivo, restrições herdadas, revogação no histórico, sinónimos, conflito de versões, ambiguidades e materiais, ausência de valores, privacidade, feedback, reportes, conversão em rascunho, retenção, limites, inglês/hindi, auditoria e alternativa sem FTS. Testa também que instruções em documentos não mudam estados do sistema. O teste HTTP/browser verifica XSS como texto inerte, CSRF, sessão/conta ativa, acesso por PIN, fontes/versionamento e chat/reportes em desktop e viewport Android com toque.

Os resultados concretos da execução acompanham esta entrega em `docs/gestisser-assist-validation.md`. Os testes de importação Excel/CSV estão disponíveis em `assist_spreadsheet_test.php` e no teste HTTP. Testes de extração PDF/DOCX e falha de sincronização **Assist** não são apresentados como aprovados: essas funcionalidades continuam pendentes. O teste de regressão de SharePoint exercita exclusivamente a integração já existente. Não foi realizada validação em tablet físico nem instalação em produção/cPanel.

## Limitações e próximas fases

- Fase 3: upload PDF/DOCX/TXT/HTML, imagens/fotografias, originais protegidos, extração e secções validadas individualmente; documentos longos e download autorizado; administração de documentos e indicadores completos.
- Fase 4: selecionar pastas/bibliotecas SharePoint autorizadas, importação manual/Forçar Sync, referência/versão da origem, suspensão após mudança externa, erros e estado de sincronização. Nenhum conteúdo SharePoint é publicado pelo Assist nesta entrega.
- Associações atuais são campos simples de equipamento/material/operação; não há múltiplas associações relacionais a máquinas, artigos e OF, nem deteção automática de contexto não fornecido.
- As soluções reportadas não são instruções aprovadas: converter não publica. Fotografias e integração com ocorrências/manutenção existentes exigem a análise da fase 3; o reporte inicial é uma fila mínima específica do conhecimento, não um módulo de manutenção.
- Busca conservadora, sem vetores ou IA: a cobertura de termos não prova relevância, e procedimentos dependem das condições/material/versão que o operador deve confirmar. Perguntas numéricas recusadas, sem tradução automática ou seguimento semântico de esclarecimentos.
- A busca de candidatos combina FTS opcional com `LIKE`; a verificação de autorização e aprovação é feita sobre cada candidato. Para bases muito grandes será necessário medir índices/ranking e paginação; nesta versão listas administrativas/históricos têm limites ou são consultas simples.
- A retenção é manual e aplica-se às mensagens, não a reportes nem à auditoria. Não há job de limpeza ou infraestrutura de serviços permanentes adicional.
- Compatibilidade gráfica foi validada em Chromium desktop e Android simulado. Browsers legados sem Fetch, FormData ou CSS flex necessitam validação/adaptação. O CSS tem largura responsiva e alternativa de altura para o painel.
- A API futura de reformulação pode ser introduzida atrás de uma interface de recuperação, mas nenhuma integração generativa foi adicionada. Continuam proibidos envio externo sem autorização e factos não suportados. O fornecedor de respostas atual é exclusivamente `AssistService`.

## Ficheiros

Novos: `assist_migrations.php`, `app/Services/AssistService.php`, `includes/assist_bootstrap.php`, `assist_admin.php`, `assist_api.php`, `assist_article.php`, `partials/assist_panel.php`, `assets/assist.css`, `assets/assist.js`, `tests/assist_service_test.php`, `tests/assist_http.py`, `tests/assist_browser.js`, esta documentação e o relatório de validação.

Alterados: `partials/footer.php` (inclusão do painel no shell autenticado) e `app/routes.php` (três nomes de rotas). Nenhum outro módulo funcional foi alterado.

## Importar conhecimento por Excel ou CSV

Em **Base de conhecimento → Importar Excel**, descarregar o template. O endpoint `assist_template.php` exige sessão ativa, módulo ativado e `assist.edit`; o modelo contém folhas **Conhecimento**, **Instrucoes** e **Categorias**, estas limitadas às categorias autorizadas do editor. Não contém exemplos técnicos ou artigos fictícios. A versão estática em `dist/modelo_conhecimento_SACO.xlsx` lista apenas as categorias iniciais; usar o modelo descarregado da aplicação quando existirem categorias personalizadas.

Preencher uma linha por artigo. São obrigatórios `titulo`, `categoria` e `procedimento`. As restantes colunas são `idioma` (pt por omissão; en/hi opcionais), `descricao`, `sintomas`, `causas`, `condicoes`, `seguranca`, `materiais_ferramentas`, `resultado_esperado`, `verificacao`, `palavras_chave`, `sinonimos`, `equipamento`, `material`, `operacao`, `validador_id` e `revisao_ate`. A categoria é o nome existente, não é criada automaticamente. O validador é um ID existente no ERP; a revisão aceita AAAA-MM-DD ou serial Excel, respeitando o sistema de datas do workbook.

Carregar `.xlsx` ou CSV UTF-8 e clicar **Pré-visualizar importação**. Conferir os campos no ecrã e clicar **Confirmar importação como rascunhos**. A pré-visualização é guardada na sessão do utilizador durante 30 minutos, protegida por token próprio além de CSRF; o navegador não fornece os registos na confirmação. Confirmações repetidas não criam cópias. As permissões de categoria são verificadas novamente ao confirmar.

Até 200 artigos e 5 MB por ficheiro; o conjunto de artigos descodificados não pode ultrapassar 2 MB. Os campos técnicos têm o limite habitual de 30 000 bytes. O modelo aceita texto e valores, sem fórmulas, macros ou ligações externas. O preflight valida limites do ZIP/XML, entidades e referências de células antes do leitor partilhado. O original permanece apenas no temporário do upload PHP, sem publicação ou armazenamento acessível pela Web. Excel requer ZIP e SimpleXML; CSV UTF-8 é uma alternativa de importação.

A gravação é aditiva e transacional: todos os artigos são novos rascunhos com versão 1 e autor autenticado. Nunca importa estados, IDs de artigo ou aprovações. Um erro em qualquer registo anula o lote, incluindo versões e auditoria intermédia. Um título já existente na mesma categoria/idioma ou repetido no lote cancela a operação; não se substitui conhecimento existente. Editar artigos existentes pelo editor normal. Depois da importação, submeter os rascunhos à validação e publicar explicitamente. Até lá, não aparecem nas respostas do SACO.

O processamento reutiliza `ArticleSpreadsheet` e a geração reutiliza `SimpleXlsx`, sem alterar esses serviços nem adicionar dependências Composer. O novo serviço é `app/Services/AssistKnowledgeSpreadsheet.php`; as ações administrativas são `preview_import`, `confirm_import` e `cancel_import`, com a capacidade `assist.edit`. Não há nova migração de base de dados.
