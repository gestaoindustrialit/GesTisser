# GesTISSER — Ficha Individual de Artigo

Implementação sobre `origin/gestisser-dev` (`144a770c3019a1ccd011d00490bc8eeb7d83c889`), em branch própria para revisão por PR com destino a `gestisser-dev`. A referência remota foi obtida e verificada. Este relatório descreve as alterações e a validação; não houve publicação da aplicação num servidor.

## Ficheiros criados

- `erp_article.php`: página de consulta, autenticação, permissões, indicadores, separadores e exportação.
- `app/Services/ArticleProfile.php`: consultas preparadas por identificador de artigo, histórico, snapshots, routing, documentos, custeio e rastreabilidade.
- `partials/article-profile-history.php`: tabelas de OF, detalhes históricos, consumos, reservas e movimentos.
- `tests/article_profile_test.php`: 22 verificações de dados, isolamento, snapshots, estados vazios, permissões financeiras, filtros e paginação.
- `tests/article_profile_http.py`: integração em cópia descartável da base, com sessão autenticada e regressão dos módulos existentes.
- `tests/article_profile_browser.js`: navegação e layout em browser.
- `app/Services/ArticleEditor.php`: lógica existente de gravação, duplicação e documentos partilhada entre criação/edição legadas e ficha.
- `app/Services/ArticleFormSupport.php`: validações técnicas e de tintas já existentes, extraídas para reutilização.
- `partials/article-profile-form.php`: formulário original partilhado pela criação de artigos e edição na ficha.
- `assets/article-editor.js`: comportamento original de cores, pesos e uploads do formulário, partilhado pelas duas páginas.
- `tests/routing_transaction_test.php`: commit/rollback atómico, chamadas aninhadas e respeito pela transação do chamador.
- `docs/FICHA_INDIVIDUAL_ARTIGO.md`: este relatório.

## Ficheiros modificados

- `erp.php`: encaminha a consulta antes das migrações e cargas gerais; toda a linha da lista abre a ficha por clique ou por Enter/Espaço, seguindo o padrão da lista de clientes. Código e descrição usam a cor normal do texto, sem sublinhado. Editar, Routing e Duplicar saem da listagem e são disponibilizados dentro da ficha. A criação continua a usar o formulário partilhado. Corrige apenas a seleção JavaScript do campo `length`: `form.elements.length` devolvia o número de elementos do formulário; `namedItem('length')` obtém o campo Comprimento e permite continuar o cálculo de peso já existente.
- `erp_customer.php`: artigos, OF e artigos frequentes ligam à ficha de artigo.
- `app/Services/CustomerProfile.php`: reutiliza o leitor paginado de OF/consumos com âmbito relacional explicitamente limitado a `customer_id` ou `finished_product_id`; acrescenta filtros de datas e o identificador real de rolo. O âmbito por defeito continua a ser cliente.
- `erp_routing.php`: o mesmo editor passa a suportar integração no separador Routing, sem repetir cabeçalho/rodapé nem executar migrações em consultas. Os links de versões permanecem dentro da ficha; a página antiga mantém compatibilidade. A coluna opcional `deleted_at` das máquinas só é consultada quando existir.
- `app/Services/RoutingService.php`: conserva `BEGIN IMMEDIATE` e a política de retry, mas faz COMMIT/ROLLBACK via SQL e controla transações aninhadas. Corrige o comportamento de PDO no PHP 7.0, que não reconhece uma transação iniciada com SQL para `PDO::commit()`. Sem alterações à lógica de versões, tempos, consumos ou snapshots.
- `erp_technical_sheet.php`: aceita o contexto preparado pela ficha de artigo, reutilizando o renderer de impressão/Guardar PDF sem criar uma OF nem escrever snapshots. O acesso existente por ID de ficha técnica mantém o funcionamento.
- `partials/header.php`: mantém Artigos selecionado na navegação lateral ao consultar uma ficha.
- `assets/customer-profile.js` e `assets/customer-profile.css`: reutilizam a navegação de linha dos clientes também nos artigos, ignorando botões, links de ações, formulários e seleções de texto.

## Auditoria e relações utilizadas

Foram analisadas as migrações, os serviços e formulários existentes e o esquema SQLite de uma base local de desenvolvimento, aberta em modo de leitura. Não existe acesso ou alteração a uma base de produção nesta tarefa.

