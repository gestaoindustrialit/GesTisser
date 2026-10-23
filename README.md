# GesTisser

Aplicação de gestão de trabalho inspirada no ClickUp, desenvolvida em **PHP + SQLite + Bootstrap** (sem frameworks).

## Funcionalidades

> Consulte a [referência completa das funcionalidades atuais e regras de cálculo](docs/FUNCIONALIDADES_ATUAIS.md), com resumo executivo, fórmulas, parâmetros e limitações a validar com a empresa.
>
> Para uma apresentação visual à administração, abra [a visão executiva da solução](apresentacao_solucao.html) diretamente no browser. A página é responsiva, permite filtrar os módulos e está preparada para impressão ou exportação para PDF.

- Autenticação (registo/login/logout)
- Gestão de Equipas e Projetos
- Membros por equipa (apenas membros acedem à equipa/projetos)
- Gestão de utilizadores no dashboard (admin cria users)
- Módulo RH: departamentos/grupos, horários, calendário de férias e alertas por e-mail
- Tarefas, Sub Tarefas e Checklist
- Vista Lista e Vista Quadro (Kanban simples)
- Envio de relatório diário para líder de projeto/equipa
- **Pedidos diretos às equipas** (fora de projetos) em `requests.php`
- **Formulários globais** criados apenas por admin e visíveis para todos os utilizadores
- Admin define os **campos personalizados** de cada formulário (texto, número, data, seleção, textarea)

## Requisitos

- PHP 7.0 (validar aplicação, dependências e testes em PHP 7.0.33)
- Extensão `pdo_sqlite` ativa
- Extensão `zip` ativa (necessária para criar e descarregar backups completos)

## Instalação

1. Iniciar servidor local:

```bash
php -S 0.0.0.0:8000
```

2. Abrir no browser:

- `http://localhost:8000/install.php` para criar o primeiro utilizador administrador.

3. Depois entrar em `http://localhost:8000/login.php`.

> A base de dados `database.sqlite` é criada automaticamente no primeiro arranque.

## Validação em PHP 7.0

O runtime de referência é PHP **7.0.33**, também definido em `.php-version` e em `config.platform.php` do Composer. A plataforma do Composer limita a resolução das dependências; não muda o PHP que executa a aplicação.

```bash
python3 scripts/test_php70.py --php /caminho/para/php70
```

O comando confirma a versão real e recusa PHP de outras versões. Verifica a sintaxe dos ficheiros PHP e executa os testes em cópias temporárias, sem usar a base da aplicação. Os resultados ficam em `work/php70/results.json`; falhas mantêm o resultado global reprovado.

Para executar apenas testes específicos, acrescente os respetivos nomes:

```bash
python3 scripts/test_php70.py --php /caminho/para/php70 operation_consumption_service_test.php stock_label_adjustment_test.php production_label_ui_test.php
```

As auditorias `historical_import_current_database_test.php` e `material_profile_test.php` usam uma base sintética preparada a partir do esquema atual, numa cópia temporária da aplicação. O relatório assinala `data_source: synthetic`; estes resultados não são uma auditoria aos dados de produção. A criação da base e qualquer falha dos testes continuam a reprovar a execução.

Para auditar uma cópia real já preparada, execute separadamente `HISTORICAL_TEST_DB=/copia/base.sqlite php tests/historical_import_current_database_test.php` ou `php tests/material_profile_test.php /copia/base.sqlite`, usando sempre PHP 7.0. Uma aprovação em PHP 8 não substitui estes resultados.

## Pedidos às equipas (global)

- Ir a `Pedidos às equipas` no menu.
- Admin cria formulários globais e define equipa de destino.
- Qualquer utilizador autenticado pode submeter pedidos nesses formulários.

## Relatório diário

- Manual: dentro de cada projeto, clique em **Enviar relatório diário**.
- Automático (cron):

```bash
php cron_daily_reports.php
```

### Alertas RH (cron)

```bash
php cron_hr_alerts.php
```

Recomendado em produção (execução automática a cada minuto):

```bash
* * * * * php /caminho/GesTisser/cron_hr_alerts.php >/dev/null 2>&1
```

### Backups automáticos

O administrador pode criar, programar e descarregar cópias completas em **Administração → Backups**. Para executar a programação, configure:

```bash
*/5 * * * * php /caminho/GesTisser/cron_backups.php >> /var/log/gestisser-backups.log 2>&1
```

Em alojamentos sem `cron`, o primeiro acesso web depois da hora programada também executa o backup. Se a hora guardada já tiver passado, a primeira cópia é criada imediatamente.

Consulte [`docs/RECUPERACAO_BACKUPS.md`](docs/RECUPERACAO_BACKUPS.md) antes de uma recuperação, incluindo quando a interface não arranca.

Se `mail()` não estiver configurado no ambiente, os relatórios/alertas ficam registados em `reports_sent.log`.

### SMTP autenticado (fallback ao `mail()`)

Quando o servidor não tem `sendmail`/`mail()` ativo, pode configurar SMTP com variáveis de ambiente:

```bash
export GESTISSER_SMTP_HOST="smtp.seudominio.com"
export GESTISSER_SMTP_PORT="587"
export GESTISSER_SMTP_SECURE="tls"   # tls | ssl | vazio
export GESTISSER_SMTP_USER="noreply@calcadacorp.ch"
export GESTISSER_SMTP_PASS="***"
```

Opcionalmente:

```bash
export GESTISSER_MAIL_FROM_ADDRESS="noreply@calcadacorp.ch"
export GESTISSER_MAIL_FROM_NAME="GesTisser"
```

Todas as tentativas de entrega (sucesso/falha) ficam registadas em `reports_sent.log`.

## Migração para outro servidor (checklist rápida)

1. Copiar os ficheiros do projeto e garantir permissões de escrita na pasta da aplicação (incluindo `database.sqlite` quando já existir).
2. Confirmar PHP 7.0 com `pdo_sqlite` ativo.
3. Configurar as variáveis SMTP (`GESTISSER_SMTP_*`) no novo ambiente, se necessário.
4. Recriar os cron jobs:
   - `* * * * * php /caminho/GesTisser/cron_hr_alerts.php >/dev/null 2>&1`
   - `*/5 * * * * php /caminho/GesTisser/cron_daily_reports.php >/dev/null 2>&1`
5. Validar no browser:
   - `install.php` (apenas se for instalação nova),
   - `login.php`,
   - e envio de teste em `Alertas RH` com **Correr agora**.
