# Ficha individual de cliente

## Instalação e utilização

Implementação PHP 7.0 / SQLite, sem dependências novas e sem migração SQL. Instalar primeiro em `gestisser-dev` / ambiente de teste. Atualizar o código da branch; **preservar database.sqlite, storage/installation.json e os restantes dados/configuração da instalação**. Não copiar a base de teste para produção. Não é necessário executar install.php nem qualquer importador.

Na lista ERP → Clientes, clicar no nome ou em **Ver ficha**. URL: `erp.php?page=customer_profile&id=ID`. O botão Editar abre o formulário existente; Voltar regressa à lista. Os separadores são URLs GET e o histórico do navegador funciona.

Arquivos de execução criados: `erp_customer.php`, `app/Services/CustomerProfile.php`, `assets/customer-profile.css`. Modificados: `erp.php` (despacho antecipado e dois links da lista) e `partials/header.php` (assinalar Clientes no menu da ficha). Testes: `tests/customer_profile_test.php`, `tests/customer_profile_http.py`, `tests/customer_profile_browser.js`. Este documento descreve instalação, contratos e integração futura.

## Arquitetura e relações confirmadas

A página original `erp.php?page=sales` usa `helpers.php` / `config.php`, executa migrações e carrega catálogos do ERP. Mantêm-se exatamente os handlers de criação, validação, edição, gravação e moradas, os campos administrativos, exportação existente e tabela com filtros/ordenação. A ficha é despachada **antes** desse bootstrap legado, utiliza `bootstrap/app.php` para autenticação/sessões e inclui somente as funções de `erp_migrations.php`, sem chamar migrações.

A conexão PDO usa o caminho configurado da instalação e `PRAGMA query_only=ON` antes de autenticar/consultar. O arquivo deve existir; a ficha não cria bases vazias. São reutilizados cabeçalho, menu, rodapé, Bootstrap, ícones, permissões e `SimpleXlsx`. Todas as consultas por identificador/filtros são parametrizadas; ordenação e limites usam valores validados. Os erros passam pelo handler genérico existente, sem SQL em HTML. POST é rejeitado com 405; não existe handler de escrita nesta página.

| Entidade | Fonte e relação utilizadas |
| --- | --- |
| Cliente | `erp_customers.id` e campos existentes |
| Moradas | `erp_customer_delivery_addresses.customer_id` |
| Artigos | `erp_finished_products.customer_id` ou artigo referido numa OF com o `customer_id` exato |
| OFs | `erp_production_orders.customer_id`, `finished_product_id`; fallback apenas pela FK `product_id` para `erp_products` |
| Unidades | `erp_finished_products.unit_id` ou `erp_products.unit_id` → `erp_units.id` |
| Tempos | `erp_operation_time_entries.production_order_operation_id` → operação da OF; `erp_operations` dá o nome da etapa |
| Custos | `erp_production_order_costs.actual_amount`, agrupado pela categoria persistida; totais de `erp_production_order_closures.total_actual_cost` |
| Consumos | `erp_production_consumptions.production_order_id`, `raw_material_id`, `source_movement_id` e o par explícito `stock_unit_type=INK` / `stock_unit_id` |
| Tintas | `erp_raw_material_ink_labels.id`, barcode e supplier_lot pelo par explícito do consumo |
| Ráfia | `erp_raw_material_roll_consumptions.production_order_id` / `source_label_id` → `erp_raw_material_roll_labels` |
| Origem Bobinas | `erp_legacy_import_map` com source_system=Bobinas, target_table=erp_production_orders e gestisser_id exato |

Não se relacionam clientes/artigos/lotes por nome, semelhança, código de texto ou cliente atual do artigo quando falta o cliente da OF. OFs sem FK de cliente ficam fora da ficha. Múltiplos mappings da mesma OF não multiplicam linhas nem contagens.

## Funcionalidades e limites desta fase

- Sete separadores: resumo, encomendas, artigos, OFs, histórico/custeio, rastreabilidade e dados gerais. Cada consulta detalhada é carregada só no separador selecionado.
- Indicadores de OFs, artigos distintos produzidos, quantidade por unidade e tempo encerrado são agregados exclusivamente dos registros ligados ao cliente. Unidades diferentes nunca são somadas; uma unidade desconhecida mantém a quantidade separada por OF. As unidades são as registadas no catálogo atual, não uma unidade histórica inventada.
- Tempos somam somente entradas encerradas, subtraindo pausas; entradas abertas não recebem duração calculada com o relógio atual. Última atividade é criação de OF ou fim de entrada de produção. Na tabela de artigos a data disponível é **criação da OF com produção registada**, identificada na coluna; não se inventa data de conclusão.
- OFs e artigos têm paginação de 20 linhas. Resumo mostra até cinco OFs e cinco artigos mais frequentes. Filtros: ano, artigo, estado e ordenação das OFs; referência/designação dos artigos com wildcards tratados literalmente. Os detalhes de custos/consumos são consultados em lote para a página, sem query por linha. Rastreabilidade limita cada fonte a 200 registos por página com aviso explícito; dossier nativo permite aprofundar cada OF.
- Histórico/custeio mostra tempos por etapa e categorias de custo **efetivamente persistidas**, nunca custos recalculados pelas tarifas atuais. Total e custo unitário só aparecem quando há fecho persistido; divisão só para quantidade produzida positiva. Valores inexistentes aparecem como Sem dados. A seleção por artigo/ano prepara a análise por período; gráficos comparativos e evolução histórica completa dependem de dados e modelo históricos validados.
- Rastreabilidade mostra consumos, datas de registo, lotes, movimentos comprovados, recipientes de tinta e rolos. Nenhum custo de consumo aparece neste separador. Não se procura stock ou lotes por aproximação.
- O módulo atual `erp_purchase_orders` / linhas contém **compras a fornecedores**. Não existe tabela/formulário de venda a clientes confirmado. Por isso, encomendas e última encomenda apresentam estado vazio explicativo; Nova encomenda fica desativado. Não se abre a compra com um falso cliente, nem se duplica uma implementação. Quando existir o formulário comercial, deverá ser reutilizado com cliente selecionado.
- Exportar ficha gera XLSX com dados gerais e moradas de entrega do cliente, sem exportar custos nem catálogo inteiro. A exportação da lista existente continua independente.
- Esta fase não importa Bobinas, não marca/ativa trabalhos no Shopfloor e não escreve em operações, stocks, saldos ou catálogos. A navegação para edição/artigos/dossier usa as páginas existentes, com o comportamento próprio desses módulos.

