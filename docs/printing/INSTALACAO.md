# Impressão centralizada GesTisser

Estado: implementação incremental, desativada por defeito. A instalação e os testes físicos em Windows 10, Zebra, HP e Android têm de ser realizados na Tisser. Não foi demonstrada impressão física neste ambiente Linux.

## Arquitetura e compatibilidade

O cPanel guarda os trabalhos em SQLite. Cada agente inicia pedidos HTTPS de saída de 5 em 5 segundos e publica inventário de 30 em 30 segundos. Não se abrem portas, não se instala software nos tablets, não existe dependência do Windows 8 nem de serviços pagos de impressão. PHP 7.0.33; FPDF já existente; serviço C# para .NET Framework 4.x do Windows 10.

A tabela existente `erp_printers` continua a ser a fonte das impressoras. `erp_work_centers.default_printer_id` e as páginas de impressão habituais continuam disponíveis. A nova relação `erp_work_center_printers` permite vários postos e várias impressoras, com autorização explícita por tipo/formato, prioridade, alternativa e seleção manual. A unicidade da predefinida é por posto, tipo **e formato**: permite uma Zebra predefinida por tamanho de etiqueta sem conflitos. Não se inferem formatos das impressoras antigas; apenas a associação existente é migrada para Documento/A4 e deve ser confirmada pelo administrador.

As filas Windows podem corresponder a USB ou a impressoras de rede instaladas com o respetivo driver no PC. A configuração distingue USB, IPP e TCP/IP através de filas Windows. O envio direto IPP/RAW TCP independente do spooler **não está implementado nesta versão**; os endereços legados são preservados, mas não executados a partir do cPanel. A HP pode ser instalada como fila Windows, incluindo quando tem ligação de rede.

Os modelos de rolo e tinta reutilizam os serviços e os registos existentes. O adaptador PDF/ZPL mantém os campos, mas tem um layout simples próprio para envio ao spooler; a reprodução visual exata dos layouts HTML, logótipos não está concluída. Não existe novo editor. A integração silenciosa do Shopfloor cobre etiquetas de rolo/tinta e produto acabado A4. Este último reutiliza ProductLabelService e Code128Barcode. As restantes páginas mantêm o percurso habitual.

## Publicação progressiva

1. Publicar os ficheiros desta alteração em conjunto no branch `main`. Não publicar `work/`, backups, credenciais ou bases locais. O branch `gestisser-dev` não é alterado.
2. Fazer uma cópia privada dos PHP anteriores (`erp_settings.php`, `shopfloor.php`, `product_label.php`) pelo mecanismo de publicação do cPanel. O pacote contém um manifesto SHA-256 dos ficheiros novos e alterados. O backup local do agente de desenvolvimento não substitui o backup do alojamento.
3. Abrir Configuração ERP → Impressoras de rede → Preparar impressão centralizada. Esta ação cria um snapshot SQL consistente de `erp_printers` e `erp_work_centers`, sob `BEGIN IMMEDIATE`, antes de qualquer alteração ao esquema. Se o backup falhar, a migração não prossegue. A operação é incremental, transacional e idempotente. O snapshot usa um diretório protegido por `.htaccess`; confirmar resposta 403 do Apache para esse diretório. O lock de escrita é breve, mas pedidos concorrentes podem aguardar o `busy_timeout` existente.
4. O sistema permanece desativado. Criar agente e guardar ID/token numa localização privada. Um token só é mostrado na criação/regeneração; o ERP guarda SHA-256 de um segredo aleatório de 256 bits.
5. Instalar um agente e confirmar inventário antes de autorizar filas. Os nomes das filas têm de corresponder exatamente ao inventário. Criar/editar a impressora na lista existente e completar código, fila, agente, linguagem e DPI na configuração central. Para USB, o campo de endereço legado pode usar um identificador único `smb://PC/Zebra01`; esse identificador não é usado para enviar dados.
6. Criar tipos, formatos e modelos. Para rolos/tintas, escolher os geradores `raw_material_roll_label` e `raw_material_ink_label`. Os formatos com menos de 50 × 30 mm não são suportados pelo adaptador ZPL. Não associar formatos que a impressora não suporta.
7. Associar postos, impressoras, tipos e formatos; definir predefinidas e alternativas. A compatibilidade física é declarada pelo administrador através destas associações.
8. Ativar só quando o agente e as configurações estiverem prontos. Começar com uma Zebra; testar tablet, HP A4 e depois restantes PCs. O botão de teste permite upload de PDF/ZPL apenas por administrador e exige tipo/formato autorizado.
9. Configurar retenção diária no cron cPanel, usando PHP 7.0.33: `php /CAMINHO/GesTisser/cron/printing_cleanup.php`. Sem processo permanente. O botão de limpeza aplica a mesma política manualmente.

