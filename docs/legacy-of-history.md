# GesTISSER · Fase 4 · Histórico de OFs Bobinas

Implementação da camada histórica independente de OFs Bobinas, destinada exclusivamente a `gestisser-dev`. O código não instala tabelas no arranque e não importa dados automaticamente. A preparação do esquema e cada importação exigem ações administrativas explícitas, backup verificado e transação.

## Publicação no cPanel e primeiro acesso

A raiz de desenvolvimento indicada é `/home/tisser/public_html/gestisser-dev/`, com a SQLite existente em `database.sqlite`.

1. No GitHub, abrir a branch do PR e escolher **Code → Download ZIP**. Descomprimir localmente.
2. Conservar uma cópia dos ficheiros existentes que vão ser substituídos. Carregar os ficheiros da lista no fim deste guia para a raiz de `gestisser-dev`, preservando as subpastas. Para funcionar no servidor, são necessários os ficheiros PHP/SQL de raiz, `historical-import` e `partials`, além das duas fichas modificadas. Os testes e a documentação são opcionais no hosting.
3. Manter a SQLite existente, a configuração da instalação e os diretórios de dados do servidor. O PR não contém SQLite, Excel, ficheiros de uploads ou dados de clientes. Não substituir a instalação inteira pelo ZIP.
4. Configurar o processo PHP com `APP_ENV=gestisser-dev`. Se o servidor não definir `APP_ENV` e já existir `storage/installation.json`, o campo `environment` desse ficheiro também é suportado pelo bootstrap: alterar apenas esse valor para `gestisser-dev`, preservando os restantes campos. A variável `APP_ENV`, quando definida no servidor, tem prioridade. Manter integrações e cron desativados no desenvolvimento.
5. Entrar novamente como administrador e abrir `https://tisser.pt/gestisser-dev/legacy_of_import.php`. O endpoint recusa ambientes diferentes de `gestisser-dev`. Um erro 404 indica que os ficheiros ainda não foram publicados no caminho servido.
6. Começar por **Validar / Dry-run** de um lote pequeno. Não premir a preparação/importação enquanto não tiver revisto o relatório. Não carregar o lote completo de OFs nesta primeira validação.

## Auditoria técnica e limites

Foram analisados o código, os contratos dos Excel e uma cópia SQLite fornecida, exclusivamente em leitura. Os anexos reais têm cabeçalhos compatíveis com os contratos existentes, mas todas as linhas chegam com `importar=0` e avisos de revisão em `erro_validacao`. O dry-run normal ignora essas linhas; só devem ser selecionadas após revisão num lote controlado. Faltam unidades e proveniência monetária para custeio. O relatório individual de referências e os dados dos anexos são mantidos fora deste repositório.

A implantação e o esquema da base ativa devem ser verificados no servidor antes da escrita. Não foi feito deploy no cPanel nem aplicada uma migração à base ativa por este PR.

Estruturas confirmadas no código e no artefacto SQLite:

| Estrutura | Relação relevante |
| --- | --- |
| `erp_customers` | `id`, `code`, `name`; catálogo atual de clientes |
| `erp_finished_products` | `id`, `code`, `description`, `customer_id`, `unit_id`; catálogo atual de artigos |
| `erp_products` | Catálogo legado exigido por OFs operacionais; o novo histórico não depende dele |
| `erp_units` | `id`, `code`; unidades atuais, não usadas para preencher unidades históricas ausentes |
| `erp_production_orders` | `customer_id`, `product_id`, `finished_product_id`, `order_number`, quantidades, datas, prioridades |
| `erp_production_order_operations` | Operações da produção atual; não recebe operações do novo histórico |
| `erp_legacy_import_map`, `erp_legacy_import_runs`, `erp_legacy_import_logs` | Estrutura definida no importador antigo; existência na base efetiva deve ser verificada |
| `erp_sales_orders` e linhas | Módulo comercial atual definido nos serviços; presente na cópia SQLite fornecida; não é necessário ao histórico |

Reutilização: autenticação/sessão e permissões `gt_erp_user_can`, token CSRF existente, shell HTML, leitor XLSX `HistoricalSpreadsheet`, contratos dos dois templates, `HistoricalValidator::number/date/schemaHash`, criação de Excel e área privada `HistoricalWorkspace`. As fichas de cliente/artigo já evitam as migrações automáticas do `config.php`; as novas páginas seguem a mesma abordagem.

Risco encontrado: o importador anterior escrevia OFs/operações em `erp_production_orders` e `erp_production_order_operations`, exigia estados finais e um artigo em `erp_products`. Esses dois adaptadores foram removidos e bloqueados, mesmo com aprovação na configuração antiga. Os restantes adaptadores antigos não foram reconstruídos e não fazem parte do novo fluxo. Se houver IDs Bobinas já mapeados em tabelas operacionais pelo importador anterior, a nova validação bloqueia esses IDs para reconciliação manual; não move nem modifica esses registos.

