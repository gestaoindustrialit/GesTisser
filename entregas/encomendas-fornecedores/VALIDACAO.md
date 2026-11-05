# Validação — 5 de novembro de 2026

Commit: 8e940d0e948d2fb7c2cfc4a6718e35b04cf5bad2
Runtime confirmado: PHP 7.0.33.

## Bateria PHP

Comando: python3 scripts/test_php70.py (após ativar o runtime PHP 7.0).
Resultado: 108 testes aprovados, zero falhas e zero testes ignorados.
Sintaxe: 360 ficheiros PHP aprovados, zero erros.
Inclui o novo teste de PDF multipágina, integridade do arquivo, estados e idempotência de envio; o teste de receções parciais e todos os testes existentes do projeto.

## Testes de integração e navegador

Comando: python3 tests/purchase_order_http.py
Instalação, base de dados e servidor PHP descartáveis; Chromium com Playwright; servidor SMTP de captura exclusivamente em 127.0.0.1; fallback mail() desativado no teste. Nenhum email foi enviado a fornecedores reais.

Aprovado:

- Instalação e migrações reais, autenticação e formulário de encomenda.
- Várias linhas, adicionar/remover, seleção de artigos, unidades e preços, guardar e recarregar.
- Receção parcial e seleção automática da encomenda no armazém; bloqueio das linhas após receção.
- Layout a 1440, 800 e 390 píxeis, sem erros JavaScript.
- Envio SMTP/MIME real para a captura local, acentuação da mensagem e PDF anexo.
- Igualdade byte a byte entre PDF enviado, PDF arquivado e PDF descarregado; hash SHA-256.
- Extração do PDF com pdftotext: fornecedor, duas linhas de artigos e moeda.
- Submissão repetida sem email duplicado; CSRF e destinatário inválidos rejeitados.
- Acesso ao arquivo protegido contra utilizadores anónimos e autenticados sem permissão de compras; envio sem permissão bloqueado.
- Receção em excesso rejeitada sem alterar stock. Erro na segunda linha reverte também os efeitos da primeira.
- Receção do restante: estado Recebida e 100% entregue.
- Artigo inexistente rejeitado e rollback do cabeçalho, linhas e numeração.
- Data inexistente e preço negativo rejeitados.
- Falha de SMTP arquivada como Falhou, sem falso sucesso.
- Integridade SQLite e ausência de erros/avisos PHP nos fluxos exercitados.

Verificação de Git: git diff --check sem erros. Conteúdo dos 12 ficheiros confirmado por SHA Git com os blobs publicados. Árvore publicada e local: 49c8e6c3b9552f20f2a4aee4be07172cc43bb434. Branch main remota verificada no commit acima.

Limite da validação: o transporte SMTP do servidor de produção e a entrega na caixa de correio de um fornecedor real não foram testados. O teste cobre o transporte SMTP local, o conteúdo, o anexo e o arquivo.
