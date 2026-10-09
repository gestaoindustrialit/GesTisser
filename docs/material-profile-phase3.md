# GesTISSER — Fase 3: auditoria e ficha de material

## Estado da entrega

Ficha de consulta implementada sobre as entidades existentes. Não houve migração,
importação histórica, substituição de base, reclassificação de referências ou
alteração de stocks, movimentos, lotes e produção. O checkout não foi publicado
nem instalado num servidor externo. O ambiente disponibilizado identifica-se como
`development`; as validações HTTP foram explicitamente executadas com
`APP_ENV=gestisser-dev`, numa cópia descartável da base enviada.

## Auditoria anterior ao desenvolvimento

Base enviada: `database.sqlite`, 5.918.720 bytes. Aberta em leitura.
`PRAGMA integrity_check`: `ok`. `PRAGMA foreign_key_check`: sem ocorrências.

| Entidade | Registos | Observação |
| --- | ---: | --- |
| erp_raw_materials | 207 | 73 raw_material; 134 subsidiary |
| erp_material_types | 5 | 68 materiais RF, 5 BOPP, 134 TINTA |
| erp_material_features | 140 | Características configuráveis existentes |
| erp_units | 5 | U, Kg, L, ML e T; todos os 207 materiais usam Kg |
| erp_suppliers | 19 | Todos os materiais têm fornecedor principal |
| erp_supplier_item_mappings | 0 | Estrutura existente para referências de fornecedor |
| erp_stock_balances | 1.917 | Sem duplicados da chave composta nesta base |
| erp_stock_movements | 1.917 | Entradas com origem spreadsheet_import; 207 materiais distintos |
| erp_article_materials | 0 | BOM sem relações de material nesta base |
| erp_routing_step_materials | 0 | Nenhum material associado aos passos |
| erp_production_order_material_reservations | 0 | Nenhuma reserva de produção |
| erp_production_consumptions | 0 | Nenhum consumo efetivo |
| erp_raw_material_roll_labels | 0 | Identificação de rolos já estruturada |
| erp_raw_material_ink_labels | 0 | Identificação de unidades de tinta já estruturada |
| erp_raw_material_roll_consumptions | 0 | Cadeia rolo de origem/resultante/OF existente |
| erp_product_documents | 84 | Todos de finished_product; nenhum anexo de material |
| erp_inks / erp_product_colors | 0 / 0 | Catálogos legados preservados |
| erp_production_orders | 1 | Preservada integralmente |

Só uma referência possui `ink_type_id`. Não se classificaram automaticamente as
restantes, mesmo quando o tipo existente é TINTA. As categorias comerciais amplas
continuam no contrato de `RawMaterialSpreadsheet::PRODUCT_GROUPS`; tipos,
características e tipos de tinta continuam configuráveis pelas funcionalidades
existentes. Não foi criado um catálogo paralelo.

Os campos de largura, gramagem, espessura, comprimento e peso por unidade existem,
mas não registam a respetiva unidade técnica. A ficha explicita essa ausência.
Composição e armazenamento não têm campos próprios. Referências comerciais de
fornecedor têm estrutura em `erp_supplier_item_mappings`, atualmente vazia.

## Endpoints e padrões reutilizados

- Listagem e edição de material: `erp.php?page=raw_materials`.
- Importação: ações `import_raw_materials` e `confirm_raw_materials_import` em
  `erp.php`; modelo em `erp_raw_material_template.php`.
- Leitura XLSX/CSV: `RawMaterialSpreadsheet`; resolução de tipos/características:
  `RawMaterialImportReferenceResolver`, que suporta preview sem criar referências.
- Stocks: `InventoryService`, `StockTransferService`, receções e importação de
  movimentos existentes. Nenhum destes mecanismos foi substituído.
- Etiquetas: `RawMaterialRollLabelService`, `RawMaterialInkLabelService` e respetivas
  páginas de impressão. Não foi criado servidor Zebra de impressão silenciosa.
- BOM na ficha de artigo: `erp_article_materials`, unidade principal do material.
- Routing: `erp_routing_step_materials`; reservas efetuadas pelo `RoutingService`.
- Cores do artigo: seletores guardam códigos ou etiquetas `código — designação`,
  uma por linha nos campos front/back e OF. A consulta reconhece o código completo,
  incluindo etiquetas com designação antiga, sem transformar Pantones em stock.
- Layout: cartões, Bootstrap, separadores horizontais e CSS da ficha de Cliente;
  formulário de edição existente mantido. A nova ficha de Artigo (`article_profile`) fornece os vínculos
  para BOM, routing e dados técnicos.

## Ficheiros

