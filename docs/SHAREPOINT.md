# SharePoint — auditoria e primeira etapa

## Estado da entrega

Implementadas a configuração não sensível, a página administrativa integrada, o diagnóstico local e o cliente Graph de leitura. **A integração completa ainda não está concluída.** Não estão implementados sincronização, worker, cron, fila, histórico de operações, escrita controlada, replicação documental ou edição do segredo na interface. Estes controlos não são apresentados como funcionais.

## Auditoria do código existente

- `helpers.php` carrega o bootstrap e disponibiliza `require_admin`, `h`, `csrf_input` e `validate_csrf_or_abort`. A página reutiliza estes componentes.
- `integrations.php` lista o conector integrado **Microsoft SharePoint — Artigos e Documentos**, com Configurar, Testar e Diagnóstico, ao lado das integrações existentes. A rota `integrations.php?action=sharepoint` abre o painel específico. Não existe entrada separada no menu; `sharepoint_settings.php` redireciona antigos favoritos GET para a rota central. A listagem usa a configuração existente em `app_settings`, sem criar uma segunda configuração ou ativar fluxos genéricos.
- `bootstrap/config.php` define `paths.uploads`, `external_services_enabled` e caminhos por ambiente. O diagnóstico externo respeita a desativação de serviços externos no ambiente de teste.
- `app_settings` já existe em `config.php`; foi reutilizada para configuração JSON e último diagnóstico por serviço. Não foi alterado o esquema SQLite e não houve necessidade de migração ou backup de esquema nesta etapa.
- Artigos: `erp_finished_products`, com `id`, `code`, `customer_product_code`, `customer_id`, `description`, `status`, `updated_at` (`erp_migrations.php`). O identificador local indicado para o futuro mapeamento é `id`; a correspondência remota está por validar.
- Clientes: `erp_customers`; o artigo associa-se por `customer_id`.
- Documentos: `erp_product_documents`, com `entity_type`, `entity_id`, `document_type`, `title`, `file_url`, `version`, `status`; artigos usam `entity_type=finished_product` (`erp.php`). Há também versões das fichas técnicas em `erp_article_technical_sheet_versions`.
- Uploads: `UploadService` e `ArticleDocument`, que atualmente impõe 20 MiB por documento. A configuração do diagnóstico não modifica esse limite nem migra ficheiros. A diversidade de `file_url` deverá ser resolvida com validação de caminhos antes de replicar.
- Existem crons e um módulo genérico de integrações. Não foi ligado nenhum worker novo aos fluxos operacionais.

## Configuração Microsoft Entra ID

1. No centro de administração Microsoft Entra, selecione o tenant da Tisser, **App registrations → New registration**. Crie uma aplicação de tenant único para o GesTISSER; não é necessário redirect URI no fluxo client credentials.
2. Copie **Directory (tenant) ID** e **Application (client) ID** para a página administrativa.
3. Em **Certificates & secrets → Client secrets → New client secret**, defina validade e um responsável pela renovação. Guarde o valor imediatamente num gestor de segredos. Nunca o coloque no repositório, no SQLite ou em mensagens/logs.
4. Em **API permissions → Add a permission → Microsoft Graph → Application permissions**, adicione **Sites.Selected** e obtenha **Grant admin consent** por um administrador autorizado.
5. Uma identidade administrativa separada, com permissão para gerir as concessões de sites, deve resolver o ID do site com `GET https://graph.microsoft.com/v1.0/sites/tisserpt.sharepoint.com:/`.
6. Com essa identidade administrativa, conceda à aplicação acesso ao site correto através de `POST https://graph.microsoft.com/v1.0/sites/{site-id}/permissions`, com corpo:

```json
{"roles":["write"],"grantedToIdentities":[{"application":{"id":"CLIENT_ID_DA_APLICACAO","displayName":"GesTISSER SharePoint"}}]}
```

A aplicação de sincronização não deve receber permissões globais apenas para se conceder acesso a si própria. O consentimento de Sites.Selected, sem esta concessão explícita, não permite aceder ao site.