## Tabelas e alterações SQL

O SQL integral e aditivo está em `historical-import/legacy-of-schema.sql`:

- `erp_legacy_production_orders`: valores originais, referências atuais opcionais, estado de resolução, quantidades/unidade, datas, hash/JSON da origem, lote e data de importação. Chave única `(source_system, legacy_id)`; o número visível da OF pode repetir.
- `erp_legacy_of_cost_summary`: tempos e custos fornecidos por OF; ausências em `NULL`. Valores reais e estimados classificados separadamente, com moeda, origem e método. Não calcula custos a partir de tarifas atuais nem soma automaticamente operações que podem sobrepor-se.
- `erp_legacy_of_cost_details`: operações independentes do catálogo/routing atual, identidade histórica única, tempos previstos/registados, quantidades, custos quando comprovados e origem imutável.
- `erp_legacy_of_batches`: administrador, hash do lote, backup e checksum, relatório integral e resultado, incluindo falhas após rollback.
- `erp_legacy_of_audit`: preparação do esquema, importações e resoluções, utilizador, evidência, valores anteriores/novos e data.

FKs apenas para clientes/artigos já existentes, utilizador e entidades históricas. Índices por cliente/data, artigo/data, data, número, resolução e OF pai/sequência. Não são criados triggers, operações atuais, movimentos, reservas, saldos ou tarefas. Triggers encontrados nas tabelas históricas bloqueiam a escrita até revisão técnica.

O esquema não é instalado no arranque, em consultas, uploads ou dry-runs. O botão administrativo de preparação exige `PREPARAR HISTORICO`, cria um backup verificado e executa DDL numa transação. O histórico não é incluído em Shopfloor, planeamento, necessidades de materiais ou prioridades: esses leitores continuam a usar as tabelas operacionais existentes.

## Contratos Excel

Foram verificados os **contratos no código**, não os cabeçalhos reais dos Excel ausentes. O download em `legacy_of_import.php?download=templates` gera os dois templates sem linhas de exemplo.

`03_ofs.xlsx` aceita as colunas existentes:

```text
legacy_id numero_of encomenda_legacy_id numero_encomenda cliente_codigo artigo_codigo quantidade_planeada quantidade_produzida data_criacao data_inicio data_fim estado observacoes gestisser_customer_id gestisser_product_id importar erro_validacao
```

`04_of_operacoes.xlsx` aceita:

```text
legacy_id of_legacy_id numero_of operacao sequencia quantidade horas_previstas horas_reais data_inicio data_fim estado importar erro_validacao
```

Todos os cabeçalhos do contrato devem existir. Células desconhecidas podem ficar vazias; a OF exige `legacy_id` e `numero_of`; a operação exige identidade, operação original e um pai não ambíguo. Datas/quantidades ausentes ficam desconhecidas. O estado original é preservado, mesmo que descreva um estado antigo aberto: não há reativação nem ação operacional.

Campos que os templates antigos não contêm e que podem ser acrescentados, se existirem na fonte:

| Ficheiro | Campos opcionais |
| --- | --- |
| OFs | `unidade`, `tempo_preparacao_horas`, `tempo_execucao_horas`, `tempo_total_horas`, `custo_mao_obra`, `custo_materiais`, `outros_custos`, `custo_total`, `custo_unitario`, `moeda`, `tipo_custo`, `origem_custo`, `metodo_calculo` |
| Operações | `operacao_codigo`, `operacao_nome`, `unidade`, `custo_registado`, `moeda`, `tipo_custo`, `origem_registo` |

Sem `unidade`, não se deduz unidade a partir do artigo atual. Sem custos monetários, moeda e proveniência suficientes, não são apresentados valores financeiros inventados. `horas_reais` e `horas_previstas` são tempos em horas, nunca custos. Campos monetários preenchidos exigem moeda ISO de três letras e `tipo_custo=real` ou `estimated`; a OF exige origem e método, a operação exige origem do registo. Custos incompletos bloqueiam o lote. O código não estima nem reconstrói valores em falta.

Segurança do leitor reutilizado: validação server-side de extensão/MIME, tamanho, formato ZIP/XML, limites descomprimidos/linhas/células, uma folha, rejeição de fórmulas, macros, objetos, ligações externas e entidades XML. Cabeçalhos incompatíveis impedem upload; para contratos de OFs reconhecíveis, a mensagem lista os cabeçalhos em falta. Limites: 20 MiB por Excel, 40 MiB por upload neste fluxo, 64 MiB descomprimidos, 100.000 linhas por ficheiro, sujeitos também aos limites PHP do hosting.

