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
