# GesTISSER — Fase 4: Encomendas de Clientes

Implementação no checkout de desenvolvimento, validada com PHP 7.0.33 e SQLite em bases descartáveis. Não foi alterada uma base operacional ou de produção. Não foi executada importação histórica.

## Auditoria inicial

Não existe `database.sqlite` nem configuração de uma base operacional instalada neste checkout. A auditoria da arquitetura baseou-se no código e nas migrações; os testes HTTP usaram uma cópia descartável de uma base de instalação de teste existente. Não é possível confirmar nesta fase os estados, volumes ou históricos da empresa.

- `erp_purchase_orders` e `erp_purchase_order_lines` representam compras a fornecedores. A rota `erp.php?page=orders`, receções e importação de PDF pertencem a compras. São preservadas e identificadas visualmente como “Compras a fornecedores”.
- `erp_order_imports` e `erp_order_import_line_audit` auditam a importação desses PDFs; não são encomendas comerciais de clientes.
- `erp_production_orders` representa OF, com cliente, artigo, quantidades previstas/produzidas, estado e prazo. A referência de encomenda no dossier/snapshot é texto, não uma relação validada com uma encomenda comercial. Não foram convertidas essas referências em associações automáticas.
- As fichas de cliente e artigo tinham um separador comercial vazio e indicadores “Sem dados”. Os seus métodos `orders()` continuam a consultar OF, para preservar os separadores produtivos existentes.
- Não foi encontrada estrutura comercial de cabeçalho/linhas de encomendas de clientes. A adição abaixo cobre essa ausência; não duplica compras ou OF.
- Numeração reutiliza `NumberSequenceService` e `erp_number_sequences`. A sequência `sales_order` usa `EC-`; compras continuam em `ENC-` e OF em `OF-`. A reserva é transacional.
- `erp_stock_movements` fornece saídas e estornos. Não existe relação nativa comprovada entre uma saída e cliente/encomenda; a associação de entrega exige confirmação explícita e referência documental.
- Os documentos genéricos já existem em `erp_product_documents`. O catálogo é reutilizado com `entity_type=sales_order`, sem novo sistema de ficheiros.
- Custeio reutiliza fechos das OF e distingue custos registados, valores comerciais e faturação não disponível.
- A infraestrutura Bobinas já dispõe de `erp_legacy_import_map`, hashes, JSON original e relatórios. A preparação comercial reutiliza essas correspondências; não instala outra tabela de mappings nem executa o importador.

## Ficheiros criados

- `erp_sales_orders.php` — listagem, ficha, edição, associações, PDF e consulta documental autorizada.
- `app/Services/SalesOrderSchema.php` — migração incremental automática no primeiro acesso de desenvolvimento.
- `app/Services/SalesOrderService.php` — regras comerciais, pesquisas, produção e entregas.
- `app/Services/SalesOrderHistoryPreparation.php` — simulação da futura importação Bobinas.
- `partials/sales_order_edit_line.php` — campos das linhas comerciais.
- `assets/sales-orders.js` — adicionar/remover linhas novas e filtrar artigos por cliente.
- `scripts/migrate_sales_orders.php` — entrada CLI protegida para desenvolvimento.
- `tests/sales_order_service_test.php`, `tests/sales_order_schema_bootstrap_test.php`, `tests/sales_order_http.py`, `tests/sales_order_browser.js` — validação funcional, HTTP e navegador.
- Este relatório.

## Ficheiros modificados

- `erp.php` — despacho independente da nova rota, navegação, indicador comercial real, contexto opcional no formulário atual de criação de OF. A ligação da nova OF é gravada na mesma transação; o caminho sem contexto continua igual. A correção de JavaScript do campo Comprimento já está presente na versão recente da ficha de artigo e foi preservada.
- `erp_article.php` — indicador e histórico comercial no separador Encomendas / OF da ficha mais recente, com quantidades do artigo e paginação própria.
- `erp_customer.php` — indicadores e lista comercial reais, links para encomendas e criação com cliente selecionado, respeitando permissões.
- `app/Services/CustomerProfile.php` — contagem/data comercial e última atividade.
- `partials/header.php` — entradas distintas de compras e encomendas de clientes.

## Estrutura acrescentada e tabelas reutilizadas

