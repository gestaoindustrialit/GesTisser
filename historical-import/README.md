# Importação histórica Bobinas — primeira fase

O módulo está preparado para exportar templates, receber Excel normalizados, fazer matching e dry-run, apresentar problemas e guardar relatórios privados. Nenhuma importação de dados reais foi executada. A execução real está desativada por defeito.

**Limitação pendente (atualizada após os anexos):** a `database.sqlite` atual foi recebida e analisada em modo de leitura. A integridade é `ok`, sem violações de FK. A `Bobinas (1).mdb` foi anexada, mas excede o limite de transferência de 32 MiB; é necessário enviá-la comprimida num ZIP abaixo desse limite ou em partes menores. A base atual confirma a ausência de tabelas de encomendas de clientes/linhas e de um destino autónomo de entradas históricas. Não foi possível concluir esses adaptadores sem inventar destinos ou alterar o esquema além do pedido. Estas entidades podem ser exportadas, carregadas e validadas, mas a escrita está bloqueada. Não foram criadas tabelas concorrentes.

## Ficheiros e integração

Ficheiros novos:

- `historical_import.php`: entrada administrativa, sete etapas, autenticação existente, CSRF, resolução manual de IDs, downloads e histórico de execuções.
- `historical-import/contracts.php`: contrato dos oito Excel.
- `historical-import/Spreadsheet.php`: geração e leitura segura de XLSX, sem dependências novas.
- `historical-import/Compatibility.php`: inspeção dos destinos, colunas obrigatórias e triggers, apresentada no painel; bloqueia destinos incompatíveis.
- `historical-import/Validator.php`: matching, validação, dependências, duplicados, impressão digital do esquema.
- `historical-import/Importer.php`: instalação explícita das tabelas auxiliares e coordenador de importação com backup, revalidação, transação e rollback.
- `historical-import/Workspace.php`: armazenamento privado por sessão, fora da raiz pública.
- `historical-import/schema.sql`: schemas completos das três tabelas auxiliares.
- `historical-import/config.example.php`: configuração de importação desativada, destinada a revisão técnica.
- `historical-import/.htaccess`: impede acesso HTTP direto aos ficheiros internos em Apache 2.4.
- `historical-import/README.md`: este guia.
- `tests/historical_import_test.php`: testes em base sintética e PHP 7.0.
- `tests/historical_import_current_database_test.php`: teste exclusivamente de leitura da SQLite atual, sem instalar tabelas nem importar dados.

**Ficheiros existentes modificados: nenhum.** Não foi alterado stock, nenhum catálogo, OFs atuais, produção, shopfloor, planner ou WMS. Não foi feito commit, push, deploy ou instalação de bibliotecas.

URL após colocar estes ficheiros na raiz da instalação: **`https://<dominio>/<pasta-do-gestisser>/historical_import.php`**. O caminho absoluto e o domínio dependem do hosting; não há URL pública publicada nesta tarefa. Usar a mesma sessão de administrador do GesTISSER. Foi escolhida uma entrada na raiz, tal como as outras páginas administrativas, para reutilizar os caminhos da UI.

A página reutiliza o header, footer, Bootstrap e CSS atuais. Usa os componentes existentes de sessão/autenticação/CSRF, mas evita incluir `helpers.php/config.php`, que executam migrações e escritas no arranque. A ligação utilizada para a página e dry-run tem `PRAGMA query_only=ON` antes da autenticação e validação. Em PHP 7.0, PDO SQLite não suporta a opção de abertura `mode=ro` utilizada em versões mais recentes. Os testes verificaram a ausência de alterações na base, tanto no serviço como por HTTP. Há ligações de escrita separadas, apenas para as ações explícitas Preparar tabelas/Importar.

## Descarregar e preencher os Excel

1. Abrir a URL com um administrador.
2. Premir **Descarregar templates de importação** e extrair o ZIP.
3. Exportar os dados do Access e preencher os ficheiros correspondentes. Não alterar nem remover os cabeçalhos.
4. Enviar os oito Excel para normalização externa. Os ficheiros devolvidos devem manter o contrato.