Modificado: `erp.php` — dispatch da ficha antes do bootstrap legado e botão Consultar.

Criados:

- `erp_material.php`: ficha GET, autenticação, permissões, filtros, sete separadores.
- `app/Services/MaterialProfile.php`: entry point de compatibilidade para o serviço.
- `material_profile_service.php`: implementação autónoma na raiz; a localização
  em `app/Services` é mantida como entry point de compatibilidade.
- `tests/material_profile_test.php`: dados reais em leitura e casos em memória.
- `tests/material_profile_http.py`: HTTP numa cópia descartável.
- `docs/material-profile-phase3.md`: esta auditoria e entrega.

Não há tabelas, colunas, índices ou migrações novas. Todas as tabelas relacionadas
na auditoria são reutilizadas conforme a secção. O dispatch evita `helpers.php` e
`config.php` legados, cujas migrações podem preencher dados automaticamente. O PDO
da nova página está protegido por `PRAGMA query_only=ON`.

## Relações e critérios da consulta

1. Material → unidade, tipo, característica, cor, tipo de tinta e fornecedor.
2. Material → saldos por armazém/localização/lote e reservas reais de OF.
3. Material → movimentos, com referência e origem.
4. Material → rolos/tintas, pesos iniciais e remanescentes, localização e barcode.
5. Rolo → relabeling → OF, apenas pelas relações existentes.
6. Material → artigos/BOM, cores de impressão, versões de routing e operações.
7. Material → consumos/OF, quantidade, unidade registada e movimento associado.
8. Material → referências de fornecedor, preços das entradas e anexos existentes.

Disponível = físico − reservado − bloqueado, como no inventário existente.
Sem linha de saldo significa ausência de dados; saldo registado a zero apresenta
zero. O consumo acumulado usa apenas registos concluídos e agrupa pela unidade
registada; não soma kg e litros. Utilizações abertas são consultáveis na tabela,
mas não integram o acumulado concluído.

Os filtros de data aplicam-se a movimentos, etiquetas e consumos; os saldos são
atuais. Cada tabela tem 20 registos por página. O filtro de pesquisa é literal e
parametrizado. A mudança de separador reinicia a paginação.

O esquema de movimentos não contém unidade histórica. Não foi preenchida a partir
da unidade atual. Pesos das etiquetas usam os campos explicitamente em kg e os
comprimentos dos rolos usam os campos explicitamente em metros. Não existe
conversão automática. A data da etiqueta não é tratada como receção; um saldo de
lote não é tratado como quantidade inicial.

Os custos exigem `erp.costs_view`. Preços existentes são apresentados como valores
registados, com aviso de zeros por omissão; não se afirma que constituem um custo
médio calculado. O botão Editar exige `erp.master_data`. A consulta exige
`erp.view`, como a área de materiais existente. POST é rejeitado.

## Fonte de verdade proposta para as próximas fases

| Registo | Fonte proposta | Compatibilidade / risco |
| --- | --- | --- |
| Ficha e classificação | erp_raw_materials e catálogos existentes | Preservar IDs/códigos e classificação importada |
| Previsão técnica por artigo | BOM da versão técnica / snapshot da OF | Não somar às mesmas necessidades do routing |
| Material por operação | routing versionado / snapshot da OF | Contexto operacional; definir explicitamente se substitui ou reparte BOM |
| Reservas atribuídas à operação | erp_production_order_material_reservations | reserved_qty dos saldos é projeção, não outra reserva a somar |
| Stock físico/disponível | erp_stock_balances | Não adicionar quantidades das etiquetas ao físico |
| Consumo efetivo | erp_production_consumptions | source_movement_id identifica lançamento de stock; não somar ambos |
| Movimentação e reversões | erp_stock_movements | Preservar origem, datas e unidade quando futuramente disponível |
| Identidade do rolo/unidade tinta | tabelas de etiquetas atuais | barcode estável; preservar cadeia de relabeling |
| Custo previsto | política explícita de BOM/routing na versão | Dossier já calcula componentes da BOM; evitar nova soma duplicada |
| Custo efetivo | consumo e respetivo lançamento de stock | Definir política contabilística, sem revalorização nesta fase |

O RoutingService já reserva materiais dos passos ao libertar OF. O dossier usa BOM
para componentes do custo previsto. Existe risco de dupla previsão/custo ao
integrar os dois catálogos. A base enviada não permite observar esse cenário, pois
ambas as relações estão vazias. Nenhuma lógica operacional foi alterada.

## Importação Bobinas: preparação e limites

Não foi importado histórico. A pré-visualização existente foi preservada; não se
afirma que seja já um importador Bobinas completo. Antes da próxima importação:

