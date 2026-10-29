# GesTISSER Assist — entrega e validação

Implementadas as fases 1 e 2 no repositório `GesTisser`: base de conhecimento com categorias, versões, aprovação humana e publicação; chat lateral partilhado pelo ERP e Shopfloor; pesquisa determinística com FTS5 opcional e alternativa SQLite; fontes com versão, histórico privado, sugestões e feedback. Inclui também reporte textual de problemas e conversão de soluções eficazes em rascunhos.

O módulo reutiliza sessões, utilizadores, capacidades e auditoria existentes. Não consulta Internet ou serviços de IA para responder. As instruções devolvidas são excertos exatos de uma única fonte publicada/aprovada, com abstenção e esclarecimento nos casos insuficientes ou ambíguos. A ativação inicial é apenas para administradores; utilizadores piloto exigem configuração e permissões explícitas.

## Resultados — 28 de outubro de 2026

- Runtime confirmado: **PHP 7.0.33**.
- Sintaxe: **352 ficheiros PHP aprovados; zero falhas**.
- `assist_service_test.php`: **72 verificações aprovadas**.
- Cinco testes de regressão aprovados: `shopfloor_page_sections_test.php`, `shopfloor_operation_flow_test.php`, `shopfloor_feedback_preview_test.php`, `sharepoint_document_sync_test.php`, `erp_migration_fast_path_test.php`.
- **24 verificações HTTP aprovadas** numa instalação temporária, incluindo o instalador/login existentes, ciclo de aprovação/publicação real, sessão, CSRF, conta inativa, fontes retiradas e versões antigas.
- Navegador Chromium: **desktop e tablet Android simulado aprovados**, com chat, fontes no painel, feedback, reporte, abstenção, dimensões e alvos de toque. Texto com script na fonte permaneceu inerte.
- Os ecrãs ERP, RH e Shopfloor abriram com sucesso na instalação sintética; o teste usou também a seleção normal de centro de trabalho e acesso de operador com PIN.
- JavaScript verificado com `node --check`; `git diff --check` sem problemas.

Não foi executada toda a suíte de testes do repositório. Não foi testado um tablet físico nem o alojamento cPanel. Não houve deploy, alteração de dados de produção ou ativação geral. A publicação posterior numa branch GitHub foi autorizada pelo utilizador.

## Como rever e instalar

As alterações encontram-se no repositório de trabalho, incluindo os novos ficheiros ainda não rastreados. Rever o diff e esses ficheiros antes de integrar. O pacote de instalação encontra-se em `dist/Gestisser-Assist-SERVIDOR.zip`; as instruções estão em `ENTREGA-ASSIST.md`. A documentação completa está em `docs/gestisser-assist.md` e inclui arquitetura, esquema das dez tabelas e índice FTS opcional, rotas, capacidades, configuração, fluxo de aprovação, retenção, testes, limitações e ativação gradual no Shopfloor.

Após instalar no ambiente de teste, entrar como administrador e abrir `assist_admin.php`. A migração aditiva é automática, através do PDO existente. Configurar pilotos e capacidades antes de ativar utilizadores comuns. Não criar dados técnicos fictícios; os dados dos testes são apenas temporários.

## Limitações e trabalho pendente

- Fases 3/4: importação PDF/DOCX/TXT/HTML, anexos/fotografias, originais protegidos, secções e validação individual; integração documental SharePoint com seleção de pastas, Forçar Sync e invalidação por alterações de origem. Já existe importação estruturada Excel/CSV; upload de documentos PDF/DOCX e sync Assist continuam pendentes.
- A pesquisa é uma heurística conservadora; não faz compreensão semântica ou tradução. Pedidos de parâmetros/valores reconhecidos e perguntas com dígitos são recusados nesta versão. Os esclarecimentos exigem selecionar a fonte ou repetir a pergunta completa com o material.
- Conteúdo em português, inglês e hindi deve ser criado e aprovado no respetivo idioma. A administração e parte das mensagens operacionais continuam em português.
- Retenção do chat é configurável, mas a execução é manual. Os reportes e auditoria não são eliminados por essa política.
- **Mascote SACO:** o botão usa o nome e o painel está preparado para `assets/assist-saco.png`. O ambiente recebeu o TXT, mas não o ficheiro binário da imagem mostrada no chat. A imagem exata não está incluída; é necessário disponibilizar o PNG original nesse caminho. Não foi gerada uma aproximação.

## Ficheiros entregues

- `app/Services/AssistService.php`
- `app/routes.php`
- `assets/assist.css`
- `assets/assist.js`
- `assist_admin.php`
- `assist_api.php`
- `assist_article.php`
- `assist_migrations.php`
- `docs/gestisser-assist-validation.md`
- `docs/gestisser-assist.md`
- `includes/assist_bootstrap.php`
- `partials/assist_panel.php`
- `partials/footer.php`
- `tests/assist_browser.js`
- `tests/assist_http.py`
- `tests/assist_service_test.php`

## Atualização da interface — 29 de outubro de 2026

O botão fechado apresenta apenas `assets/assist-saco.png`, sem legenda visível ou fundo. O painel abre diretamente em português, sem seletor nem aviso de língua. Mantém-se o nome acessível do botão. CSS/JavaScript incluem versão de cache pelo ficheiro.

Revalidação em PHP 7.0.33: sintaxe dos 352 ficheiros aprovada, 72 verificações do serviço Assist aprovadas e 24 verificações HTTP aprovadas, incluindo o uso em computador e tablet Android simulado. `node --check assets/assist.js` e `git diff --check` aprovados. Não houve alteração do motor de respostas ou do esquema da base de dados.