O ZIP inclui `01_encomendas.xlsx`, `02_encomendas_linhas.xlsx`, `03_ofs.xlsx`, `04_of_operacoes.xlsx`, `05_entradas_mp.xlsx`, `06_rolos_rafia.xlsx`, `07_lotes_tintas.xlsx`, `08_movimentos_historicos.xlsx` e um LEIA-ME. Não há linhas de exemplo que possam ser importadas acidentalmente. Todos têm filtros, primeira linha congelada e colunas com largura legível.

Regras:

- `legacy_id`, códigos, números e documentos como **texto**, preservando zeros à esquerda. Nunca renumerar os IDs antigos.
- `importar=1` seleciona a linha; `0` ou vazio ignora. Outros valores são erro.
- Datas `AAAA-MM-DD` ou `DD/MM/AAAA`; datas Excel do sistema 1900 são convertidas. O sistema de datas 1904 é rejeitado. Datas impossíveis são erro.
- Decimais com ponto ou vírgula, sem separadores de milhares; quantidades/pesos não podem ser negativos, exceto movimentos documentais com sinal.
- Sem fórmulas, macros, objetos embebidos ou ligações externas. Substituir fórmulas pelos valores antes do upload.
- Uma folha por ficheiro. Até 100000 linhas, 20 MB por Excel e 50 MB por upload, sujeitos aos limites PHP do hosting. O tamanho descomprimido é limitado a 64 MB.
- `erro_validacao` preenchido pelo normalizador mantém a linha bloqueada até corrigir/remover o erro.
- `afeta_stock_atual` fica sempre a `0`. `1` é rejeitado; esta fase não contém uma regra autorizada para alterar stock.
- OFs históricas exigem estados finais `Concluída`, `Encerrada`, `Fechada` ou `Cancelada`; operações exigem `Concluída` ou `Cancelada`. Não são criadas reservas nem routing ativo.
- Etiquetas: estado vazio assume `AVAILABLE`; estados normalizados aceites `AVAILABLE`, `CONSUMED`, `BLOCKED`, `CANCELLED`. A aprovação final deve confirmar quais são utilizados pela instalação atual.
- `warehouse_id`/`location_id` têm de existir e corresponder entre si. Não é assumido um armazém/localização por descrição.

## Exportação manual da Bobinas.mdb

O MDB anexado não pôde ser transferido para o executor devido ao limite de 32 MiB, e não há `mdb-tables`/`mdb-export` já instalado no ambiente. Não foram instaladas bibliotecas no servidor. Esta implementação não expõe upload de MDB que não possa ler com segurança.

Abrir uma **cópia** da base no Microsoft Access, consultar Ferramentas de Base de Dados → Relações e identificar as tabelas reais. Os nomes abaixo são entidades a identificar, não nomes de tabelas presumidos:

| Entidade | Campos mínimos do Access a preservar e relacionar |
| --- | --- |
| Encomendas | PK antiga, número, cliente/código/NIF, datas, estado, referência cliente e notas |
| Linhas | PK da linha, FK da encomenda, artigo/código/referência, quantidade, unidade, preço e entrega |
| OFs | PK da OF, número, FK/número da encomenda, cliente, artigo, quantidades, datas e estado |
| Operações | PK, FK da OF, código de operação, sequência, quantidade, tempos, datas e estado; omitir ficheiro preenchido se não houver esta informação |
| Entradas MP | PK, número, fornecedor/código/NIF, MP/código/referência, lote fornecedor, quantidade, unidade, peso, documento e notas |
| Rolos | PK do rolo, entrada, MP, fornecedor, lote, identificador, características, pesos/metragens iniciais e restantes, estado e localização |
| Tintas | PK do recipiente/lote, entrada, MP, fornecedor, lote, identificador, pesos inicial/restante, estado e localização |
| Movimentos | PK, data, tipo, origem, MP, quantidade/unidade/peso, lote/rolo, referências à OF/encomenda/documento e notas |