- Identificar por origem + código original; nunca só pela designação.
- Confrontar referências de fornecedor e IDs antigos de lote; relatório de
  conflitos para códigos colidentes, unidades, fornecedor e classificações.
- Dry-run de toda a operação, incluindo relações, sem criar tipos ou movimentos.
- Confirmar plano imutável antes da execução transacional; não reclassificar
  materiais existentes por omissão de grupo no ficheiro.
- Não deduzir fatores rolo/kg nem recriar unidades de stock para cores.

Mapeamento definitivo e testes do importador dependem da estrutura/dados Bobinas.

## Verificação

PHP 7.0.33:

- Nova ficha e serviço: sintaxe válida.
- Teste da ficha: 207 materiais × 14 projeções, disponibilidade comparada com os
  saldos, permissões de custo, filtros, ID inexistente e pesquisa literal.
- Casos isolados em memória: sem fornecedor/unidade/movimentos, saldo zero,
  consumos com Kg e L, utilizações abertas, BOM, routing, reservas, fornecedores,
  documentos e códigos de cores sem correspondências parciais.
- HTTP em `gestisser-dev`: 3 referências × 7 separadores, custo oculto sem
  permissão, POST rejeitado, ID array rejeitado, data inválida, ID inexistente,
  pesquisa literal e paginação limitada. SHA-256 da cópia não mudou nas consultas.
- Regressão aprovada: etiquetas de rolo, etiquetas de tinta, base de tintas,
  compatibilidade do routing e seletor de tintas do artigo.

Três testes preexistentes não passam em PHP 7.0: `raw_material_spreadsheet_test`
usa arrow functions; `operation_consumption_service_test` usa retorno `void`;
`raw_material_import_reference_resolver_test` compara estritamente o inteiro 1 com
o valor devolvido como string pelo PDO desta versão. Os três passam em PHP 8.4
(o leitor CSV emite aviso de depreciação). Não foram alterados para mascarar as
limitações do conjunto existente.

O SHA-256 da base enviada permaneceu inalterado. Nenhum registo fictício foi
inserido nela. Fixtures existem apenas em memória; cenários de permissão HTTP usam
utilizadores existentes numa cópia descartável. Não se arrancou o ERP legado
contra a base enviada. Não foi efetuada validação visual num tablet físico.

## Dependências e limitações

- BOM, routing material, reservas, consumos, etiquetas e documentos de material
  terão dados apenas após o registo das respetivas relações; a ficha não os inventa.
- Campos próprios de composição, armazenamento, fabricante, validade das unidades
  de tinta e data de receção precisam de proposta estrutural separada. Não há
  migração destes campos nesta entrega.
- Anexos têm consulta de metadados; não se expõem caminhos internos de ficheiros.
  Download de material requer endpoint autenticado apropriado (o endpoint atual
  de documentos de artigo restringe a entidade finished_product).
- Não se demonstra custo médio calculável a partir desta base nem se infere
  fornecedor histórico a partir do fornecedor principal atual.
- Acrescentar grupos comerciais arbitrários exige rever o contrato fixo do
  importador; os catálogos de tipos/características já são configuráveis.
- A ficha foi validada no checkout e numa cópia `gestisser-dev`. A instalação real
  precisa de apontar para a base dev pelos mecanismos existentes; a base enviada
  não foi copiada para substituir `database.sqlite` da aplicação.

## Correção do carregamento do serviço

O log de `gestisser-dev` para a referência `295D0B56E624` registou
`Class 'MaterialProfile' not found` em `erp_material.php`, linha 22. O erro ocorre
ao instanciar a classe, antes de consultar materiais; não identifica uma falha SQL.
O ficheiro carregado em `app/Services` não disponibilizou a classe nesse pedido.
Sem acesso aos ficheiros/cache do servidor, não se distingue ficheiro incompleto
de uma versão compilada desatualizada.

A implementação foi colocada em `material_profile_service.php`, na raiz, e a
ficha carrega diretamente esse entry point autónomo. É o padrão já usado por
`ArticleDocument` e `ProductionDossierService` para evitar dependências de
carregamento na pasta `app/Services`. O caminho anterior permanece um wrapper
para os consumidores existentes; não há uma segunda implementação da classe.

O teste HTTP aceita `--simulate-stale-service`: na cópia descartável, simula um
ficheiro em `app/Services` que é incluído mas não declara a classe. O carregador
antigo reproduz `Class 'MaterialProfile' not found`; a ficha com o entry point
raiz deve continuar a abrir todos os separadores em PHP 7.0. A correção não
altera o esquema, permissões ou dados da base.