## Instalar no Windows 10

Copiar a pasta `windows-print-agent` para uma pasta privada do administrador. Abrir Windows PowerShell 5.1 como Administrador. O instalador usa o compilador .NET Framework já instalado para criar `GesTisserPrintAgent.exe`; se não existir, instalar/ativar .NET Framework 4.x. Não descarrega binários.

```powershell
.\Install.ps1 -Url 'https://ERP/print_agent_api.php' -AgentId 'ID_DO_ERP' -Queues @('ZDesigner ZD220','HP Producao') -PdfExe 'C:\Program Files\SumatraPDF\SumatraPDF.exe'
```

O token é pedido de forma protegida, sem aparecer na linha de comando. Configuração e token DPAPI LocalMachine em `%ProgramData%\GesTisserPrintAgent`; ACL limitada a SYSTEM/Administradores. Serviço automático `GesTisserPrintAgent`, por defeito LocalSystem. O inventário e as permissões observadas são os dessa conta, não os da sessão do operador. Instalar as impressoras para a máquina e testar sob a conta do serviço; drivers que exigem sessão interativa podem não funcionar. Para filas de rede, uma conta de serviço adequada pode ser necessária (configurar nas propriedades do serviço do Windows).

Para PDF, instalar **SumatraPDF oficial** localmente e indicar o caminho absoluto. É gratuito, corre sem diálogo com `-silent -print-to` e usa o driver Windows. Não é incluído nem descarregado automaticamente. Zebra ZPL não precisa deste componente. O agente suporta orientação e cópias, mas opções adicionais específicas do driver e seleção explícita de tabuleiros não estão implementadas.

Logs diários em `%ProgramData%\GesTisserPrintAgent\agent-AAAAMMDD.log`; retenção de 30 dias. Não contêm token nem conteúdo impresso. O diário `job-ID.json` é persistido e sincronizado antes do início do envio. Não o apagar durante atualizações: isso enfraquece a proteção contra duplicações. São preservados os diários; planear arquivo privado de longo prazo. PDFs temporários são eliminados após a tentativa normal; após falha de energia podem sobrar PDFs, que o serviço elimina quando tiverem mais de 24 horas, preservando os diários.

Atualizar: parar o serviço, preservar ProgramData, substituir os fontes e executar novamente Install.ps1 com as filas e a credencial autorizada. O token pode ser regenerado no ERP. Desinstalar: executar `Uninstall.ps1` como Administrador e revogar o agente no ERP. Dados locais são conservados por defeito.

O workflow GitHub `Windows print agent` compila e verifica a sintaxe PowerShell em Windows; o artefacto contém o EXE e os scripts. Uma compilação CI não comprova funcionamento dos drivers locais.

## Estados e recuperação

- `pending`: ainda não reservado; pode cancelar/transferir para destino compatível.
- `reserved`: lease de 2 minutos, exclusiva e renovada com novo token se expirar antes de iniciar processamento.
- `processing`: barreira confirmada pelo servidor antes de tocar no spooler; após 10 minutos sem resposta fica `unknown`, sem reenvio automático.
- `spooler_accepted`: aceitação pelo spooler/retorno do helper PDF. **Não é prova de impressão física**. O agente atual não marca automaticamente `completed`, pois não tem confirmação física fiável.
- `error`: falha conhecida antes do envio; conserva diagnóstico.
- `unknown`: saída incerta; nunca reenvia automaticamente.
- `cancelled`: cancelamento de pendente.

O serviço verifica o hash SHA-256 do conteúdo e a lista local de filas, recusa redirecionamentos HTTP e não recebe comandos, caminhos locais ou executáveis do ERP. Endpoint e helper PDF são apenas configuração local administrada. Payloads até 10 MB, total em base até aproximadamente 500 MB e até 5000 trabalhos ativos; a inserção é recusada quando estes limites são atingidos. A retenção remove conteúdos terminais após 30 dias, preservando histórico e auditoria; pendentes não são removidos. Administradores podem transferir apenas pendentes; linguagens, formatos, DPI ZPL e autorizações são revalidados. Uma reimpressão é um novo trabalho, exige confirmação explícita de possível duplicação e regista o original.