Exportar cada tabela/consulta com Dados Externos → Excel. Preservar chaves PK/FK, sem agrupar registos ou consolidar lotes/rolos. Se os dados estiverem repartidos por tabelas, preparar consultas que preservem uma linha por entidade e fazer as junções usando PK/FK, não nomes. Guardar o esquema/relacionamentos do Access para a revisão. Transformar estes exports nos oito templates com os cabeçalhos fornecidos. A extração automática poderá ser acrescentada depois de verificar uma capacidade de leitura já instalada e os nomes reais das tabelas.

Os lotes são preservados nos campos das entradas, recipientes/rolos e movimentos. Não foi criada uma tabela paralela de lotes. A existência de uma entidade autónoma de lotes na base atual terá de ser confirmada.

## Matching e IDs atuais

Nunca se criam clientes, fornecedores, artigos ou matérias-primas. O `legacy_id` identifica o histórico; não é utilizado como ID atual do catálogo.

- Clientes/fornecedores: código exato sem distinção entre maiúsculas/minúsculas, depois NIF exato, se fornecido. Nomes normalizados são apenas sugestões, incluindo fornecedores, para evitar correspondências inseguras.
- Artigos: código em `erp_finished_products`, depois referências nas colunas que efetivamente existam (`reference`, `customer_product_code`, `proof_reference`).
- MP: código em `erp_raw_materials`, depois referências que existam. Designações apenas sugerem IDs.
- IDs manuais devem existir. Se contradizem um código/NIF/referência resolvido, a linha fica com erro. Referências ambíguas não são automaticamente resolvidas.
- O normalizador pode acrescentar as colunas opcionais `cliente_nif`, `fornecedor_nif`, `artigo_referencia`, `materia_prima_referencia`.
- OFs necessitam de um artigo correspondente **já existente** em `erp_products`, por código, além de `erp_finished_products`, devido à FK legada. Não se chama o bridge que cria artigos automaticamente.
- Dependências entre ficheiros podem ser resolvidas no mesmo lote. Um pai com erro invalida as linhas dependentes. IDs legacy e números contraditórios do pai são erro.

## Upload, dry-run e resolução

1. Selecionar os Excel devolvidos e premir **Carregar Excel**. Isto não importa dados.
2. Um upload substitui a mesma entidade no lote. Pode enviar os ficheiros por partes; os restantes permanecem no lote da sessão para resolver relações.
3. Premir **Validar importação / Dry-run**.
4. Consultar total, válidas, ignoradas, duplicadas/já importadas, referências encontradas/em falta, linhas com erro e avisos.
5. Abrir os problemas, corrigir o Excel e reenviar, ou preencher um ID existente no formulário de resolução manual. Esta ação invalida o relatório anterior.
6. Repetir o dry-run. Descarregar o log JSON com todos os resultados e dados das linhas. A página mostra no máximo 200 problemas; o log inclui todos.

As contagens de duplicadas podem sobrepor-se às linhas com erro quando uma chave aparece repetida dentro do lote. As contagens de referências e avisos são por ocorrência; erros contam linhas com pelo menos um erro. “Válida” verifica dados e referências, mas não significa que o adaptador de escrita já esteja aprovado.

Para respeitar **zero escritas SQLite durante o dry-run**, os seus relatórios e histórico resumido ficam fora da base, numa pasta privada `gestisser-history-<token>` sob a pasta temporária do PHP, com diretório 0700/ficheiros 0600. A identidade da área é associada à sessão e ao utilizador. Os binários XLSX não são conservados após o pedido, mas os dados lidos e a cópia dos valores originais permanecem para a validação/importação. **Limpar lote** elimina os dados da sessão. Uma limpeza pelo sistema operativo pode apagar estes dados; descarregar os relatórios que quiser conservar. Definir retenção/limpeza periódica desta pasta no hosting antes de uso prolongado; os exports contêm dados pessoais/comerciais.

## Tabelas auxiliares