## Referências e importação

1. Upload e pré-visualização das primeiras dez linhas por ficheiro, sem escrever na base.
2. Dry-run com ligação `PRAGMA query_only=ON`; o relatório completo fica numa área privada por utilizador/sessão fora da raiz pública.
3. Correspondências automáticas exclusivamente por **código exato**, preservando zeros e maiúsculas/minúsculas. Não há matching por nomes, aproximações ou números atuais de encomendas.
4. IDs preenchidos no Excel devem existir e ser coerentes com o código. Um ID existente sem código comprovado continua pendente, até um administrador registar evidência documental. Um ID contraditório bloqueia a associação/lote.
5. Referências sem correspondência podem ser importadas com ID atual `NULL` e estado `pending`; códigos e valores originais são conservados. A resolução posterior altera apenas o vínculo atual e o respetivo estado, exige evidência e backup e guarda auditoria. Não cria clientes ou artigos.
6. `of_legacy_id` é prioritário para relacionar operações. Um número de OF só resolve o pai se houver exatamente uma identidade histórica comprovada; ID e número contraditórios ou pai inválido bloqueiam a operação.
7. Importação exige administrador ativo, `APP_ENV=gestisser-dev`, relatório atual sem erros, confirmação `IMPORTAR LOTE HISTORICO`, backup verificado e transação SQLite. Hash do lote, esquema e referências são revistos antes e depois de `BEGIN IMMEDIATE`. Qualquer falha dá rollback; o lote falhado é registado separadamente.
8. Chaves únicas e hash da origem impedem duplicação. Repetir o mesmo conteúdo ignora os registos já importados. Conteúdo diferente com a mesma identidade gera erro; não há substituição automática.

O backup dedicado usa `VACUUM INTO`, verifica integridade e FKs e guarda SHA-256. Não invoca `BackupManager`, cujo arranque escreve `app_settings`. Requer SQLite 3.27 ou superior; em versões anteriores bloqueia a escrita, até ser revisto um adaptador seguro. Os backups são privados em `storage/backups/legacy-of`, com proteção Apache e permissões 0700/0600. Validar regras equivalentes em Nginx e o acesso protegido a `storage` antes de disponibilizar o módulo.

A recuperação posterior faz-se por backup verificado em janela de manutenção. Restaurar toda a SQLite pode também repor alterações operacionais feitas depois do snapshot; não existe um botão de restauração automática. Eliminar o esquema histórico não é necessário para desligar a interface: os novos pontos de entrada só funcionam em `gestisser-dev` e as inclusões nas fichas são condicionadas a esse ambiente.

## Consultas e preparação BI

- `legacy_of_history.php`: pesquisa paginada unificada, filtros por número, ano, origem, cliente e artigo. Cada resultado tem origem e identidade próprias; IDs de tabelas diferentes nunca são tratados como a mesma OF.
- `legacy_of_history.php?id=<id>`: ficha exclusivamente histórica, operações, indicadores fornecidos, custos apenas com `erp.costs_view` e rastreabilidade sem associações presumidas.
- `legacy_of_history.php?origin=Bobinas&pending=1`: referências pendentes. Apenas administradores podem resolver por POST com CSRF e evidência.
- Fichas existentes de cliente/artigo: componente reutilizável nos separadores de OFs/histórico, custos e rastreabilidade. Usa FKs atuais comprovadas e encaminha cada OF Bobinas para a sua ficha histórica. A consulta unificada está acessível no próprio componente.
- `LegacyOfHistory::indicators($articleId)`: base de agregação por ano, unidade, moeda e tipo de custo, com contagem de OFs/clientes, quantidades, tempos de execução fornecidos e custos registados. OFs sem unidade ficam separadas por identidade. É preparação de serviço para BI; não foi reconstruído o dashboard nem criado um indicador de produtividade com dados insuficientes.

Os números-resumo operacionais existentes mantêm o seu significado. O componente histórico apresenta a contagem das produções e respetivas datas/quantidades/clientes; tempos e custos estão na ficha histórica. Não combina totais históricos com unidades desconhecidas ou moedas/tipos incompatíveis.

## Primeiro dry-run controlado