Novas tabelas: `erp_sales_orders`, `erp_sales_order_lines`, `erp_sales_order_work_orders` e `erp_sales_order_deliveries`, com chaves estrangeiras, índices e restrições de unicidade. Cabeçalhos preservam origem, ID/número original e JSON original; linhas guardam código, descrição, unidade e valores comerciais no momento do registo.

Reutilizadas: `erp_customers`, `erp_finished_products`, `erp_units`, `erp_number_sequences`, `erp_production_orders`, `erp_production_order_closures`, `erp_stock_movements`, `erp_product_documents`, `erp_audit_log`, `erp_legacy_import_map` e tabelas existentes de permissões.

A migração é transacional e idempotente e é assegurada automaticamente no primeiro acesso autorizado ao módulo em desenvolvimento. Não remove dados nem altera linhas existentes de clientes, artigos, OF, movimentos, reservas ou saldos. Acrescenta apenas a sequência comercial.

## Funcionalidades implementadas

- Listagem paginada com pesquisa por número, código/nome de cliente e código/descrição registados do artigo; filtros de estado, datas, cliente, artigo e atraso; ordenação de número, cliente, datas, artigos distintos, estado e origem.
- Ficha própria com cabeçalho, indicadores e separadores: visão geral, artigos, OF, produção/entregas, histórico/custeio e documentos.
- Design reutiliza diretamente `assets/customer-profile.css`, os cartões, separadores, cores e espaçamentos da ficha de cliente. Mantém os componentes Bootstrap dos artigos e matérias-primas.
- Quantidades separadas por identificador de unidade; unidades desconhecidas ficam separadas por linha. Não há conversões implícitas.
- Criação/edição com validação de cliente/artigo, datas, quantidades e desconto. Clientes/artigos inativos continuam consultáveis no histórico. Não se removem linhas existentes.
- Preços não são obtidos do preço atual do artigo. Totais comerciais existentes são preservados quando quantidade, preço e desconto não mudam. Revisão otimista impede sobreposição de edições concorrentes.
- Estado comercial independente do progresso produtivo e logístico. Sem atualização automática de estados comerciais ou substituição de estados antigos.
- Várias OF por linha. Associação exige cliente, artigo e unidade correspondentes. Uma OF inteira pertence a uma única linha neste modelo; o modelo anterior não previa repartição de uma OF por várias linhas.
- A criação a partir de linha usa o formulário atual, pré-selecionando artigo, quantidade por planear, prazo e referência comercial. Mantém routing, dossier e ficha técnica existentes. A ligação comercial é adicional e não modifica OF anteriores.
- Entregas parciais por associação explícita a saídas existentes, com referência documental e permissão de confirmação. Uma saída não pode ser contabilizada duas vezes; estornos deixam de contribuir para a quantidade entregue. A associação não escreve no ledger ou stock.
- PDF com mPDF existente e exportação autorizada; documentos locais consultados com verificação de entidade e resolução segura dentro de uploads.
- Fichas de cliente/artigo abrem encomendas por IDs internos; a encomenda abre as respetivas fichas.

## Preparação histórica

`SalesOrderHistoryPreparation::simulate($pdo, $rows)` não escreve. Exige correspondências Bobinas já validadas para cliente e artigo; não procura entidades por nome, não cria duplicados e não gera números comerciais, OF ou movimentos.

Entrada esperada por encomenda: `original_id`, `original_number`, `customer_original_id`, `order_date`, `expected_date` opcional e `lines` contendo `article_original_id` e `quantity`. Outros dados originais, incluindo códigos, estados, cliente original, preços e referências a OF antigas, permanecem no JSON integral.

Resultado: `ready`, `unchanged` e `conflicts`. Uma repetição só é considerada inalterada quando mapping, destino, hash e payload concordam. Números existentes, origem alterada ou correspondências em falta produzem conflitos. Não existe botão de execução/importação nesta fase.

## Ativação na cópia gestisser-dev

Não é necessário executar um comando manual. Ao abrir `erp.php?page=sales_orders`, depois de autenticação e autorização, o módulo verifica as quatro tabelas comerciais, os índices e a sequência `sales_order`. Se faltar estrutura, executa a migração na mesma base configurada para a página. Se já estiver completa, a consulta continua sem escritas.

A preparação automática só é permitida para os ambientes `development`, `gestisser-dev` e `test`, ou numa instalação cuja pasta real seja `gestisser-dev` e cuja base real esteja dentro dessa pasta. Esta última condição permite uma cópia de desenvolvimento que herdou a configuração “production”, sem autorizar uma base externa à cópia. Não se usa o URL do pedido para decidir o ambiente.

