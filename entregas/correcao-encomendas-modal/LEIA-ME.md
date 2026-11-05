# Correção de encomendas e criação de artigos em modal

Publicado na main: f37bb677c1035430c7c0f70b2b08e8fa0f0f18ff
https://github.com/gestaoindustrialit/GesTisser/commit/f37bb677c1035430c7c0f70b2b08e8fa0f0f18ff

Este pacote atualiza a versão anterior das encomendas (8e940d0). Inclui 8 ficheiros de aplicação e 3 de testes.

## Aplicar

1. Fazer cópia de segurança da instalação e da base de dados SQLite.
2. Copiar TODOS os ficheiros de aplicação abaixo para os mesmos caminhos na instalação. Aplicar em conjunto; não copiar apenas erp.php. Os ficheiros de tests/ são opcionais no servidor.
3. Abrir Compras a fornecedores. A nova versão de esquema 2026110502 atualiza automaticamente a tabela antiga: acrescenta os campos em falta e copia os valores de line_no, ordered_quantity e unit_cost. Mantém as linhas, os seus IDs e as ligações aos movimentos/receções. Não é necessário executar SQL manualmente.
4. Guardar a encomenda. Em cada linha existe agora Criar artigo, visível a utilizadores com permissões de compras e dados mestre.

## Ficheiros de aplicação

- app/Services/PurchaseOrderImportService.php
- app/Services/PurchaseOrderLineSchema.php
- assets/purchase-order-editor.js
- erp.php
- erp_migrations.php
- erp_purchase_item.php
- partials/purchase-order-item-modal.php
- partials/purchase-order-lines.php

## Comportamento

- As instalações antigas conservam os campos obrigatórios originais. A gravação manual e a importação por PDF preenchem também esses campos.
- Uma falha ao guardar conserva fornecedor, datas, referência, observações, artigos, quantidades e preços no formulário, permitindo corrigir e voltar a guardar.
- Criar artigo abre uma modal sem sair da encomenda. Suporta matérias-primas/tintas/outros e artigos acabados, código manual ou automático, categoria, descrição, unidade e preço.
- Depois de criar, o artigo fica selecionado na linha de origem e disponível nas restantes linhas. As outras linhas e o cabeçalho da encomenda mantêm-se.
- Código duplicado ou outro erro é mostrado dentro da modal, mantendo os dados introduzidos. Cancelar deixa a encomenda intacta.
- O artigo é criado no cadastro imediatamente; fechar a encomenda sem a guardar não elimina o artigo criado.

## Validação

PHP 7.0.33: 109 testes aprovados; 364 ficheiros PHP sem erros de sintaxe.

Testes de integração: instalação descartável com tabela antiga contendo campos NOT NULL, marcador de migração anterior e uma receção existente. Confirmados atualização automática, conservação dos IDs e ligações, gravação de novas linhas, receções parciais e PDF/email.

Chromium: criação e seleção do artigo na modal, código duplicado, cancelamento, conservação de fornecedor/quantidades/observações e layouts a 1440, 800 e 390 px. Sem erros JavaScript.

Também verificados: importação PDF na tabela antiga; recuperação de rascunho após erro; criação de artigo acabado com código automático; CSRF e permissões; email MIME e arquivo idênticos, SMTP exclusivamente local; rejeição de excessos e rollback de receções inválidas.

Comandos: python3 scripts/test_php70.py e python3 tests/purchase_order_http.py, com PHP 7.0 ativo. Não foram enviados emails a fornecedores reais nem efetuada atualização do servidor de produção.
