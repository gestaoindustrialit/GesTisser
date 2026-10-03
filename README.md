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

- PHP 7.0+
- Extensões `pdo_sqlite` e `zip` ativas

## Instalação

1. Iniciar servidor local:

```bash
php -S 0.0.0.0:8000
```

2. Abrir no browser:

- `http://localhost:8000/install.php` para criar o primeiro utilizador administrador.

3. Depois entrar em `http://localhost:8000/login.php`.

> A base de dados `database.sqlite` é criada automaticamente no primeiro arranque.

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
2. Confirmar PHP 7.0+ com as extensões `pdo_sqlite` e `zip` ativas.
3. Configurar as variáveis SMTP (`GESTISSER_SMTP_*`) no novo ambiente, se necessário.
4. Recriar os cron jobs:
   - `* * * * * php /caminho/GesTisser/cron_hr_alerts.php >/dev/null 2>&1`
   - `*/5 * * * * php /caminho/GesTisser/cron_daily_reports.php >/dev/null 2>&1`
5. Validar no browser:
   - `install.php` (apenas se for instalação nova),
   - `login.php`,
   - e envio de teste em `Alertas RH` com **Correr agora**.

## Backups e recuperação

Os administradores podem configurar a frequência, retenção e conteúdo em **Administração → Backups e recuperação**. O backup usa um snapshot consistente do SQLite, valida a integridade e pode incluir os ficheiros da solução. Para executar o agendamento:

```bash
* * * * * php /caminho/GesTisser/cron/backup_runner.php >/dev/null 2>&1
```

Em alternativa, execute `php cron/backup_runner.php --daemon` através de systemd, Supervisor ou outro gestor de processos. Este processo e a página `backup_recovery.php` são independentes do bootstrap da aplicação, permitindo criar e repor backups mesmo perante um erro fatal. Proteja a consola definindo `GESTISSER_RECOVERY_TOKEN` no ambiente ou gerando um token na área de administração.

Antes de qualquer reposição é criada automaticamente uma cópia da base atual. A reposição parcial permite escolher tabelas e é transacional: se forem detetadas relações inválidas, nenhuma alteração é aplicada.
