# Colorimetria e tintas preparadas

Módulo PHP 7.0.33 / SQLite, aberto numa modal Bootstrap pelo menu ERP e pelo ícone **Tintas / Cores** junto às etiquetas do Shopfloor. Nas operações de produção existe um atalho de consumo que transporta a OF para o recipiente.

## Navegação na modal

O Shopfloor permanece carregado. Pesquisa de cores, preparação, stock, histórico, biblioteca e editores navegam dentro da mesma janela, através de fragmentos autenticados carregados por `fetch`. O botão do cabeçalho tem apenas o ícone, tooltip e nome acessível, com as dimensões dos atalhos vizinhos.

`colorimetry.php` funciona como controlador de fragmentos (`modal=1`). Os links antigos sem esse parâmetro encaminham para o Shopfloor e abrem a modal na vista solicitada. A URL principal e a OF selecionada não são alteradas pela navegação interna. A impressão central reutiliza o spool; o PDF pode ser visualizado e descarregado na própria modal. Os anexos são descarregados sem substituir o Shopfloor.

As vistas usam cartões de cores e recipientes, biblioteca em tabela, componentes com massa e unidade na mesma linha, totais e conferência de recipientes em tempo real e operações apresentadas por contexto. No telemóvel, a janela ocupa o ecrã e o conteúdo rola dentro dela. As permissões, CSRF, idempotência e transações existentes mantêm-se no backend. Erros de validação e ligação preservam os dados introduzidos; sair de um formulário alterado pede confirmação.

## Ativação

1. Distribuir os ficheiros do módulo juntamente com as integrações indicadas no relatório de alterações. Manter o procedimento habitual de backup da instalação.
2. A migração aditiva `migrations/colorimetry.sql` é aplicada transacionalmente por `ColorimetryService::migrate()` no primeiro acesso ao Shopfloor, à gestão de utilizadores ou ao módulo. A marca é guardada em `erp_ink_schema_migrations`. A repetição preserva os dados existentes; não são criadas receitas demonstrativas.
3. Na gestão de utilizadores, ativar **Colorista**. Conceder **Criar fórmulas de tinta** e **Aprovar fórmulas de tinta** apenas a quem desempenha essas funções. As três opções reutilizam `erp_user_permissions`; os administradores têm acesso independentemente delas.
4. Confirmar as matérias-primas existentes, unidades `g` ou `kg`, saldos, lotes, armazéns e etiquetas das latas. Não há conversão de litros para massa. Os componentes são baixados do inventário na pesagem confirmada. Quando o stock tem latas identificadas, é obrigatório selecionar a lata correspondente; o seu peso é atualizado na mesma transação.
5. Em **Configuração ERP → Impressão**, executar a preparação do catálogo existente para acrescentar o modelo **Tinta preparada · 100 × 50 mm** (`prepared_ink_label`). Associar impressora, agente, formato e posto pelos mecanismos existentes. O renderer admite formatos maiores e DPI 203, 300 e 600. Sem impressão central ativa, o módulo disponibiliza PDF 100 × 50 mm.

## Operação

- Criar ou reutilizar uma cor. Criar uma fórmula com componentes do ERP e massas em g ou kg. Associar opcionalmente PDF, PNG ou JPEG de referência (até 5 MB); a validação MIME reutiliza `UploadService` e os ficheiros são servidos por endpoint autenticado.
- Uma fórmula aprovada conserva permanentemente a composição, a versão, o aprovador e as referências. Alterações criam nova versão em desenvolvimento. O responsável pela criação pode preparar amostras de rascunhos; o preparador comum usa fórmulas aprovadas.
- Selecionar a fórmula, indicar a quantidade prevista, consultar o cálculo e registar todas as pesagens reais e respetivos stocks de origem. As pesagens não são preenchidas com os valores calculados. Distribuir a massa real por recipientes; a soma tem de coincidir exatamente. Os recipientes começam bloqueados até à avaliação.
- Cada recipiente tem saldo e composição próprios. As correções acrescentam componentes exclusivamente ao restante físico e exigem nova avaliação. A proposta de fórmula cria um rascunho, sem modificar a receita original.
- Consumos, perdas, devoluções e acertos são eventos novos. A devolução identifica o consumo de origem e restaura a composição efetivamente retirada, mesmo após afinações do restante. Os acertos e a alteração administrativa de estado exigem administrador e motivo.
- O código de barras Code128 identifica exclusivamente o recipiente. Um leitor que introduza texto permite pesquisar esse código em **Stock de Tintas**; a consulta exige autenticação e permissão. As etiquetas incluem cor, Pantone, mistura, versão, recipiente, lote, massa inicial, data e colorista. A geração ou reimpressão é auditada e não cria stock.
- Os saldos de tinta preparada aparecem também no inventário WMS, separados das matérias-primas, através de `item_type=prepared_ink`. Os consumos com OF reutilizam `erp_production_consumptions`, com `stock_unit_type=PREPARED_INK` e unidade kg. Não executar uma segunda baixa manual do mesmo consumo no fluxo de matérias-primas.

## Integridade e limites

