# Encomendas a fornecedores — ficheiros a atualizar

Commit publicado na branch main: 8e940d0e948d2fb7c2cfc4a6718e35b04cf5bad2
https://github.com/gestaoindustrialit/GesTisser/commit/8e940d0e948d2fb7c2cfc4a6718e35b04cf5bad2

## Instalação

1. Fazer cópia de segurança dos ficheiros atuais e da base de dados SQLite.
2. Copiar os 8 ficheiros de aplicação abaixo para a raiz da instalação, mantendo as pastas. Os 4 ficheiros em tests/ servem apenas para validação.
3. Manter a pasta vendor/ existente: o PDF utiliza mPDF, já declarado no composer.json do projeto. As dependências devem estar instaladas com o destino PHP 7.0.33.
4. Abrir ERP > Compras a fornecedores. A migração automática cria a tabela de arquivo de emails e o índice, preservando as encomendas e movimentos existentes. Não é necessário executar SQL manualmente.
5. Confirmar o email na ficha do fornecedor e a configuração de correio/SMTP do ERP. O envio externo tem de estar ativo no ambiente de produção.

## Ficheiros de aplicação

- app/Services/PurchaseOrderDocument.php
- app/Services/PurchaseOrderEmail.php
- app/Services/PurchaseReceiptService.php
- erp.php
- erp_migrations.php
- erp_purchase_document.php
- partials/purchase-order-lines.php
- partials/purchase-order-send.php

## Ficheiros de testes

- tests/purchase_order_browser.js
- tests/purchase_order_document_test.php
- tests/purchase_order_http.py
- tests/purchase_receipt_service_test.php

## Utilização

- Criar uma encomenda, selecionar fornecedor e adicionar tantas linhas de artigos quantas necessárias. Código, descrição, unidade e preço são carregados do cadastro. A quantidade e o preço são editáveis antes das receções.
- Guardar encomenda. Depois ficam disponíveis Ver PDF e Enviar ao fornecedor, com o ícone de avião de papel.
- O envio permite editar destinatário, assunto e mensagem. O texto tem um modelo inicial. O PDF utiliza os dados guardados; guardar alterações antes de enviar.
- Cada tentativa conserva destinatário, assunto, mensagem, utilizador, data, estado, erro e o PDF exato com hash de integridade. Uma submissão repetida com a mesma chave não volta a enviar. Uma nova abertura do formulário permite um reenvio intencional, que cria novo registo no histórico.
- A ligação Registar receção parcial abre o armazém com a encomenda selecionada. Indicar as quantidades recebidas por linha (zero para linhas não recebidas).
- As receções atualizam stock e o estado Parcial/Recebida automaticamente. Quantidades superiores ao que falta são rejeitadas. Os artigos e o fornecedor ficam protegidos após existirem receções; o histórico continua disponível.
- A percentagem global é a média das percentagens entregues por linha, para permitir encomendas com unidades diferentes. Exemplo: uma linha a 50% e outra a 0% = 25% global.

## Âmbito da entrega

Os ficheiros foram publicados na branch main. A atualização do servidor ERP é feita copiando os ficheiros ou pelo processo de deploy existente. Esta entrega não efetuou deploy no servidor de produção nem enviou emails a fornecedores reais.

Consultar VALIDACAO.md para os testes efetuados. SHA256SUMS.txt permite conferir os 12 ficheiros do pacote.
