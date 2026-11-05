# Tintas — integração com o cadastro existente

Publicado na main: f735a25e05632d1cadde429f62bdf3250616b49f
https://github.com/gestaoindustrialit/GesTisser/commit/f735a25e05632d1cadde429f62bdf3250616b49f

Este pacote SUBSTITUI o pacote anterior das tintas. Inclui os campos legados e esta afinação; não é necessário aplicar o ZIP anterior de tintas primeiro. Parte da versão f37bb67 (encomendas corrigidas e criação de artigos em modal).

## O que foi afinado

- O tipo de tinta vem apenas da tabela existente das configurações: Água, Solvente, UV e quaisquer outros tipos aí configurados. Não são criados tipos ou ícones duplicados.
- A modal utiliza esse catálogo. Na ficha mantém-se o seletor já existente. Foi retirado o seletor independente de Tinta de água: essa indicação é calculada a partir do tipo selecionado.
- Os indicadores legados de água/solvente são mantidos coerentes com o tipo. Importar Não em Tinta Água não converte UV em solvente. Dados de importação que contradizem um tipo existente são rejeitados, em vez de alterar silenciosamente a classificação.
- Reutilizam-se o material, o seu ID, código, tipo, característica, unidade e relações existentes. Uma característica como COR: DOURADO | PANTONE: 871 fornece os valores estruturados para esse mesmo material.
- A migração preenche campos vazios e corrige o preenchimento genérico reconhecível da versão anterior com os dados da característica. Regista as alterações numa tabela de reconciliação. Valores explícitos divergentes ficam conservados e sinalizados para revisão na lista de materiais.
- Quando a característica contém cor/Pantone, os campos mostram essa origem e ficam protegidos contra edição contraditória. O botão Usar cor e Pantone da característica permite confirmar os dados a reutilizar. Pode alterar-se a característica selecionada pelo procedimento habitual.
- Stocks, movimentos, lotes, encomendas e IDs internos não são recalculados, reatribuídos ou fundidos. Não se juntam materiais apenas por terem nomes/cores semelhantes.
- Os campos Código, Cor, Pantone, Água, Último preço e ID antigo continuam disponíveis. O ID antigo não substitui o ID interno nem é inventado.
- A importação continua a preservar zeros, código, ID antigo, ficheiro e linha de origem. Foi acrescentada a coluna opcional tipo_tinta, que aceita o código ou nome de um tipo existente. Colunas de tipo/característica ausentes ou vazias não apagam associações existentes.

## Aplicar

1. Fazer cópia de segurança dos ficheiros e da base de dados.
2. Copiar TODOS os 15 ficheiros de aplicação abaixo, mantendo as pastas. Os ficheiros de tests/ são para validação e são opcionais no servidor.
3. Abrir ERP > Matérias-primas ou Compras a fornecedores. A migração independente executa uma única vez a reconciliação, mesmo que o pacote de tintas anterior já tenha sido instalado. Não é necessário executar SQL manualmente nem substituir erp_migrations.php.
4. Rever eventuais avisos Referências de tintas a confirmar na lista de materiais. Quando apropriado, abrir a ficha e usar o botão de reutilização dos dados da característica antes de guardar.
5. Atualizar o navegador para carregar os scripts novos.

## Validação

PHP 7.0.33: 111 testes aprovados, 370 ficheiros PHP sem erros de sintaxe.

Testes com um material equivalente ao exemplo 504355: característica COR: DOURADO | PANTONE: 871, tipo Água já configurado, ID interno e stock existentes. Confirmados cor/Pantone, conservação do código e tipo, catálogo com os mesmos IDs/nomes/ícones, stock 19,690 inalterado e ausência de duplicações.

Testes unitários adicionais: UV prevalece sobre indicadores antigos contraditórios, solvente deriva do catálogo, material do tipo TINTA é reconhecido, base desconhecida não é inventada, divergência explícita fica para revisão, migração é idempotente e não altera stocks/movimentos.

Chromium/HTTP: modal com os tipos existentes; indicação de água sem segundo controlo editável; edição com UV; ficha do material existente; botão de reutilização da característica; importação/reimportação; conservação das referências e auditoria; encomendas, PDF, email e receções parciais. Sem avisos PHP/erros JavaScript nos fluxos testados. SMTP exclusivamente local, sem emails externos.

Não houve acesso nem alteração direta à base de dados de produção. Os dados são reconciliados após aplicar o pacote e abrir o ERP. Divergências não resolvidas exigem revisão na ficha.

## Ficheiros de aplicação

- app/Services/ArticleSpreadsheet.php
- app/Services/InkMaterialFields.php
- app/Services/InkMaterialSchema.php
- app/Services/PurchaseOrderDocument.php
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