A ligação regressa ao modo de leitura após a migração, incluindo em caso de erro. Falhas são registadas no servidor e a página indica que é necessário verificar permissões de escrita da base SQLite e da pasta. O script CLI permanece uma ferramenta opcional, não um requisito de ativação.

Não existe uma base operacional neste ambiente; a execução no alojamento terá lugar no primeiro acesso após instalar esta correção. Não foram importados dados históricos nem alteradas OF, reservas ou movimentos de stock.

## Testes executados

- Testes do serviço comercial: 39 verificações em SQLite isolado, incluindo migração repetida, várias linhas/OF, produção e entrega parciais, cancelamento, histórico e entidades inativas, preservação comercial, concorrência, IDs, estados antigos, simulação idempotente e conflitos, ausência de escritas indevidas, paginação e integridade de FKs.
- Regressão `customer_profile_test.php`: 17 verificações aprovadas.
- HTTP PHP 7.0: 27 verificações aprovadas; criação/edição, CSRF, permissões, exportação PDF, separadores, XSS, cliente, formulário atual de OF e base original intacta. A consulta da ficha/PDF manteve a base byte a byte inalterada.
- Chromium: seis separadores a 1440, 1024, 768 e 390 px, sem transbordo horizontal da página; edição de linhas e navegação cliente/encomenda/artigo, sem erros de JavaScript.
- Regressões aprovadas: `number_sequence_service_test.php`, `production_order_creation_form_test.php`, `production_order_form_ui_test.php` e `article_theoretical_weight_test.php`.
- Arranque automático: 13 verificações adicionais aprovadas, incluindo proteção de produção, preparação inicial, estrutura incompleta, sequência em falta, numeração preservada, rollback e restauro de leitura. O teste HTTP parte agora de uma base sem tabelas comerciais, verifica que acesso negado não migra e que o primeiro acesso autorizado prepara o módulo.
- Sintaxe de todos os ficheiros PHP alterados/adicionados validada com PHP 7.0.33; `git diff --check` aprovado.

Após integração com a versão atual de `gestisser-dev`, foram repetidos os testes da Fase 4 e aprovadas também as regressões `article_profile_test.php`, `material_profile_test.php`, `routing_transaction_test.php` e as suites HTTP existentes de cliente, artigo e matéria-prima. As ligações comerciais usam a rota `article_profile` atual e o histórico comercial do artigo dispõe de paginação independente.

Os dados de teste foram criados exclusivamente em bases em memória ou cópias descartáveis. Não se criaram dados fictícios numa base operacional.

## Limitações e riscos identificados

- Falta a base operacional de `gestisser-dev`: auditoria dos dados reais e validação com encomendas reais ficam pendentes; a migração passa a ser automática no primeiro acesso autorizado.
- O checkout referencia `assets/mapper-theme.css` e `assets/mapper-layout.js`, mas os ficheiros não existem. A nova ficha reutiliza o estilo existente do cliente; a navegação lateral não pode ser validada com o tema final neste checkout. Não foi introduzido um tema alternativo.
- O teste valida o formulário atual de OF e a associação comercial, mas não comprova uma criação completa com routing/consumos reais da empresa. As incompatibilidades anteriores do processo de produção em PHP 7.0 continuam a exigir a validação própria da Fase 5.
- A quantidade entregue mostra apenas saídas explicitamente confirmadas. O ledger não identifica por si o destinatário; o utilizador deve confirmar o documento/cliente antes da associação. Não há repartição de uma única saída entre várias linhas.
- O modelo não suporta repartição de uma OF por várias linhas. Isso requer futura alocação de produção comprovada, evitando contagem duplicada.
- Custo previsto/real na ficha usa fechos registados, sem estimar custos ausentes. Não há ligação a faturação nem cálculo de margem.
- Consulta documental reutilizada; não se criou um novo carregador de anexos. A preparação Bobinas é apenas simulação, sem execução.
- Validação de performance limitada à paginação e consultas com índices em dados de teste. Volumes reais e Safari em Mac/iPad não foram testados; o navegador usado foi Chromium.

