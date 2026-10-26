# Diagnóstico de HTTP 403 — SharePoint

O diagnóstico administrativo passa a obter um token novo em `POST /{tenantId}/oauth2/v2.0/token` com `client_credentials` e scope `https://graph.microsoft.com/.default`. Inspeciona aud, tid, appid/azp, roles Sites.Selected, exp/nbf e ausência de contexto delegado (scp/idtyp). A inspeção das claims não valida criptograficamente a assinatura; o token vem do endpoint Entra por HTTPS e é validado pelo Graph nos pedidos.

Se o token tiver as características esperadas, executa independentemente os três GET com os IDs configurados:
- `/sites/{siteId}`
- `/sites/{siteId}/lists/{listId}`
- `/sites/{siteId}/drives/{driveId}`

Uma falha num GET não impede os outros. Falha na autenticação ou nas claims marca os GET como não executados. Não há descoberta por URL nem enumeração de listas/colunas.

Cada resultado contém endpoint lógico, HTTP, código Graph (ou OAuth2 para autenticação), request-id quando disponível e mensagem diferenciada para 401, 403, 404 e falhas de ligação. Não são guardados ou apresentados tokens, segredo, cabeçalhos Authorization, claims completas, corpos remotos ou mensagens de exceções de transporte. O token é limpo no fim. Os resultados usam a persistência administrativa existente.

O ficheiro privado continua em `/home/tisser/gestisser-config/sharepoint-client-secret`, fora de public_html. A variável de ambiente GESTISSER_SHAREPOINT_CLIENT_SECRET, quando não vazia, mantém prioridade. Não é necessário mover ou expor o segredo.

## Publicação no cPanel

Guardar uma cópia privada dos dois ficheiros de execução e publicar em conjunto `app/Services/SharePointIntegration.php` e `sharepoint_settings.php`, preservando os caminhos. Não substituir a configuração, SQLite, uploads ou gestisser-config. Não há migração, cron, alteração de permissões Microsoft ou ativação de sincronização. Após publicar, entrar como administrador em `integrations.php?action=sharepoint` e clicar TESTAR SHAREPOINT. Reverter repondo os dois ficheiros anteriores em conjunto.

## Validação e limites

Os testes usam transporte simulado, sem credenciais Microsoft. Os testes sharepoint_integration_test.php e sharepoint_diagnostics_test.php passaram em PHP 7.0.33 real; os dois ficheiros de execução passaram php -l. Cobrem configuração/persistência, identidade da aplicação, claims, erros individuais, códigos, request-id, falhas OAuth e rede, ausência de credenciais nos resultados e limpeza do token.

O Git local não conseguiu atualizar através do proxy. Os ficheiros alterados foram obtidos de main pelo conector GitHub; a cópia local restante é antiga. O runner oficial nessa cópia encontrou três erros de sintaxe preexistentes e fora do âmbito: app/Services/ValidatedHourBankCalculator.php, tests/production_dossier_close_report_test.php e tests/raw_material_spreadsheet_test.php. A validação global não passou; repetir o runner na main atual antes da publicação em produção.

Não foram realizados testes reais no tenant nem publicação cPanel. Um 403 isolado não identifica a concessão em falta, e 404 também pode ocultar falta de acesso. A causa concreta depende dos novos resultados.