Para instalações existentes, `dist/Gestisser-Assist-ATUALIZACAO-UI.zip` contém apenas `partials/assist_panel.php`, `assets/assist.css`, `assets/assist.js` e instruções. Conservar a imagem original já instalada em `assets/assist-saco.png`. O pacote completo também foi atualizado.

## Significado de SACO e importação Excel — 29 de outubro de 2026

O cabeçalho apresenta **SACO · Sistema de Apoio a Conhecimento Operacional**. O botão fechado mantém apenas a mascote.

Criado `assist_template.php` e `AssistKnowledgeSpreadsheet`, reutilizando os leitores/geradores existentes. O template descarregável tem folhas Conhecimento, Instrucoes e Categorias, sem dados técnicos fictícios. O download na aplicação inclui apenas categorias autorizadas; a versão estática no repositório contém as categorias iniciais.

A administração inclui upload `.xlsx`/CSV UTF-8, pré-visualização protegida por sessão, CSRF e nonce, e confirmação explícita para criar rascunhos. Limites: 200 artigos, 5 MB de ficheiro e 2 MB de artigos descodificados. Não aceita fórmulas, macros, entidades XML nem referências externas. Verifica limites de ZIP e células antes de chamar o leitor partilhado. Rejeita duplicados e efetua rollback integral em caso de erro, sem substituir conhecimento existente, aprovar ou publicar artigos.

Validação em PHP 7.0.33: **355 ficheiros PHP sem erros de sintaxe**, **72 verificações do serviço**, **37 verificações de importação**, dois testes de regressão (`raw_material_spreadsheet_test.php`, `user_spreadsheet_export_test.php`) e **36 verificações HTTP**, incluindo computador e tablet Android simulado. Testado download de template real, upload de workbook preenchido, pré-visualização sem gravação, HTML escapado, token inválido, confirmação, replay, CSRF e recusa de utilizadores sem permissão. As datas Excel 1900/1904 são respeitadas. Nenhum deploy foi executado.

Pacote completo atualizado; `dist/Gestisser-Assist-ATUALIZACAO-EXCEL.zip` contém os 10 ficheiros necessários à atualização e instruções, incluindo os dois serviços partilhados existentes como dependências. `dist/modelo_conhecimento_SACO.xlsx` é um template vazio com categorias iniciais. Não há migração adicional de esquema.

## Visibilidade em tablets e simplificação do painel

Retirado o link de administração do chat; Nova conversa foi colocado no rodapé junto de Reportar problema. O botão usa a mascote quando carregada e um SVG autónomo quando o PNG está em falta/falha. PNG com cache por data do ficheiro, posição fixa com safe-area, alvo de toque de 80 px e largura do painel compatível sem CSS min().

Revalidação PHP 7.0.33: 355 ficheiros sem erros de sintaxe e 72 verificações do serviço aprovadas. O teste HTTP passou 38 verificações; o navegador validou desktop, tablet retrato, tablet paisagem e ecrã estreito. Inclui hit-test do botão após scroll, toque, PNG carregado/falhado e fluxo completo de chat, fontes, feedback, reporte e nova conversa. Verificou-se também que a conta não piloto ou sem assist.use não recebe o botão. Node syntax e diff sem problemas.

O utilizador ainda não comparou a mesma conta entre computador e tablet. Não foi possível confirmar a causa específica no dispositivo/servidor real. A atualização corrige a situação reproduzida de imagem falhada e preserva as regras de acesso; as instruções do ZIP explicam como configurar a conta do tablet. Não houve deploy nem alteração automática de permissões.

## Entrada shopfloor@tisser.pt com PIN de cada colaborador

A análise do login confirmou que `user_id` é substituído pelo colaborador identificado pelo PIN. Logo, as autorizações da conta de entrada não eram aplicadas às consultas do colaborador. Foi implementada herança limitada de `assist.use` e ativação piloto da entrada canónica, conservando a identidade individual.

`login.php` regista o ID da entrada partilhada apenas após PIN válido e limpa-o em login por password ou outra entrada. O teclado direto prefere a conta canónica ativa mesmo sem PIN próprio e não é desviado por um email pessoal lembrado. O painel e a API obtêm o mesmo contexto através de `AssistService::fromSession`. A autorização exige entrada ativa e configurada, honra negações explícitas, revalida permissões e nunca delega capacidades de gestão ou categorias restritas.

A administração inclui **Ativar SACO para o Shopfloor por PIN**: ação explícita com CSRF e assist.admin, que autoriza consulta e põe apenas shopfloor@tisser.pt nos pilotos. Não ativa globalmente, não copia permissões para todos, não cria contas nem altera PINs. Após instalar, é necessário ativar uma vez e sair/voltar a entrar por PIN nos tablets. Não há migração adicional.

PHP 7.0.33: **356 ficheiros sem erros de sintaxe**, **72 verificações do serviço**, **37 de importação**, **29 de entrada partilhada** e regressão de seleção do centro de trabalho aprovados. **59 verificações HTTP** aprovadas, incluindo PIN inválido, ativação central, entrada real de dois colaboradores sem permissões individuais, propriedade do histórico, troca de utilizador, revogação, conta inativa, password sem herança, parâmetros forjados e cookie de email pessoal. Fluxos Chromium desktop, tablet retrato/paisagem e ecrã estreito passaram. Não foi testado no tablet físico nem houve deploy.

O novo pacote `dist/Gestisser-Assist-ATUALIZACAO-PIN.zip` contém 12 ficheiros atuais e instruções. Os pacotes de atualização anteriores foram atualizados com as mesmas dependências para evitar que um painel novo invoque uma classe antiga sem fromSession. O pacote completo tem 16 ficheiros; ZIPs verificados quanto a conteúdo, datas e permissões 0644. O PNG e a base de dados são preservados.