O botão **Preparar apenas tabelas auxiliares** executa o `schema.sql` numa transação. Não é executado em upload, dry-run, descarga de templates ou ao abrir a página. Nenhuma destas tabelas foi instalada numa base de produção nesta tarefa.

- `erp_legacy_import_map`: chave única `(source_system, entity_type, legacy_id)`, destino real, ID atual, código/documento antigo, execução, hash e JSON com valores de origem, valores normalizados e referências resolvidas.
- `erp_legacy_import_runs`: utilizador, ficheiros/entidades, datas, contagens, estado, log, backup, hash do lote/esquema. `rows_updated=0`: não há updates automáticos de entidades importadas.
- `erp_legacy_import_logs`: resultados e falhas por linha. Um erro de importação é persistido após rollback, com entidade, linha e legacy_id da falha.

`dry_run` está previsto no schema das execuções, mas é sempre 0 para as execuções SQLite desta fase: persistir dry-runs aí contrariaria a regra de não escrever na base. Os dry-runs são guardados na área privada da sessão.

Os schemas completos, com FK e índice único, estão no ficheiro **`historical-import/schema.sql`** do pacote e no `schemas_auxiliares.sql` entregue separadamente.

## Importação posterior — condições necessárias

Não ativar apenas para experimentar com a base atual. O fluxo completo para todas as entidades depende da revisão em falta:

1. A `database.sqlite` **atual** já foi analisada; disponibilizar o MDB em ZIP/partes menores para analisar o seu esquema/relacionamentos. O botão **Descarregar esquema atual** exporta nomes, SQL, colunas, FK e hash, sem os dados do catálogo.
2. Confirmar as estruturas reais de encomendas de clientes, linhas, entradas e lotes, relações e regras documentais. Implementar os respetivos adaptadores reutilizando essas tabelas. Não utilizar as compras como destino das encomendas de clientes.
3. Rever os adaptadores preparados de OFs, operações, etiquetas e movimentos. Confirmar estados, unidades, dimensões/datas, ligações que não têm coluna de destino e impactos dos novos registos nos relatórios/WMS. Os campos sem destino nativo ficam rastreáveis no JSON auxiliar, não em tabelas concorrentes.
4. Rever a reconciliação dos registos já presentes sem legacy map. Números de OF ou barcodes já existentes sem mapeamento são bloqueados; não se presume que são o mesmo objeto. Se já existirem etiquetas com outro barcode, reconciliar manualmente os identificadores antigos antes de ativar este adaptador. O programa não pode deduzir esta identidade sem dados adicionais.
5. Para movimentos, o adaptador preparado usa `erp_stock_movements.source_type=Bobinas_documental`, com `afeta_stock_atual=0` preservado no JSON de origem, **sem chamar serviços de stock ou escrever em `erp_stock_balances`**. Confirmar que relatórios/reconstruções de stock da instalação reconhecem esta distinção. Caso não reconheçam, manter este adaptador desativado e adequar a estrutura existente antes de importar. Não há regra para `afeta_stock_atual=1`.
6. Preparar as tabelas auxiliares explicitamente e testar numa cópia da base atual.
7. Após revisão técnica, criar a configuração protegida, usando `config.example.php` como modelo, `enabled=true`, o hash do esquema aprovado e apenas as entidades com adaptadores concluídos/revistos. A configuração padrão é `storage/historical-import-config.php`; preferir uma localização fora da raiz pública e ajustar `configuration()` em `Importer.php`. Nunca colocar credenciais neste ficheiro.
8. Refazer o dry-run e resolver todos os erros. No painel, escrever **IMPORTAR BOBINAS** e premir **Importar lote validado**. Esta é a única ação que inicia a importação.
9. Verificar a execução, descarregar o log e conservar o backup para recuperação.

Adaptadores preparados: `ofs → erp_production_orders`, `of_operacoes → erp_production_order_operations`, `rolos_rafia → erp_raw_material_roll_labels`, `lotes_tintas → erp_raw_material_ink_labels`, `movimentos_historicos → erp_stock_movements`. Encomendas/linhas/entradas permanecem bloqueadas. Se um lote selecionado contiver entidades sem adaptador aprovado ou alguma linha com erro, o lote inteiro não é importado.

