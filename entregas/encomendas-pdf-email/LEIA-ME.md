# Correção de PDF e email das encomendas

Commit publicado na main: d93aa8327d67672418230b25c322c6db408149bc
https://github.com/gestaoindustrialit/GesTisser/commit/d93aa8327d67672418230b25c322c6db408149bc

Pacote completo de atualização a partir da versão f37bb67, incluindo as afinações das tintas e esta correção de PDF/email. Substitui os pacotes anteriores de tintas. Contém 18 ficheiros de aplicação e 6 de testes. Inclui o auxiliar nativo ProductionDossierPdf.php já existente no repositório, para garantir a versão necessária ao gerador.

## Causa reproduzida e correção

Foi reproduzido o erro HTTP 500 no PHP 7.0 numa instalação descartável sem vendor/autoload.php: a geração de PDF exigia a biblioteca mPDF, e a falta desse ficheiro era fatal tanto ao abrir o PDF como ao preparar o anexo de email.

A encomenda passa a utilizar um gerador PDF A4 autónomo, com as rotinas nativas dos relatórios do ERP. Não precisa de Composer, mPDF, ficheiros externos de fontes ou pasta temporária de PDF. Mantém logótipo local quando disponível, identificação da empresa e fornecedor, número/data, entrega prevista, quantidades, unidades, preço, Pantone, código/descrição, cor, totais, observações e paginação.

Ver PDF e Enviar email passam a estar na mesma célula da lista, junto de Editar. Enviar email abre a encomenda com a composição do email já expandida.

O email continua a utilizar o transporte/SMTP configurado no ERP. Se a preparação do PDF falhar, o utilizador regressa à encomenda com assunto e mensagem conservados e uma referência de diagnóstico. Não é indicado um envio bem-sucedido nem criado um envio falso quando a preparação falha. Falhas do transporte continuam arquivadas como Falhou.

Os PDFs já arquivados continuam a ser devolvidos pelos bytes e hash guardados, sem regeneração. O anexo dos novos envios corresponde exatamente ao PDF arquivado.

## Aplicar

1. Fazer cópia de segurança dos ficheiros e da base de dados.
2. Copiar TODOS os ficheiros de aplicação abaixo, mantendo os caminhos. É essencial incluir app/Services/PurchaseOrderPdf.php e o auxiliar ProductionDossierPdf.php. Não copiar apenas erp.php. Os ficheiros de tests/ são para validação e são opcionais no servidor.
3. Manter as dependências existentes: outros módulos podem usá-las, mas o PDF da encomenda já não depende de vendor.
4. Abrir Compras a fornecedores e atualizar o navegador. Abrir Ver PDF junto de Editar. Usar Enviar email para abrir a composição da mensagem; confirmar o destinatário e enviar quando pretendido.
5. Verificar o estado e o PDF no Histórico de envios. O envio efetivo em produção depende da configuração de correio/SMTP e dos serviços externos ativos no ERP.

O pacote mantém a reconciliação independente das tintas: reutiliza tipos/ícones/características existentes, preserva IDs e stocks, e sinaliza divergências para revisão. Não é necessário executar SQL manualmente nem substituir erp_migrations.php.

## Validação

PHP 7.0.33: 112 testes aprovados; 372 ficheiros PHP sem erros de sintaxe.

- Regressão reproduzida antes da correção com erro fatal por falta de vendor/autoload.php.
- Novo teste autónomo copia apenas três ficheiros do gerador para uma pasta sem vendor e gera PDF válido, incluindo uma encomenda com 150 linhas em múltiplas páginas.
- Teste HTTP/Chromium executado por omissão sem vendor: PDF junto de Editar devolve application/pdf e bytes %PDF-; Enviar email abre a composição; SMTP local recebe mensagem MIME com anexo.
- PDF enviado, arquivado e descarregado iguais byte a byte; conteúdo extraído com pdftotext e layout de amostra inspecionado visualmente.
- Erro de composição deliberado devolve mensagem controlada e conserva assunto/texto no regresso à encomenda, sem enviar email ou criar um falso envio.
- Encomenda inexistente devolve 404; destinatário inválido e CSRF rejeitados; permissões, prevenção de duplicados e falhas de SMTP validadas.
- Todos os testes existentes de encomendas, receções, stocks, importações, tintas e outros módulos aprovados.

Comandos: python3 scripts/test_php70.py e python3 tests/purchase_order_http.py com o runtime PHP 7.0 ativo. O teste HTTP funciona sem vendor por omissão; PURCHASE_TEST_WITHOUT_VENDOR=0 permite comparar com dependências instaladas.

Não foram consultados os logs nem alterada a base de dados do servidor de produção. A reprodução foi numa instalação descartável. Não foram enviados emails a fornecedores reais; o teste utilizou apenas SMTP local. O deploy no servidor fica por aplicar.

## Ficheiros de aplicação

- app/Services/ArticleSpreadsheet.php
- app/Services/InkMaterialFields.php
- app/Services/InkMaterialSchema.php
- app/Services/ProductionDossierPdf.php
- app/Services/PurchaseOrderDocument.php
- app/Services/PurchaseOrderPdf.php
- app/Services/RawMaterialSpreadsheet.php
- assets/purchase-order-editor.js
- erp.php
- erp_material.php
- erp_purchase_document.php
- erp_purchase_item.php
- erp_raw_material_template.php
- partials/ink-material-fields.php
- partials/ink-material-list.php
- partials/purchase-order-item-modal.php
- partials/purchase-order-lines.php
- partials/purchase-order-send.php