O campo Artigo pesquisa por descrição ou código e apresenta apenas artigos do cliente selecionado. Selecionar um artigo preenche automaticamente a descrição da linha. Mudar de cliente limpa seleções incompatíveis. Novas linhas também usam a pesquisa; a validação no servidor rejeita artigos de outro cliente e artigos genéricos para novas seleções. Os valores históricos de linhas existentes continuam preservados.

O botão de adicionar artigo é um ícone + na última linha; a sua posição acompanha adições e remoções. A exportação usa SalesOrderPdf, um gerador A4 sem Composer/vendor, com paginação e valores comerciais registados. A rota mantém as permissões de exportação e não escreve na base de dados.

A criação de OF exige uma escolha explícita entre uma encomenda guardada apta para produção e “Sem Encomenda”. Para encomendas, o formulário restringe a escolha às linhas com quantidade ainda por planear, preenche artigo, quantidade e data prevista e associa automaticamente a OF à linha por identificador. Rascunhos, canceladas e concluídas não são oferecidas. Linhas sem unidade registada continuam visíveis; diferenças de unidade são verificadas antes da criação. No servidor, a origem, a pertença do artigo e a quantidade disponível são validadas antes da criação, na mesma transação das fichas e dos snapshots. A referência vem da encomenda guardada. OF sem encomenda ficam identificadas como “Sem Encomenda” no snapshot e não criam relações comerciais. Mantêm-se routing, morada de entrega, transportador e as permissões existentes. Sem migrações.

O histórico fica visível na listagem e por baixo do formulário de nova encomenda. Cada encomenda expande para mostrar quantidades por artigo, evolução de produção e entrega, OF associadas e atividades de auditoria. O estado comercial mantém-se separado das quantidades efetivamente registadas; produzir não implica entregar. O resumo usa a média dos percentuais de cada linha, sem somar unidades diferentes, com barras limitadas visualmente a 100%. A consulta é paginada (20 encomendas) e não altera dados. Disclosure nativo com teclado, controlos para expandir/recolher todas, memória de expansão na sessão do browser e CSS responsivo.

Correção de dados legados: uma encomenda confirmada com unidade não registada no artigo e na linha pode gerar OF ligada, mantendo ambas as unidades como não registadas. Uma unidade efetivamente diferente continua bloqueada com mensagem explícita; não são alterados artigos nem linhas históricas. A atualização do seletor pesquisável foi agrupada por campo, evitando redesenhar o catálogo por cada opção inserida. Com mais de 2000 artigos, a seleção “Sem Encomenda” passou de 2008 atualizações na versão anterior para 4, com pesquisa por descrição e clique reais no browser.


## Confirmação de encomenda numerada

Na ficha de uma encomenda guardada, **Gerar confirmação** abre uma nova aba A4 com **Imprimir / Guardar PDF**, seguindo a Ficha Técnica e a Folha de Acompanhamento. O browser carrega o logótipo em `<img>` e imprime o documento; não se usa o descodificador de imagens do PDF nativo para esta confirmação. PNG, JPG, SVG e WEBP são apresentados pelo browser, independentemente do suporte GD do servidor.

Inclui logótipo e contactos da empresa, NIF, identificação/morada do cliente, datas, referência, moeda EUR, quantidades, descrição técnica, preços, descontos e total sem IVA. Não constitui documento fiscal. Campos não registados não são presumidos. As variações padrão de quantidade ±3% e largura/comprimento ±10 mm foram repostas. São apresentadas no rodapé no lugar de Processado por, que foi removido. Texto editável no Perfil da empresa; uma configuração vazia usa as variações padrão.

Cada encomenda recebe uma única confirmação, numerada pela série própria `CONF-00001`, `CONF-00002`, etc. Novos cliques reutilizam o primeiro registo de documento e não avançam o contador. Depois de alterar e guardar a encomenda, o botão atualiza a confirmação com os dados mais recentes, mantendo o mesmo número e identificador. Sem alterações, reabre a versão existente. Encomendas que tenham várias confirmações emitidas pelo comportamento anterior passam a reutilizar a primeira; os documentos antigos são preservados. O documento HTML imprimível e os dados comerciais usados são arquivados na encomenda; **Documentos → Abrir / Guardar PDF** reabre a versão emitida sem consumir outro número. Uma atualização da confirmação usa os dados guardados da encomenda e os dados atuais do cliente/artigo. As versões anteriores do ficheiro são preservadas e a atualização fica auditada. PDFs já emitidos pelo gerador anterior continuam descarregáveis. O PDF da nova confirmação é guardado no computador pelo diálogo de impressão do browser; o servidor arquiva o HTML, não esse PDF.