7. Consulte `GET /v1.0/sites/{site-id}/lists`, confirme a lista cujo `webUrl` é `https://tisserpt.sharepoint.com/Lists/Artigos`, e copie o ID. **Não crie outra lista Artigos.**
8. Consulte `GET /v1.0/sites/{site-id}/drives`, identifique a biblioteca documental e copie o Drive ID. Se a biblioteca pretendida não existir, um administrador poderá criá-la manualmente no SharePoint como **Maquetes e Documentos de Artigos**. O código desta entrega não cria bibliotecas.
9. Configure `GESTISSER_SHAREPOINT_CLIENT_SECRET` no ambiente PHP do cPanel, fora do diretório público. O mecanismo exato depende do fornecedor: se PHP-FPM filtra variáveis, peça ao alojamento a configuração do pool. Não coloque o segredo num `.env` publicamente acessível nem o passe como argumento de linha de comandos. Valide a disponibilidade no processo web; a página mostra apenas se está configurado.
10. Guarde IDs e URL na página **Administração → Integrações → Microsoft SharePoint — Artigos e Documentos → Configurar**. Execute os testes no ambiente de teste autorizado. Serviços externos têm de estar habilitados na configuração desse ambiente.

O teste verifica token, URL e ID do site, enumeração paginada de listas e colunas (incluindo Title), Drive ID e acesso à raiz da biblioteca. Respostas HTTP com erro são resumidas por categoria sem corpo remoto. A escrita permanece explicitamente **não testada**; não se conclui que a sincronização está operacional apenas pela autenticação.

## Segurança e comportamento

- Apenas administradores; todos os formulários POST validam CSRF e toda a saída dinâmica é escapada.
- Segredo por variável de ambiente ou ficheiro privado lido explicitamente como dados pelo PHP, sem armazenamento ou devolução pela interface. A opção de o editar no ERP ainda está pendente.
- OAuth client credentials, Graph v1.0, HTTPS e TLS verificado; redirecionamentos HTTP desativados.
- URL do site restrito a domínio Microsoft SharePoint. Links de paginação restritos ao Graph v1.0. Limite de 100 páginas por diagnóstico, com deteção de ciclos.
- Diagnóstico cPanel usa acesso local, ficheiro aleatório criado em modo exclusivo, escrita, leitura, comparação e remoção em finally. Não usa FTP.
- Apenas último resultado persistido por serviço. Os testes executam num pedido HTTP síncrono; uma atualização apresenta o último resultado concluído. Não existe acompanhamento persistido de teste em curso nesta etapa.
- Não são guardados tokens ou corpos de erros Microsoft. Credenciais não devem ser introduzidas nos campos não sensíveis.

## Validação executada

Executado em PHP **7.0.33**, CLI, no ambiente disponibilizado:

```
php tests/sharepoint_integration_test.php
php -l sharepoint_settings.php
php -l app/Services/SharePointIntegration.php
php -l partials/header.php
php -l integrations.php
```

Resultado: teste aprovado e quatro verificações de sintaxe aprovadas. O teste usa SQLite em memória e diretório temporário real. Verifica configuração, rejeição de URL local, leitura/escrita/integridade e remoção local, persistência de falha, descoberta Graph paginada com transporte simulado, divergência de List ID, biblioteca ausente e ausência de falso sucesso de escrita. Não usa credenciais nem dados reais.

**Não executados:** autenticação Microsoft real, acesso ao cPanel real, consulta real ao tenant, testes de escrita, teste de cinco artigos, qualquer sincronização, upload remoto, versões, retentativas, recuperação, cron e integridade das cópias remotas. Sem credenciais Microsoft configuradas no ambiente. A interface foi validada por pedidos HTTP autenticados numa cópia isolada; não foi realizada uma inspeção visual no browser. O estado real do SharePoint e as colunas internas continuam por confirmar.

## Continuação necessária para aceitação completa