1. Identificar a SQLite **de desenvolvimento** e configurar o processo com `APP_ENV=gestisser-dev` e `GESTISSER_DB_PATH=<caminho confirmado da base dev>`. Não apontar para uma base de produção. Manter integrações externas/cron desativados conforme configuração da instalação.
2. Abrir `legacy_of_import.php` com a sessão de um administrador. Não há URL pública/deploy criado nesta tarefa.
3. Carregar cópias de `03_ofs.xlsx` e `04_of_operacoes.xlsx` com apenas 1–5 OFs e operações relacionadas; `importar=1` apenas nas linhas do lote controlado.
4. Rever a pré-visualização e premir **Validar / Dry-run**. A preparação de tabelas não é necessária para este primeiro dry-run.
5. Descarregar o JSON completo, rever erros, pendências, unidades e proveniência dos custos. Resolver apenas vínculos comprovados ou manter pendências. Reenviar Excel corrigidos e repetir a validação.
6. Só quando pretender testar a escrita do pequeno lote: **Preparar estrutura com backup**, escrever `PREPARAR HISTORICO`, repetir o dry-run e confirmar `IMPORTAR LOTE HISTORICO`.
7. Consultar OF histórica, fichas de cliente/artigo, auditoria e backup; comparar contagens/dados operacionais antes/depois. Não carregar ainda o lote real de 6.656 OFs.

A análise dos anexos identificou referências pendentes e avisos de origem. Os valores individuais estão no relatório privado; o primeiro dry-run no servidor apresentará as pendências do lote escolhido. Os ficheiros originais não foram corrigidos automaticamente nem importados.

## Testes e resultados

Runtime real: PHP 7.0.33 e SQLite 3.46.1. Sintaxe de todos os PHP alterados/novos validada; `git diff --check` sem erros.

- `tests/legacy_of_history_test.php`: importação válida, cliente/artigo desconhecidos, IDs contraditórios/inexistentes, evidência administrativa, datas inválidas, pais contraditórios/ambíguos, duplicados/conflitos, operação sem catálogo atual, consultas por cliente/artigo/número/ano/origem, resolução auditada, custos ausentes, bloqueios de ambiente/permissões/confirmação/triggers, backups, rollback real por violação de constraint, snapshots operacionais idênticos e zero escritas em consultas/dry-run. Volume de 6.656 **OFs sintéticas**, validação ~0,12 s e consulta paginada ~0,01 s neste executor; índice cliente/data confirmado com `EXPLAIN QUERY PLAN`. Tempos não são garantia para outro hosting/dados.
- `tests/legacy_of_history_http.py`: cópia descartável do artefacto SQLite, upload multipart, pré-visualização escapada, dry-run/relatório sem escrita, preparação com backup, confirmação do pequeno lote sintético, ficheiro histórico, permissões/CSRF, custos protegidos, integração nas duas fichas e preservação das tabelas operacionais/base fornecida.
- `tests/historical_import_test.php`: regressão do leitor XLSX e dos adaptadores antigos não relacionados; agora verifica que o destino operacional das OFs está bloqueado. Passou.
- 17 testes existentes selecionados de fichas, histórico, vendas, OFs, Shopfloor, routing e stock: **12 passaram e 5 falharam já no HEAD original**, confirmado numa cópia separada anterior às alterações. `production_order_version_selection_test.php` espera um seletor ausente no formulário original. `shopfloor_operation_flow_test.php`, `routing_pending_order_sync_test.php`, `stock_transfer_service_test.php` e `stock_movement_import_test.php` usam retorno `void`, interpretado como classe em PHP 7.0. Não foram corrigidas funcionalidades/testes operacionais fora do âmbito.

Comandos de reprodução:

```sh
/workspace/gestisser-env/bin/php tests/legacy_of_history_test.php
/workspace/gestisser-env/bin/php tests/historical_import_test.php
python3 tests/legacy_of_history_http.py /caminho/copia-de-teste.sqlite --php /workspace/gestisser-env/bin/php
```

O teste HTTP exige sockets locais e prepara apenas uma cópia descartável; não é um comando de instalação nem de importação da base fornecida.

## Ficheiros da entrega

Novos: `historical-import/LegacyOfHistory.php`, `historical-import/legacy-of-bootstrap.php`, `historical-import/legacy-of-schema.sql`, `legacy_of_import.php`, `legacy_of_history.php`, `partials/legacy-of-list.php`, `tests/legacy_of_history_test.php`, `tests/legacy_of_history_http.py`, `docs/legacy-of-history.md`.

Modificados: `erp_customer.php`, `erp_article.php` (inclusão de consulta, sem alterar writers operacionais), `historical-import/Importer.php` (bloqueio/remoção dos dois adaptadores operacionais), `historical-import/Spreadsheet.php` (colunas opcionais e diagnóstico de cabeçalhos), `historical_import.php` (encaminhamento visível), `historical-import/README.md` (atualização de âmbito), `tests/historical_import_test.php` (regressão do bloqueio), `.gitignore` (ficheiros de trabalho privados).