O hash do esquema inclui tabelas, colunas, FK, índices, triggers e views; exclui as próprias tabelas auxiliares e a tabela operacional `backup_runs`. Mudanças no esquema invalidam a aprovação. Antes de importar, o módulo cria um backup verificado usando `BackupManager` existente, obtém `BEGIN IMMEDIATE`, refaz a validação e importa o lote numa única transação. Em PHP 7.0, usa consistentemente SQL `COMMIT`/`ROLLBACK` para esse tipo de transação. Se algum INSERT/FK falhar, nenhum INSERT de entidade/mapa do lote permanece. O backup e o registo da execução falhada são conservados. A importação seguinte com IDs/hashes idênticos apresenta “Já importado”; conteúdo diferente com o mesmo ID é erro, sem updates automáticos.

O backup é um snapshot consistente anterior ao lote, mas não desfaz alterações simultâneas feitas pelo resto do ERP depois desse snapshot. Restaurar toda a base requer uma janela de manutenção e uma decisão administrativa; não há rollback posterior automático de uma importação já confirmada.

## Segurança e hosting

PHP 7.0, PDO SQLite, ZipArchive, SimpleXML/LibXML, fileinfo, mbstring e iconv já presentes no ambiente. Não foi introduzido framework nem alterado Composer. Não são extraídos ficheiros ZIP para a raiz pública. XML com DOCTYPE/ENTITY é rejeitado; carregamento de entidades é desativado. Valores SQL usam parâmetros; nomes de destinos são fixos nos adaptadores.

A página exige administrador ativo e usa o token CSRF existente em todas as ações POST. Downloads também exigem autenticação. A aprovação do esquema não é um campo editável no painel, para que a simples confirmação de importação não dispense a revisão técnica.

Apache: manter a regra do diretório interno `.htaccess`. Nginx/outro servidor: configurar negação de acesso a `/historical-import/` e a configurações/backups em `/storage/`; testar que a configuração não é servida como texto. Não modificar os controlos existentes do servidor silenciosamente.

## Validação executada

- Runtime real **PHP 7.0.33 / SQLite 3.46.1**; sintaxe dos novos PHP verificada.
- Teste de regressão `php tests/historical_import_test.php`: matching, NIF ambíguo, sugestões sem auto-matching, IDs contraditórios, datas inválidas, pai inválido, flag de stock rejeitada, zero alterações no dry-run, barcodes determinísticos, backup, idempotência, conflito de hash e rollback com falha na última entidade.
- Roundtrip dos oito templates; fórmulas e XML com entidades externos rejeitados.
- Teste HTTP com cópia descartável do esquema existente: shell administrativa, acesso negado a utilizador comum, CSRF inválido, ZIP/templates, upload multipart, dry-run, logs/esquema, hash da base inalterado, importação real bloqueada e criação explícita só das tabelas auxiliares.

Foi executado um teste exclusivamente de leitura contra a base atual fornecida: integridade/FK, correspondência dos IDs de cliente/artigo/MP e bloqueio do artigo sem referência antiga para OFs. O hash SHA-256 da base original permaneceu inalterado. O teste HTTP foi repetido numa cópia descartável dessa base, sem a substituir ou importar dados históricos. Não foram executados testes de importação com dados reais, de volume completo ou no cPanel atual.

## Resultado da análise da SQLite enviada

186 tabelas; 266 clientes, 19 fornecedores, 1.643 artigos atuais, 207 matérias-primas, 1 artigo em `erp_products`, 1 OF, 3 operações dessa OF, 1.917 movimentos e 1.917 linhas de saldo. As duas tabelas de etiquetas existem e estão vazias. Estas contagens descrevem a base anexada, não foram obtidas de uma instalação em branco.