| Área | Tabelas e relações |
| --- | --- |
| Artigo e cliente | `erp_finished_products.customer_id → erp_customers.id`; `unit_id → erp_units.id`. Nunca se associa pelo nome do cliente ou código de texto. |
| Características, impressão e BOM | `erp_product_features`, `erp_product_colors → erp_colors`, `erp_article_materials → erp_raw_materials → erp_units`. |
| Produção | `erp_production_orders.finished_product_id → erp_finished_products.id`; `erp_products` apenas na compatibilidade do leitor existente; `erp_production_order_operations.production_order_id → erp_production_orders.id`. |
| Tempos | `erp_operation_time_entries.production_order_operation_id → erp_production_order_operations.id`. Apenas registos encerrados com datas válidas, descontando `pause_seconds`. |
| Routing | `erp_article_routings`, `erp_article_routing_versions`, `erp_article_routing_steps`, `erp_operations`, `erp_work_centers`, `erp_machines`, `erp_routing_step_work_centers`, `erp_routing_step_machines`, `erp_routing_step_materials`. |
| Versões e custeio | `erp_article_technical_sheet_versions`, `erp_technical_sheets`, `erp_production_order_routing_snapshots`, `erp_production_order_closures`, `erp_production_order_costs`. Foram também auditados `erp_production_order_snapshots` e o processo existente de criação de snapshots. |
| Rastreabilidade | `erp_production_consumptions`, `erp_raw_material_ink_labels`, `erp_raw_material_roll_consumptions`, `erp_raw_material_roll_labels`, `erp_production_order_material_reservations`, `erp_stock_movements`. |
| Documentos | `erp_product_documents`, com `entity_type="finished_product"` e `entity_id` real. A maquete atual usa o tipo existente `production_main`. |
| Origem histórica | `erp_legacy_import_map`, quando existente; mapeamentos repetidos são agrupados para não multiplicar OF. |

As encomendas atuais usam `erp_purchase_orders` e `erp_purchase_order_lines`: são compras a fornecedores. Não foram apresentadas como encomendas comerciais de clientes. Reservas não foram apresentadas como consumos efetivos. Etiquetas de impressão e números de lote de texto não foram convertidos em identificadores artificiais de palete.

## Migrações

Nenhuma migração, tabela ou índice novo. A consulta da ficha não chama migrações, sincronização de estados nem gravações; a ligação SQLite usa `PRAGMA query_only=ON`. Apenas POST explícitos com permissão, CSRF e identificadores validados permitem escrita, com transação e rollback. As chaves estrangeiras estão ativas. Uploads reutilizam o mecanismo existente de partes e armazenamento. As regressões HTTP confirmaram a igualdade byte a byte da base antes/depois de consultar os separadores, exportar e rejeitar ações sem permissão.

## Funcionalidades implementadas

- Lista → ficha por clique na linha, Ctrl/Cmd+clique ou Enter/Espaço, com código/descrição na cor normal e navegação cliente ↔ artigo. Documentos permanecem acessíveis na lista.
- Editar artigo abre o formulário dentro de Dados Técnicos da mesma ficha. Gravação e Cancelar permanecem na ficha; falhas de validação fazem rollback e conservam os campos submetidos.
- Duplicar artigo está no cabeçalho da ficha e abre a ficha da cópia em edição, preservando o mecanismo existente de duplicação de BOM e documentos.
- Cabeçalho com código, descrição, cliente, estado, dimensões, gramagem, cores por face e prova.
- Seis cartões em duas linhas de três, reutilizando `assets/customer-profile.css`.
- Visão Geral com dados principais, routing efetivamente ativo, atividade produtiva, últimas cinco OF e documentos.
- Dados Técnicos por grupos, características do saco, cores, BOM, boletim e consulta dos snapshots de versões técnicas. Unidades/normas do boletim permanecem como registadas.
- Routing e versões geridos diretamente no separador da ficha com o editor existente: criar/duplicar versão, ativar, editar etapas, sequência, máquinas, tempos e consumos, e guardar/aplicar templates. Pedidos com versões ou operações de outro artigo são rejeitados antes de escrever. Consultas permanecem sem escrita.
- Encomendas / OF com secções distintas, paginação de 20 OF, filtros inclusivos por data e estado, origem e ligações aos dossiers e fichas técnicas utilizados.
- Custeio com totais previstos/reais guardados no fecho, custo unitário, desvios, tempos históricos e consumos registados. Os detalhes por categoria não são somados novamente ao total do fecho. Não se usam tarifas atuais ou preço de venda para reconstruir custos antigos.
- O indicador de custo médio é a média aritmética do custo real total das OF com fecho registado e produção positiva, em **€/OF**, indicando o número de fechos utilizados. Sem fecho suficiente, apresenta «Sem dados».
- Operações antigas permanecem consultáveis por `operation_name` ou `snapshot_json`, com `LEFT JOIN` ao catálogo, mesmo quando a operação atual já não existe.
- Rastreabilidade artigo → OF → consumos/lotes, rolos, tintas e movimentos; reservas separadas; movimentos de produto acabado paginados, incluindo artigos sem OF.
- Rastreabilidade inversa por identificador real de rolo ou recipiente de tinta, validando primeiro a relação desse identificador com o artigo. Nomes de lote iguais não unem unidades distintas.
- Documentos existentes, identificação da maquete atual e abertura pelo endpoint original. Exportação da ficha técnica atual pelo mecanismo existente de impressão/Guardar PDF.
- Custos, routing e exportação respeitam as permissões correspondentes; POST desconhecidos ou sem permissão/CSRF são rejeitados; os identificadores têm de pertencer ao artigo aberto. Valores apresentados são escapados e identificadores/filtros entram em queries preparadas.