Usa `logo_report_dark`, como os outros dois documentos. Incorpora imagens locais em data URI, incluindo caminhos de instalações anteriores. Quando só existe um URL HTTP configurado, entrega esse URL ao browser. A imagem remota permanece dependente da disponibilidade desse endereço. Logótipos em falta não bloqueiam a emissão.

A emissão exige sessão, CSRF, permissão `erp.reports_export` e a revisão atual da encomenda. Falhas de escrita/registo revertem a reserva do número. Usa as tabelas existentes de documentos e sequências. O único ajuste de esquema é o campo de requisição descrito abaixo. A pasta de uploads deve permitir escrita e ser preservada nos backups. Em produção, as alterações comerciais ficam disponíveis depois da ativação explícita do módulo pelo administrador. Antes da ativação, não são preparadas automaticamente pelo simples acesso à página.

A encomenda inclui o campo separado **Nr. Requisição**, apresentado depois do nome do cliente quando preenchido. O NIF do cliente fica depois da morada e do país. A migração incremental acrescenta `requisition_number` a `erp_sales_orders`, automaticamente no acesso autorizado em desenvolvimento/teste, sem alterar registos ou contadores existentes.

Na Configuração ERP, os blocos de Encomendas de clientes e Confirmações permitem definir o próximo número. Apenas administradores podem alterar os contadores, com CSRF e auditoria. O próximo número deve ser superior ao último utilizado na mesma série; não modifica encomendas ou documentos existentes.

Ficheiros de aplicação para esta atualização:
- `erp_sales_orders.php`
- `erp_settings.php`
- `company_profile.php`
- `partials/sales-order-history.php`
- `app/Services/SalesOrderSchema.php`
- `app/Services/SalesOrderService.php`
- `app/Services/SalesOrderConfirmation.php`
- `app/Services/SalesOrderConfirmationPrint.php`
- `app/Services/CommercialNumbering.php` (novo)

## Ativação em produção

Depois de instalar o conjunto completo do módulo, atualizar também:
- `erp_sales_orders.php`
- `app/Services/SalesOrderSchema.php`
- `erp.php`
- `erp_customer.php`
- `erp_settings.php`
- `scripts/migrate_sales_orders.php` (alternativa por terminal)

Criar uma cópia de segurança da base produtiva e manter as pastas de uploads. Entrar como administrador e abrir `erp.php?page=sales_orders`. O botão **Ativar encomendas de clientes** prepara, numa transação, as tabelas/índices de encomendas e o campo `requisition_number`, preserva sequências e registos existentes e guarda `sales_orders_enabled=1` na própria base. A base tem de existir; não é criada nem substituída. Não altera o APP_ENV nem dados de clientes/artigos/OF/stocks. Uma falha reverte a migração e o estado de ativação. Repetir a ativação não reinicia números.

Ativação exige administrador, sessão e CSRF. Depois de ativa, encomendas/confirmar/exportar/OF continuam sujeitos às permissões habituais. A ativação cobre também o botão Nova encomenda na ficha do cliente, a seleção de encomendas na criação da OF e os contadores na Configuração ERP.

Alternativa CLI, executada na pasta da aplicação após o backup:
```sh
php scripts/migrate_sales_orders.php --activate-production
```
Sem a opção explícita, o script continua a recusar migrações em produção. Não copiar a base de desenvolvimento para produção.


## Compatibilidade com PHP 7.0 e integração do Shopfloor

A integração com `main` mantém a seleção de encomenda guardada ou **Sem Encomenda**, os valores comerciais e as confirmações existentes. Quando a unidade foi definida no artigo depois de guardar a encomenda, a criação da OF resolve essa unidade na mesma transação. Se ambas as unidades continuam por registar, mantém o comportamento de `gestisser-dev`, sem inventar unidades.

Na atualização do Shopfloor, incluir também `app/Services/ProductLabelService.php`. Em PHP 7.0, o método `audit()` não pode declarar retorno `void`: essa assinatura provoca HTTP 500 depois de gravar a auditoria da impressão. A versão de `main` remove essa assinatura e é verificada através de pedidos reais de pré-visualização e impressão, para administrador e operador.
