# Company Mapper — fundação de schema

## Análise do repositório e conflitos encontrados

A referência sanitizada em `docs/mapper-reference/` descreve entidades `people`, `departments`, `shifts`, calendário, máquinas, fluxos, sistemas, cadernos e melhorias. No GesTISSER real estas entidades já existem parcialmente e foram reutilizadas:

- `people` mapeia para `users`, mantendo `manager_name` como alternativa legada e adicionando apenas `manager_user_id`, `capacity_percent` e `org_sort_order`.
- `departments` mapeia para `hr_departments`, que já tinha `group_id`; esse agrupamento foi preservado.
- `shifts` mapeia para `hr_schedules`; não foi criada tabela duplicada para horários dos colaboradores.
- Calendário anual mapeia para `hr_calendar_events`; não foi criada tabela duplicada de eventos.
- Centros de trabalho mapeiam para `erp_work_centers`; foram adicionados campos mapper sem reconstruir a tabela.
- Permissões e auditoria usam as estruturas ERP existentes: `erp_permissions`, `erp_role_permissions`, `erp_user_permissions` e `erp_audit_log`. Administradores continuam com acesso total pelo comportamento atual de `erp_user_can`.
- `projects` e `tasks` são reutilizados como ligações opcionais de melhorias.

## Ficheiros de referência consultados

- `docs/mapper-reference/README.md`
- `docs/mapper-reference/COMPONENT_MAP.md`
- `docs/mapper-reference/ui/index.reference.html`
- `docs/mapper-reference/ui/styles.reference.css`
- `docs/mapper-reference/ui/script.reference.js`
- `docs/mapper-reference/data-model.example.json`

## Migração

O ficheiro `company_mapper_migrations.php` define `company_mapper_run_migrations(PDO $pdo)`, ativa `PRAGMA foreign_keys = ON`, verifica `sqlite_master`, `PRAGMA table_info` e `PRAGMA index_list`, controla a versão em `app_settings.company_mapper_schema_version` e cria backup antes da primeira alteração real em `backups/company_mapper_YYYYmmdd_HHMMSS.sqlite`.

A integração ocorre em `config.php` depois das tabelas base ERP/RH e depois de `erp_run_phase1_migrations($pdo)`.

## Enriquecimentos de tabelas existentes

- `users`: `manager_user_id`, `capacity_percent`, `org_sort_order`; índices `idx_users_manager_user_id`, `idx_users_department_schedule`, `idx_users_org_sort`.
- `hr_departments`: `code`, `color`, `description`, `manager_user_id`, `is_active`, `sort_order`, `updated_at`; índice `idx_hr_departments_mapper`.
- `hr_schedules`: `code`, `color`, `is_active`, `notes`, `updated_at`; índice `idx_hr_schedules_mapper`.
- `hr_calendar_events`: `description`, `owner_user_id`, `department_id`, `all_day`, `status`, `updated_at`; índice `idx_hr_calendar_events_mapper`.
- `erp_work_centers`: `department_id`, `location`, `description`, `sort_order`, `updated_at`; índice `idx_erp_work_centers_mapper`.

## Novas tabelas

- `erp_machines`
- `hr_machine_competencies`
- `company_duty_sheets`
- `company_duty_sheet_versions`
- `company_systems`
- `company_process_flows`
- `company_process_nodes`
- `company_process_edges`
- `company_improvements`

As relações opcionais usam `ON DELETE SET NULL`. Competências e versões usam cascata onde requerido. Fluxos eliminam nós e ligações por cascata.

## Enums

- Máquina `status`: `operational`, `limited`, `maintenance`, `stopped`, `inactive`.
- Máquina `criticality`: `high`, `medium`, `low`.
- Competências: 0 sem conhecimento, 1 em formação, 2 com supervisão, 3 autónomo, 4 formador/especialista.
- Cadernos: `draft`, `validated`, `review`, `inactive`.
- Sistemas `billing_period`: `monthly`, `annual`, `one_time`, `none`.
- Sistemas `status`: `active`, `review`, `risk`, `inactive`.
- Fluxos `status`: `draft`, `hypothesis`, `validated`, `archived`.
- Nós `node_type`: `operation`, `decision`, `control`, `external`.
- Melhorias `area`: `production`, `process`, `supplier`, `technology`, `people`, `quality`, `other`.
- Melhorias `priority`: `critical`, `high`, `medium`, `low`.
- Melhorias `status`: `identified`, `analysis`, `planned`, `in_progress`, `blocked`, `implemented`, `measured`, `cancelled`.

## Aplicação, backup e rollback

Para aplicar no servidor, copiar os ficheiros do PR e abrir a aplicação ou executar um bootstrap PHP que inclua `config.php`. A migração cria automaticamente backup em `backups/` antes da primeira alteração.

Rollback: colocar a aplicação em manutenção, parar processos PHP, substituir `database.sqlite` pelo backup `backups/company_mapper_*.sqlite` correspondente, repor os ficheiros da versão anterior e voltar a ativar a aplicação.

## Validação

Executar:

```bash
php scripts/validate_company_mapper_schema.php
```

O script copia a base real para uma base temporária, executa a migração duas vezes, valida idempotência, tabelas, colunas, índices, permissões, constraints e cascatas, e remove a cópia temporária.

## Ficheiros criados e alterados

- Criado: `company_mapper_migrations.php`
- Criado: `app/Services/CompanyMapperService.php`
- Criado: `scripts/validate_company_mapper_schema.php`
- Criado: `COMPANY_MAPPER_SCHEMA.md`
- Alterado: `config.php`

## Desvios e decisões em aberto

- As permissões Mapper foram inseridas em `erp_permissions` porque é o schema real de permissões encontrado.
- `users.department` e `users.department_id` coexistem; leituras do serviço preservam compatibilidade e não migram dados entre ambos neste PR.
- Não foram adicionadas foreign keys a tabelas existentes para evitar reconstruções SQLite destrutivas.
- A interface completa, endpoints e formulários ficam para PR posterior.