1. Confirmar os recursos reais e a disponibilidade do segredo no processo web; autorizar e implementar teste temporário de escrita com limpeza e diagnóstico de limpeza incompleta.
2. Validar nomes internos/tipos e um campo remoto estável, sem criar colunas automaticamente. Definir a correspondência de registos já existentes e tratar ambiguidades.
3. Antes de nova migração, criar backup consistente SQLite. Implementar mapeamentos, fila persistente, locks/leases, hashes, idempotência, análise, simulação e teste de cinco artigos.
4. Implementar worker CLI e cron com proteção contra concorrência, limites, Retry-After, retentativas, retoma e progresso persistido. **Não há cron SharePoint funcional nesta entrega; não configurar um comando fictício.** Quando o worker existir, o agendamento previsto é a cada cinco minutos com o binário PHP 7.0 do alojamento e caminhos absolutos, após validar o comando em teste.
5. Implementar replicação documental com controlo de caminhos, sessões de upload, recuperação, metadados, versões e verificação do conteúdo; ligar os eventos de alteração dos artigos e documentos e o estado à ficha do artigo.
6. Completar todos os testes de aceitação. Sincronização integral de produção exige autorização explícita posterior.


## Publicação e teste desta versão

1. Rever e integrar o PR em `main` pelo processo habitual. Publicar os ficheiros do PR em conjunto; a listagem passa a carregar o novo serviço PHP.
2. Esta alteração não altera o esquema SQLite nem cria uma lista SharePoint. Preservar a base de dados, uploads e configuração do alojamento durante a publicação.
3. Entrar como administrador e abrir **Administração → Integrações**. A linha **Microsoft SharePoint — Artigos e Documentos** está disponível imediatamente, inicialmente **Por configurar**, sem precisar de criar outra integração genérica em «Nova integração».
4. Em **Configurar**, guardar Tenant ID, Client ID, URL, IDs e o diretório de diagnóstico. Definir o segredo no processo PHP do alojamento conforme as instruções acima.
5. Executar **TESTAR CPANEL**, depois **TESTAR SHAREPOINT** ou usar **Testar** na listagem para ambos. A listagem mostra o último diagnóstico concluído. Alterar a configuração invalida os resultados antigos para não mostrar sucesso para recursos diferentes.
6. **Leitura validada** significa que os recursos foram consultados; escrita e sincronização continuam indisponíveis. Não é necessário nem existe um cron SharePoint nesta versão.
7. Em caso de reversão, repor o código anterior de `integrations.php` e `partials/header.php` em conjunto; as chaves `sharepoint.*` em `app_settings` podem permanecer sem afetar o ERP. Não restaurar a base de dados por cima de operações de produção.

### Validação adicional da navegação centralizada

Base de validação: `main` no commit `0d9bbb96dcaf8eff2233bb8cf6a9025fae6da22f`, com apenas os ficheiros deste PR sobrepostos numa cópia isolada.

- Runner oficial `python3 scripts/test_php70.py sharepoint_integration_test.php integration_safe_probe_test.php`, em PHP 7.0.33: **302 ficheiros PHP sem erros de sintaxe; 2 testes aprovados**.
- Teste HTTP numa instalação temporária com SQLite sintético: **20 verificações aprovadas**, incluindo autenticação, listagem com conector existente, editor genérico, configuração centralizada, segredo mascarado, rejeição CSRF, gravação, atualização de estado, diagnóstico local real, bloqueio dos serviços externos, redirecionamento do URL antigo, integridade SQLite e rejeição de não administradores nas três rotas.
- O teste Graph continua simulado; nenhuma credencial Microsoft nem dados de produção foram utilizados. Não houve publicação em LIVE ou merge automático.


## Correção do Client Secret — cPanel e publicação

O leitor `SharePointClientSecret` dá prioridade a `getenv('GESTISSER_SHAREPOINT_CLIENT_SECRET')` quando não vazio. Se ausente ou em branco, lê explicitamente `/home/tisser/gestisser-config/sharepoint-client-secret` através de `fopen`/`stream_get_contents`; nunca inclui nem executa o ficheiro. O conteúdo é apenas o Valor do segredo, admitindo uma quebra de linha final. Não é um ficheiro PHP, `.env` ou `NOME=valor`. Conteúdo vazio, multilinha, com NUL ou superior a 8192 bytes é recusado. Não se guarda o segredo em SQLite, HTML, respostas ou logs.