As recuperações de leases são aplicadas quando um agente solicita trabalho. Se todos estiverem desligados, o estado pode manter-se visível como reservado/em processamento até à próxima consulta.

## Reversão

Desmarcar a ativação na Configuração ERP: bloqueia novos trabalhos e reservas; não apaga impressoras, associações, fila ou auditoria. Trabalhos já entregues/reservados podem ainda terminar; parar agentes se for necessário suspender também essa execução. O Shopfloor volta às páginas habituais.

Para reverter código, desativar primeiro, parar os agentes e repor os PHP privados anteriores pelo mecanismo de publicação. **Não restaurar o snapshot SQL sobre a base ativa**: poderia perder alterações posteriores. O esquema aditivo pode permanecer na base; se for necessário recuperar valores antigos, comparar o snapshot numa base isolada e aplicar apenas correções selecionadas. Não existem migrações destrutivas automáticas.

## Relatório de ficheiros e base de dados

Publicação autorizada em `gestaoindustrialit/GesTisser`, branch `main`, com 22 ficheiros e impressão centralizada desativada por defeito. A publicação de código no GitHub não confirma a sincronização do cPanel. O agente não executou a migração nem alterou dados no alojamento; a preparação e ativação continuam a exigir ação explícita na Configuração ERP.

Tabelas novas: `erp_print_config`, `erp_print_agents`, `erp_print_types`, `erp_print_formats`, `erp_print_templates`, `erp_work_center_printers`, `erp_print_jobs`, `erp_print_audit`. Campos aditivos em `erp_printers`: `print_code`, `printer_type`, `brand`, `model`, `connection_type`, `agent_id`, `windows_queue`, `language`, `dpi`, `online`, `last_seen`, `notes`. Preserva IDs, endereços, impressoras e associação legada. A preparação da base só ocorre por ação administrativa explícita.

Validação local: PHP 7.0.33, 336 ficheiros PHP sem erros de sintaxe, sete testes selecionados aprovados e 36 verificações HTTP locais aprovadas. Serviço C# compilado e sintaxe dos instaladores PowerShell validada em runner Windows (.NET Framework). Self-test aprovado: configuração de filas, DPAPI, validação de argumentos e escrita/substituição durável do diário. Execução CI: https://github.com/gestaoindustrialit/GesTisser/actions/runs/40336234760 . Validação física Windows 10/Zebra/HP/Android pendente. Backups dos PHP anteriores apenas na área local de trabalho; o backup do alojamento ainda não foi executado.

| Ficheiro | Finalidade |
|---|---|
| `.github/workflows/windows-print-agent.yml` | Compilação Windows em CI |
| `app/Services/CentralPrinting.php` | Backend, API, administração ou migração |
| `app/Services/PrintLabelRenderer.php` | Backend, API, administração ou migração |
| `cron/printing_cleanup.php` | Backend, API, administração ou migração |
| `docs/printing/INSTALACAO.md` | Documentação e validação local |
| `docs/printing/VALIDACAO.md` | Documentação e validação local |
| `erp_settings.php` | Integração nas páginas existentes |
| `includes/printing_admin_actions.php` | Backend, API, administração ou migração |
| `includes/printing_admin_panel.php` | Backend, API, administração ou migração |
| `includes/product_central_print.php` | Backend, API, administração ou migração |
| `migrations/central_printing.sql` | Backend, API, administração ou migração |
| `print_agent_api.php` | Backend, API, administração ou migração |
| `print_label.php` | Backend, API, administração ou migração |
| `print_submit.php` | Backend, API, administração ou migração |
| `product_label.php` | Integração nas páginas existentes |
| `shopfloor.php` | Integração nas páginas existentes |
| `tests/central_printing_concurrency_test.php` | Validação automatizada |
| `tests/central_printing_http.py` | Validação automatizada |
| `tests/central_printing_test.php` | Validação automatizada |
| `windows-print-agent/GesTisserPrintAgent.cs` | Instalação e serviço Windows |
| `windows-print-agent/Install.ps1` | Instalação e serviço Windows |
| `windows-print-agent/Uninstall.ps1` | Instalação e serviço Windows |

Pacote Windows compilado (EXE, fonte e instaladores): https://github.com/gestaoindustrialit/GesTisser/actions/runs/40336234760/artifacts/12656703144 . Artefacto retido até 9 de novembro de 2026; o instalador também pode compilar os fontes posteriormente. SHA-256 do ZIP: `afee1b58f75e5810d71cfa4387b56251e3e3ed6360f76bd733e528115def8900`.