## Dependências da importação histórica

- Encomendas comerciais, respetivas linhas e quantidades entregues permanecem «Sem dados» até existir uma origem comercial comprovada e integrada.
- A ficha já reconhece OF importadas quando tiverem `finished_product_id` e mapeamento de origem reais. Não foram realizadas importações Bobinas.
- Custos, tempos, lotes e configuração histórica só aparecem quando os registos/snapshots existem. Valores ausentes não são simulados.
- Não se inferem relações entre movimentos de produto acabado e OF, nem se criam paletes sem identificadores registados.

## Testes e resultados

Execução principal em **PHP 7.0.33**, sobre fixtures SQLite em memória ou cópias descartáveis. A base fornecida ao runner permaneceu inalterada.

| Validação | Resultado |
| --- | --- |
| Sintaxe de todos os ficheiros PHP afetados | Aprovada em PHP 7.0.33. |
| `article_profile_test.php` | 22 verificações aprovadas, incluindo operações removidas, snapshots, custeio sem duplicação, filtros, paginação e ausência de escritas. |
| `customer_profile_test.php` | 17 verificações aprovadas; regressão do leitor partilhado. |
| Integração `article_profile_http.py --browser` | 57 verificações HTTP e cinco verificações de browser aprovadas. |
| Layout | Sete separadores sem overflow global a 1440, 1024, 768 e 390 px. Validação por viewport Chromium; não substitui execução física em iPad/Android. |
| Navegação e regressão | Lista → artigo → cliente → artigo; editor original; edição, criação e duplicação; routing existente; PDF associado; paginação; estados vazios; permissões, 404, 405 e escaping. |
| Preservação | OF, operações, routings, versões, etapas e templates comparados integralmente antes/depois dos fluxos existentes: sem alterações. |
| Testes PHP selecionados | 16 de 16 aprovados em PHP 7.0.33. |
| `git diff --check`, sintaxe Python e JavaScript | Aprovados. |

Os 16 testes PHP aprovados: `customer_profile_test.php`, `article_profile_test.php`, `article_theoretical_weight_test.php`, `article_pallet_weight_test.php`, `article_current_artwork_test.php`, `article_ink_selector_test.php`, `article_document_presentation_test.php`, `article_document_chunk_upload_test.php`, `article_printer_roll_test.php`, `article_pdf_preview_ui_test.php`, `technical_sheet_print_layout_test.php`, `routing_service_php70_compatibility_test.php`, `production_dossier_cost_test.php`, `routing_transaction_test.php`, `routing_template_test.php` e `routing_sqlite_lock_test.php`.

Os testes de contrato do formulário foram ajustados para ler os componentes partilhados extraídos, mantendo as verificações anteriores. Foi removido o retorno `: void` do auxiliar do teste de paletização para permitir a sua execução em PHP 7.0. O teste e o cálculo passaram nesse runtime.

Foram também aprovadas a gravação dentro da ficha, duplicação com abertura da cópia, criação de versão por clone, gravação/aplicação de template, navegação por teclado e preservação de OF/operações. Permissões e CSRF impedem escritas; uma referência de BOM inválida faz rollback do artigo e da substituição da BOM.

Para repetir:

```bash
source /workspace/gestisser-env/activate.sh
php tests/article_profile_test.php
php tests/customer_profile_test.php
BOOTSTRAP_ASSETS=/workspace/gestisser-env python3 tests/article_profile_http.py CAMINHO_BASE_DEV --php /workspace/gestisser-env/bin/php --browser
```

O runner copia a base e os ficheiros para um diretório temporário e executa alterações de teste apenas nessa cópia.

## Limites e riscos identificados

- A validação não foi feita num servidor de produção nem em dispositivos físicos; não houve publicação.
- A instalação deve conter o esquema atual já utilizado pelos módulos existentes. A consulta não tenta reparar ou migrar automaticamente uma base antiga.
- Consumos, rolos, reservas e rastreabilidade inversa usam resumos limitados a 200 registos, com indicação explícita e encaminhamento para a OF. OF e movimentos de produto acabado têm paginação própria.
- A listagem recebeu apenas ligações; não calcula o histórico de cada um dos mais de 1.600 artigos. As consultas de histórico são executadas apenas na ficha selecionada e os detalhes são carregados em lote por página.
- Os testes selecionados e os fluxos HTTP/browser passaram em PHP 7.0.33. A correção das transações do routing foi também validada com chamadas aninhadas, rollback e transação exterior.