### Configuração concreta no cPanel

1. No Gestor de ficheiros, abrir `/home/tisser`, fora de `public_html`. A pasta `gestisser-config` já criada deve ter permissões **0700**, e `sharepoint-client-secret` **0600** (ou **0400** para leitura apenas).
2. A pasta e o ficheiro devem pertencer à conta que executa o PHP do domínio, normalmente `tisser`. O leitor recusa permissões para grupo/outros e ligações simbólicas. Se PHP correr noutra conta, pedir ao alojamento para ajustar o proprietário e o pool; não abrir as permissões para 0644/0755.
3. No Terminal do cPanel, sem mostrar o conteúdo:

```sh
chmod 700 /home/tisser/gestisser-config
chmod 600 /home/tisser/gestisser-config/sharepoint-client-secret
stat -c '%U %a %n' /home/tisser/gestisser-config /home/tisser/gestisser-config/sharepoint-client-secret
```

4. Se `open_basedir` estiver ativo para o domínio, pedir ao alojamento para acrescentar `/home/tisser/gestisser-config` à lista existente, preservando os restantes caminhos. Confirmar que a pasta privada não é Document Root de nenhum domínio/subdomínio e que não existem aliases ou symlinks públicos para ela.
5. A alternativa por variável exige que o alojamento a disponibilize ao processo PHP web do domínio, por exemplo na configuração privada do pool PHP-FPM. Um `export` no Terminal só afeta essa sessão e não configura o processo web. Não colocar o segredo em `.htaccess`, `.user.ini`, ficheiros públicos ou comandos no histórico. Uma variável antiga não vazia tem prioridade sobre o ficheiro: removê-la do pool se se pretender usar o ficheiro. Recarregar o pool após alterar o ambiente.
6. Entrar como administrador em `integrations.php?action=sharepoint#diagnostics` e confirmar **Client Secret disponível: Sim**. Esta indicação comprova apenas a leitura local, não a validade no Entra. Se aparecer Não, verificar permissões, proprietário, `open_basedir` e formato do ficheiro com o alojamento, sem usar `phpinfo()` nem mostrar o segredo.
7. Executar **TESTAR SHAREPOINT** para renovar o diagnóstico antigo. Este teste obtém um token e consulta recursos; não ativa sincronização nem escreve artigos/documentos no SharePoint.

### Publicar a correção

- Base: `main`, após PR #431 (`fab025740c6377491a9cc84e9e5fc6e3361ed30d`). Publicar em conjunto os dois ficheiros de execução `app/Services/SharePointIntegration.php` e `sharepoint_settings.php`, mantendo os caminhos relativos. Fazer primeiro uma cópia destes dois ficheiros em local privado para reversão. Os testes e esta documentação não precisam de ficar no diretório público.
- Não substituir a base SQLite, uploads, configuração do domínio ou a pasta privada. Não é necessária migração, instalação, criação de cron ou ativação de serviços/sincronizações.
- No cPanel, usar o processo Git Deploy existente apenas se estiver configurado para preservar esses dados; em alternativa, enviar os dois ficheiros pelo Gestor de ficheiros. Confirmar PHP 7.0 para o domínio no MultiPHP Manager e, se necessário, pedir recarga do OPcache/PHP-FPM.
- Confirmar disponibilidade e repetir apenas o teste de leitura. Para reverter, repor os dois ficheiros anteriores em conjunto; não restaurar a base por cima de operações posteriores.

### Validação desta correção

Executado `python3 scripts/test_php70.py sharepoint_secret_test.php sharepoint_integration_test.php integration_safe_probe_test.php` com PHP **7.0.33**: **315 ficheiros PHP sem erros de sintaxe e 3 testes aprovados**. O novo teste cobre prioridade do ambiente, alternativa quando vazio, permissões da pasta/ficheiro, ficheiro ausente, symlink, conteúdo inválido e leitura sem executar código ou produzir saída. O teste Graph usa transporte simulado. Não foram usados o segredo real, o servidor cPanel ou o tenant Microsoft; não houve sincronização.
