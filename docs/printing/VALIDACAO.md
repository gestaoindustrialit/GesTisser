# Plano de validação e aceitação

Executar progressivamente na produção com a funcionalidade inicialmente desligada, como solicitado. Usar uma janela curta para a preparação da base. Não utilizar nem alterar dados de OF, artigos, stocks ou RH para testes de impressão; usar etiquetas existentes e cópias de documentos autorizadas.

| Cenário | Procedimento / resultado esperado | Validação neste ambiente |
|---|---|---|
| Preservação | Preparar duas vezes; comparar impressoras e default_printer_id; snapshot restaurável em base isolada | Teste PHP/SQLite aprovado |
| Credenciais/permissões | Token incorreto e acesso de outro agente recusados; administrador gere configuração; operador não deve gerir agentes | Isolamento, CSRF e autenticação testados por HTTP local; produção pendente |
| Concorrência | Dois processos reclamam um único trabalho: só um o recebe | Teste multiprocesso PHP aprovado |
| Reserva expirada | Interromper antes de processing; lease expira e novo token invalida o antigo | Teste aprovado |
| Falha após barreira | Interromper após processing e antes da resposta: unknown; sem retry automático | Teste aprovado |
| Falha após spooler | Cortar Internet após aceitação, reiniciar serviço; reconciliar diário, sem segunda impressão | Lógica servidor testada; ensaio Windows pendente |
| Retenção | Limpar conteúdos terminais com 30 dias; conservar metadados e pendentes | Teste aprovado |
| Uma Zebra USB | Instalar serviço, confirmar fila sob conta SYSTEM, criar teste ZPL 100×50 à resolução real | Pendente no PC |
| Várias Zebras no PC | Autorizar cada fila local; direcionar testes distintos; verificar nomes e drivers | Pendente no PC |
| Vários PCs | Agentes separados, credenciais únicas; jobs isolados por agente | Serviço testado; PCs pendentes |
| HP A4 / PDF | Instalar SumatraPDF oficial; testar driver, A4, orientação, 1 e 2 cópias sem diálogo | Geração PDF testada; impressão pendente |
| Multiposto | Duas filas por posto e uma fila em dois postos; default único por tipo/formato | Migração/relação/default testados; UI HTTP local testada; tablet pendente |
| Dois modelos / formato | Rolo e tinta 100×50 na mesma Zebra, campos e códigos corretos | Renderização testada; dimensão física pendente |
| Seleção automática/manual | Uma opção não exige seletor; várias oferecem predefinida e autorizadas | Serviço testado; tablet pendente |
| Offline / agente parado | Trabalho permanece pending; alternativa compatível disponível ao administrador | Serviço testado; falha física pendente |
| Transferência | Apenas pending; novo destino com mesma linguagem/formato e DPI para ZPL; tentativa em reservado recusada | Testado |
| Cancelar / reimprimir | Cancelar pendente; reimprimir terminal com confirmação, novo ID e auditoria | Testado |
| Reinício agente / PC | Desligar em cada estado; verificar diário durável, sem duplicações | Pendente Windows |
| Android Redmi Pad 2 | No Shopfloor, pesquisar rolo/tinta e etiqueta de produto A4; enviar sem diálogo do navegador | Pendente tablet |
| Compatibilidade anterior | Com sistema desligado, páginas e botões anteriores continuam disponíveis | Testes de regressão selecionados aprovados |

Não marcar os critérios de aceitação globais como concluídos antes dos ensaios físicos e da confirmação do alojamento real. O agente mantém `spooler_accepted` como resultado honesto sem confirmação física. O EXE foi compilado em runner Windows e passou os testes locais de configuração, DPAPI, argumentos e diário. O instalador também compila os fontes localmente. A execução em Windows 10 com drivers reais continua pendente.

Executar testes no ambiente de desenvolvimento:

```sh
source /workspace/gestisser-env/activate.sh
php -v
python3 scripts/test_php70.py central_printing_test.php central_printing_concurrency_test.php work_center_capacity_test.php shopfloor_page_sections_test.php product_label_feature_test.php raw_material_roll_label_test.php raw_material_ink_label_test.php
```

O runner usa cópias isoladas e bases sintéticas. Não equivale a um teste com os dados de produção. Executar também os testes HTTP autenticados e o diagnóstico TLS no alojamento após publicação.

Teste HTTP reproduzível numa cópia descartável (requer PHP 7.0.33 no PATH e sockets loopback): `python3 tests/central_printing_http.py`. Inclui 36 verificações, instalação de uma base sintética, autenticação web, configuração, proteção CSRF, migração, inventário, reserva, download, transições e revogação. Para testar a API em loopback, um router exclusivo da cópia descartável simula o indicador HTTPS do servidor; não é publicado e não comprova certificados TLS reais. A API normal é testada separadamente para recusar HTTP sem TLS. O fixture inicializa os esquemas existentes das máquinas/custos através dos migradores já disponíveis; não altera esses módulos na implementação.
