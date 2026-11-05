# Tintas — campos legados e rastreabilidade

Commit publicado na main: 4d8dd0ca0c9e3eb97e301b5871a7503a81408fc5
https://github.com/gestaoindustrialit/GesTisser/commit/4d8dd0ca0c9e3eb97e301b5871a7503a81408fc5

Este pacote atualiza a versão f37bb67, que já inclui a correção das encomendas antigas e a criação de artigos em modal. Contém 15 ficheiros de aplicação e 4 ficheiros de testes. Não contém dados de fornecedores nem o ficheiro geral de dados iniciais/migrações.

## Aplicação

1. Fazer cópia de segurança dos ficheiros atuais e da base de dados.
2. Copiar TODOS os ficheiros de aplicação abaixo para os mesmos caminhos. Manter as dependências existentes e as correções anteriores das encomendas. Os ficheiros de tests/ são opcionais no servidor.
3. Abrir ERP > Matérias-primas ou Compras a fornecedores. A pequena migração independente acrescenta os campos necessários automaticamente, conservando os IDs dos materiais. Não é necessário executar SQL nem substituir erp_migrations.php.
4. Atualizar o navegador para carregar os novos scripts.

## Campos de cada tinta

- Código: mantém a referência do cadastro/ERP antigo.
- Cor: nome como AMARELO, AZUL, AMARELO ESVRD.
- Pantone: texto, mantendo referências como 000, 072 e 4287.
- Tinta de água: Sim/Não, coerente com a classificação de tinta de água existente.
- Último preço: valor decimal no cadastro; editável e importável.
- ID no ERP antigo: referência original em texto, independente do ID interno atual. Opcional quando não é conhecido; não é inventado.

Na modal de criação, assinalar Este artigo é uma tinta. Cor e Pantone tornam-se obrigatórios e o preço unitário é guardado também como último preço. O artigo é selecionado na encomenda sem recarregar a página.

A ficha de material contém os mesmos campos e o catálogo apresenta a tabela Tintas — manutenção de cores, com Código, Cor, Pantone, Tinta água, Últ. preço e ID antigo. Os preços respeitam as permissões do utilizador. A seleção de artigos mostra Pantone e a indicação de água quando aplicável. O PDF usa a cor/Pantone próprios do artigo, conservando esses dados no snapshot arquivado.

## Importação

O modelo Excel foi atualizado. São aceites também os cabeçalhos antigos:
Código (Sint.);Cor;Pantone;Tinta Água;Últ. Preço;ID

Se o ficheiro antigo não tiver Descrição, utiliza-se a Cor. Nas tintas novas sem grupo/unidade indicados, utiliza-se Subsidiário e KG; numa referência existente, mantém-se a unidade configurada. A importação tem pré-visualização e confirmação.

Códigos, Pantones e IDs mantêm zeros à esquerda. Também são preservados formatos Excel simples de zeros, como 000. Os preços aceitam vírgula ou ponto decimal. Reimportações atualizam pelo código e conservam a associação ao ID antigo. Um ID antigo já associado a outro código é rejeitado para evitar uma ligação incorreta.

Cada importação de tinta regista auditoria com utilizador/data, valores anteriores e finais, nome do ficheiro e linha de origem. As referências recebidas não são convertidas no ID interno do novo ERP.

## Validação

PHP 7.0.33: 110 testes aprovados e 369 ficheiros PHP sem erros de sintaxe.

Verificados em Chromium e instalação PHP/SQLite descartável: criação da tinta na modal, conservação da encomenda, tabela com os campos legados, importação do formato antigo sem Descrição, reimportação sem duplicados, edição preservando 072/00009, auditoria com ficheiro e linha original, coerência dos campos na base de dados e layouts a 1440/800/390 px.

Inclui ainda validação de migração independente idempotente, referências antigas preservadas, conflitos de ID, formatos Excel 000, e todos os testes existentes de encomendas, PDF, emails e receções parciais. HTTP/SMTP validado apenas localmente, sem emails externos. Não foi efetuado deploy no servidor de produção.

Comandos: python3 scripts/test_php70.py e python3 tests/purchase_order_http.py, com PHP 7.0 ativo.

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