## Permissões

Requer autenticação, utilizador ativo e **erp.view + erp.customers**. A ficha não adiciona permissões aos perfis. Custos exigem também **erp.costs_view**, incluindo URL direta; exportação exige **erp.reports_export**. Administradores seguem o comportamento existente. Os links nativos mantêm a validação dos respetivos endpoints. A arquitetura atual não oferece ACL por cliente individual: o acesso ao cliente segue a permissão global existente, sem inventar um sistema de autorizações por registo.

## Proposta para o histórico Bobinas — não aplicada

Preferir um armazenamento de consulta histórico separado dos trabalhos/roteiros ativos. Antes de decidir tabelas/campos novos, rever o módulo histórico e mapping já presentes no projeto para reutilizar contratos adequados.

1. Manter o mapping validado de cliente/artigo antigo → `customer_id` / `finished_product_id` atual. Correspondência deve ser exata e confirmada; referências originais, sistema de origem, ID original e estado de validação permanecem auditáveis. Ambíguos ficam pendentes; nenhum catálogo é criado automaticamente.
2. Entidades de leitura propostas: cabeçalhos de encomendas históricas, linhas, OFs históricas, etapas, entradas de tempo, custos efetivos e vínculos de consumo a lotes/recipientes/movimentos históricos. Cada entidade preserva `(source_system, legacy_id)` único, referência original, datas, unidade original, referência ao mapping validado e lote de importação. Dados originais ausentes ficam nulos.
3. Cabeçalhos ligam ao cliente atual por FK validada; linhas/OFs ligam ao artigo atual por mapping validado. Relação OF ↔ linha de encomenda precisa de evidência original, nunca texto semelhante.
4. Tempos/custos históricos guardam os valores originais e respetiva evidência. Etapas históricas são registros de consulta; não criam routing ativo, reservas, clocks, operações pendentes ou custos pelas tarifas atuais.
5. Lotes e movimentos históricos ficam disponíveis para rastreabilidade sem lançar movimentos no stock atual. Uma eventual reconciliação de saldo exige processo separado e explicitamente validado.
6. A camada `CustomerProfile` deverá agregar fontes atuais e históricas por cliente e unidade, com origem explícita e prevenção de dupla contagem. Antes de adicionar leitura histórica, validar IDs/mappings, esquema real, contagens, estados e permissões numa cópia de teste com staging e backups; esta alteração não executa esse trabalho de importação.

Não é criado/alterado índice nesta entrega. Para volumes históricos, medir os planos de execução e considerar índice composto `erp_production_orders(customer_id, created_at, id)` e equivalente por cliente/data no armazenamento histórico, evitando duplicar índices existentes. O esquema definitivo requer validação das fontes reais e das relações, antes de qualquer DDL/importação.

## Validação reproduzível

```sh
php tests/customer_profile_test.php
python3 tests/customer_profile_http.py /caminho/copia-validada.sqlite --php php
# Opcional: Playwright + Chromium disponíveis no ambiente de testes:
python3 tests/customer_profile_http.py /caminho/copia-validada.sqlite --php php --browser
```

O teste HTTP cria **outra cópia temporária** da aplicação/base. Só nessa cópia testa os handlers originais de escrita. A base fornecida nunca é alterada; hash SHA-256 antes/depois confirma isso. O teste opcional de browser permite `CHROMIUM_PATH` e `BOOTSTRAP_ASSETS` (CSS/JS Bootstrap 5.3.3 local para evitar dependência de CDN durante validação).

Validação realizada em PHP **7.0.33**: consultas de unidade mista/desconhecida, FKs entre clientes com nomes iguais, entrada aberta/pausas, custos persistidos, origem histórica, filtros malformados/paginação, pesquisa literal, tinta/ráfia e zero writes com SQLite query_only. HTTP: sete separadores, cliente correto/HTML escapado, 403/404/405, exportação XLSX, criação/edição/gravação/moradas/exportação original e hash idêntico da base em consultas. Chromium: desktop 1440px, tablet 768px e smartphone 390px, sem overflow horizontal da página, menu nativo, navegação Voltar, ação de encomenda desativada e ligações exatas para OF/artigo.
