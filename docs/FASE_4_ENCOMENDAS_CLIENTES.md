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