Massas guardadas em miligramas inteiros, com precisão de 1 mg e limite de 1000 kg por quantidade/operação. A distribuição conserva a massa total. São usadas transações `BEGIN IMMEDIATE`, queries parametrizadas, CSRF e chaves persistentes por utilizador para impedir submissões duplicadas. Uma chave repetida com outros dados é recusada.

Preparações, eventos, anexos e identidades de recipientes têm proteção de imutabilidade na base de dados. Os históricos não são eliminados pelo módulo. As fórmulas e pesagens são snapshots independentes das alterações posteriores à biblioteca. As matérias-primas conservam os movimentos no inventário existente; os eventos das tintas guardam os movimentos de origem e a ligação à produção.

## Validação

```sh
source /workspace/gestisser-env/activate.sh
php -v
python3 scripts/test_php70.py
python3 tests/colorimetry_http.py
```

O segundo comando de testes requer rede local para o servidor PHP, Playwright e Chromium. Trabalha numa instalação temporária, sem modificar a base de dados de trabalho ou produção. Os caminhos do Chromium e do Bootstrap no teste de navegador correspondem ao ambiente de validação disponibilizado.

Antes da utilização em produção, validar receitas e permissões com a TISSER, a legibilidade da etiqueta e do código no equipamento físico, as filas e DPI das Zebra e os fluxos num tablet Android real. A integração de balanças e a importação Excel/CSV de fórmulas ficam para uma fase futura, conforme solicitado.

## Validação da revisão das vistas

- PHP 7.0.33: 107 testes aprovados e 354 ficheiros PHP sem erros de sintaxe.
- Navegador: abertura e fecho da modal, Escape, botão Voltar, navegação sem recarregar a página principal, pesquisa e filtros, preparação com dois recipientes, pesagens reais, erro de stock com preservação dos campos, avaliação, consumo, correção, PDF dentro da modal e links antigos.
- Administração: criação de cor e fórmula e aprovação dentro da modal; tabela compacta de componentes e adição/remoção de linhas.
- Layout verificado em desktop, tablet de 800 px e telemóvel de 390 px. Impressora e tablet físicos continuam a requerer validação na instalação.

Ficheiros da revisão: `assets/colorimetry.css`, `assets/js/colorimetry.js`, `colorimetry.php`, `includes/colorimetry_view_helpers.php`, `includes/colorimetry_views.php`, `partials/colorimetry_modal.php`, `partials/header.php`, `partials/footer.php`, `shopfloor.php`, `prepared_ink_label.php`, `print_label.php`, os testes HTTP/navegador e este guia. Não há novas migrações nesta revisão das vistas.

## Relatório da implementação inicial

Validação concluída em PHP 7.0.33: suite completa de 107 testes aprovada, 351 ficheiros PHP sem erros de sintaxe; testes HTTP e navegação Android emulada aprovados, incluindo preparação em vários recipientes, consumo, correção, geração/repetição de PDF e layout de 800 e 390 px. As alterações finais de navegação foram revalidadas nos testes de colorimetria, inventário e Shopfloor. `node --check` e `git diff --check` aprovados. Não foi efetuada instalação em produção.

Tabelas novas: `erp_ink_color_details`, `erp_ink_formulas`, `erp_ink_mixes`, `erp_ink_containers`, `erp_ink_events`, `erp_ink_requests`, `erp_ink_formula_attachments` e `erp_ink_schema_migrations`.

Tabelas reutilizadas: `users`, `erp_permissions`, `erp_role_permissions`, `erp_user_permissions`, `erp_colors`, `erp_ink_types`, `erp_units`, `erp_raw_materials`, `erp_raw_material_ink_labels`, `erp_customers`, `erp_finished_products`, `erp_warehouses`, `erp_stock_balances`, `erp_stock_movements`, `erp_production_orders`, `erp_production_consumptions` e o catálogo/spool de impressão `erp_print_*`, com as associações de postos existentes.

A cópia fornecida referencia os assets globais `assets/mapper-theme.css` e `assets/mapper-layout.js`, que já estão ausentes do repositório. O módulo foi testado com os assets disponíveis; o aspeto global deverá ser confirmado na instalação com o tema completo.

Ficheiros alterados ou criados:

- `app/Services/CentralPrintingSetup.php`
- `app/Services/ColorimetryService.php`
- `app/Services/InventoryService.php`
- `app/Services/PreparedInkLabelRenderer.php`
- `app/Services/PrintLabelRenderer.php`
- `app/routes.php`
- `assets/js/colorimetry.js`
- `colorimetry.php`
- `docs/colorimetry.md`
- `erp.php`
- `includes/printing_admin_actions.php`
- `includes/printing_admin_panel.php`
- `ink_formula_attachment.php`
- `migrations/colorimetry.sql`
- `partials/header.php`
- `prepared_ink_label.php`
- `print_label.php`
- `shopfloor.php`
- `tests/central_printing_setup_test.php`
- `tests/colorimetry_browser.js`
- `tests/colorimetry_http.py`
- `tests/colorimetry_test.php`
- `tests/support/colorimetry_worker.php`
- `users.php`
