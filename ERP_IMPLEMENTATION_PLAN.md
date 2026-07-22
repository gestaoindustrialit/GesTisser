# Plano de Implementação ERP Industrial gesTISSER

## Fase 0 — Diagnóstico

### Ambiente e stack identificados
- **PHP:** ambiente CLI testado com PHP 8.5.7-dev; o código usa `declare(strict_types=1)`, PDO e SQLite.
- **Base de dados:** SQLite em `database.sqlite`, inicializada por `config.php` e `bootstrap/db.php` com `PRAGMA foreign_keys = ON`.
- **Arquitetura:** aplicação PHP procedural/modular, com páginas por módulo na raiz, bootstrap comum em `bootstrap/app.php`, helpers globais em `helpers.php` e rotas simples em `app/routes.php`.
- **Autenticação:** sessão PHP com `users`, `require_login()`, `current_user()` e perfis em `users.access_profile`; não será criado novo login.
- **Permissões:** atualmente por `is_admin` e `access_profile`; a Fase 1 acrescenta permissões ERP granulares em tabelas próprias, mantendo compatibilidade.
- **Templates/layout:** `partials/header.php` e `partials/footer.php`, Bootstrap 5 e assets em `assets/`.
- **RH e Shopfloor:** módulos existentes em `hr*.php` e `shopfloor*.php`; colaboradores/operadores continuam a ser `users`; turnos e ponto/paragens existentes são reutilizados.
- **Documentos/uploads:** anexos básicos de OF em `erp_production_order_documents`; upload service em `app/Services/UploadService.php` será reaproveitado nas fases seguintes.
- **ERP atual:** `erp.php` concentra criação simples de artigos, OF, movimentos, listagens e anexos; passa a ser dashboard/entrada modular.

## Estrutura existente reaproveitada
- `users` para utilizadores, operadores, responsáveis, comerciais e criadores/modificadores.
- `shopfloor_time_entries`, `shopfloor_break_entries` e motivos de pausa/paragem para início, pausas e fim de trabalho.
- `erp_production_orders`, `erp_production_order_operations`, `erp_operation_time_entries` e documentos de OF como base compatível.
- `erp_units`, `erp_material_types`, `erp_colors`, `erp_warehouses`, `erp_products` e `erp_inventory_movements`, ampliados sem apagar dados.
- `audit_logs` e `log_app_event()` para auditoria aplicacional, complementados por `erp_audit_log`.

## Novas tabelas da Fase 1
- `erp_permissions`, `erp_role_permissions`, `erp_user_permissions`.
- `erp_audit_log`, `erp_number_sequences`, `erp_settings`.
- `erp_material_features`, `erp_inks`, `erp_location_types`, `erp_locations`.
- `erp_raw_materials`, `erp_finished_products`, `erp_product_colors`, `erp_product_features`, `erp_product_documents`.
- `erp_stock_movements`, `erp_stock_balances` como cache recalculável.
- Estruturas base para fases seguintes: compras, receções, bobinas, comercial, confirmações, reservas, produção, consumos, expedição, qualidade, custos e inventários.

## Alterações às tabelas atuais
- Alterações são incrementais/idempotentes; quando SQLite não permite `ALTER` complexo, usam-se tabelas novas normalizadas em vez de destruir dados.
- `erp_products` permanece para compatibilidade; novas fichas detalhadas usam `erp_raw_materials` e `erp_finished_products`, com ligação futura a produtos legados quando necessário.
- `erp_inventory_movements` permanece como histórico legado; novos fluxos críticos usam `erp_stock_movements` imutável.

## Relações entre entidades
- Clientes/fornecedores ligam a orçamentos, encomendas, receções, produtos, expedições e qualidade.
- Matérias-primas ligam a tipos, características, unidades, fornecedores, localizações, bobinas, reservas e consumos.
- Produtos acabados ligam a clientes, materiais, cores, documentos, ordens de fabrico, lotes e expedições.
- OF ligam a encomendas, produto, operações, materiais, reservas, produção, consumos, documentos, custos e auditoria.
- Stock é calculado por `erp_stock_movements`, opcionalmente materializado em `erp_stock_balances`.

## Fluxos funcionais previstos
1. Compra → encomenda fornecedor → receção parcial → bobinas → etiquetas → stock/preço médio.
2. Comercial → orçamento → encomenda → confirmação → OF → reserva → produção Shopfloor → consumos → produto acabado → expedição.
3. Stock → consulta → transferência → inventário → ajustes aprovados → reversão auditada.
4. Qualidade → não conformidade → bloqueio de stock/OF → ação corretiva → verificação.
5. Rastreabilidade bidirecional por bobina, lote, OF, produto, encomenda e expedição.

## Fases de implementação
- **Fase 0:** diagnóstico, plano, mapeamento, backup automático e migrations.
- **Fase 1:** navegação, permissões, auditoria, sequências, armazéns/localizações, ledger, dados mestre, clientes, fornecedores, matérias-primas, produtos acabados.
- **Fases 2 a 6:** compras/receções, comercial/OF, produção, expedição/custos/qualidade, relatórios/otimização.

## Riscos de compatibilidade
- Ambientes com SQLite antigo podem ter limitações em `ALTER TABLE`; migrations evitam alterações destrutivas.
- Dados legados em `erp_products` não são apagados; pode ser necessário mapeamento assistido para classificar como matéria-prima/produto acabado.
- Permissões existentes são simples; a camada granular ERP começa permissiva para administradores e perfis industriais, devendo ser afinada em produção.
- A integração profunda com Shopfloor será faseada para evitar quebra de ponto, pausas e operação diária.