Só **1 dos 1.643 artigos** tem correspondência por código já existente em `erp_products`. A FK obrigatória `erp_production_orders.product_id → erp_products.id` impede importar OFs dos restantes artigos sem criar artigos (proibido pelo pedido) ou rever o esquema/compatibilidade. O módulo não usa o único artigo antigo como substituto de outros produtos. A ação do painel exige a correspondência correta e bloqueia quando ela não existe.

`erp_purchase_orders` e `erp_purchase_order_lines` são compras a fornecedores, não encomendas de clientes. A receção de compras existente cria movimentos e atualiza saldos; não será utilizada para o histórico documental. Não há tabela de encomendas de clientes/linhas ou entidade autónoma de entradas históricas para reutilizar. Foi solicitada uma decisão entre manter esta escrita bloqueada ou autorizar uma fase separada de evolução do esquema. Até haver decisão explícita, mantém-se bloqueada.

O único trigger da base está em `erp_suppliers`, para timestamps; não existem triggers nos cinco destinos preparados. O módulo verifica estes triggers durante o dry-run e bloqueia triggers de destino desconhecidos que possam escrever em stock/catálogos.

A data de fim real de uma OF não é assumida como data de entrega: `due_date` permanece NULL quando o template não contém uma entrega explícita, e `data_fim` fica preservada nos dados auxiliares de origem. Campos sem destino nativo não serão silenciosamente reinterpretados.

## Atualização: análise exata antes da evolução do esquema

A existência de só um registo em `erp_products` é uma limitação técnica da FK antiga das OFs; **não demonstra falta de correspondência entre os artigos antigos e `erp_finished_products`**. A comparação real com Bobinas.mdb ainda não foi executada, porque o anexo excede o limite de transferência. Não se apresentam contagens de correspondências antigas sem ler essa fonte.

Novos ficheiros: `historical-import/ArticleMatcher.php` e `tests/historical_article_matcher_test.php`. O painel permite descarregar `staging_artigos.xlsx`, carregar a extração dos artigos realmente referenciados no sistema antigo e descarregar um ZIP com `correspondencias_artigos.xlsx`, `excecoes_artigos.xlsx` e relatório JSON. O staging contém legacy_id, código/referência, designação, código/nome de cliente e ID atual do cliente quando já validado. Referências e nomes são comparados sem aproximação: apenas case e espaços são uniformizados; acentos e pontuação são preservados. Cliente associado restringe os candidatos.

Classificações mutuamente exclusivas: MATCH_EXACT_REF, MATCH_EXACT_NAME, MATCH_REF_AND_NAME, MULTIPLE_MATCHES, NO_MATCH. Correspondência única por nome com uma referência explícita contraditória, ou por referência com designação divergente, fica pendente no Excel de exceções, sem article_id confirmado. Todos os registos com legacy_id repetido ficam pendentes. O relatório distingue número de linhas, artigos antigos distintos, linhas duplicadas, classificações, confirmados e exceções. Não cria nem altera artigos ou outros catálogos.

Esta fase **não aplica a evolução de schemas** nem efetua importação definitiva. A criação explícita das tabelas auxiliares e qualquer execução pelo painel estão limitadas ao ambiente `test` (instalação gestisser-test), com o hash do staging real aprovado em `reviewed_article_report_hash` e o esquema atual correspondente ao relatório. Defaults mantêm tudo bloqueado. Ainda falta gerar/rever o relatório com a fonte Access real, definir a evolução retrocompatível, preservar IDs originais e testar a migração numa cópia gestisser-test. Não foi criado nenhum campo legacy nos catálogos atuais.

Depois da análise real, a evolução deverá reutilizar os article_id confirmados, manter ambiguidades pendentes, definir tabelas canónicas para encomendas/linhas sem duplicar estruturas existentes e resolver a FK das OFs sem duplicar artigos. O plano de teste deve incluir backup completo, transação/rollback e comparação de contagens por tabela antes/depois. Até então, os adaptadores dependentes continuam bloqueados. As instruções de ativação anteriores neste guia estão subordinadas a estes novos pré-requisitos; não representam autorização para produção.
