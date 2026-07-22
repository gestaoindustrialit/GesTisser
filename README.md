# GesTisser

GesTisser é uma aplicação web de gestão integrada para a Tisser, combinando os módulos de Recursos Humanos existentes com uma camada ERP orientada à produção.

## Âmbito funcional

### Recursos Humanos
- Gestão de presenças e picagens.
- Comunicação e acompanhamento de ausências.
- Administração de colaboradores.
- Organização de equipas e turnos.

### ERP Industrial
- Clientes e dados comerciais.
- Artigos, unidades, cores e características técnicas.
- Ordens de fabrico (OFs) com estados e quantidades.
- Expedição e controlo de entregas.
- Operações produtivas configuráveis por centro de trabalho.
- Shopfloor para iniciar OFs na operação selecionada.

## Executar localmente

Este repositório inclui um protótipo estático, sem dependências externas:

```bash
python3 -m http.server 4173
```

Depois abra `http://localhost:4173` no navegador.

## Estrutura

- `index.html` — interface principal com navegação de módulos ERP/RH.
- `styles.css` — aparência inspirada no ecrã legado de produção e stocks.
- `app.js` — dados de demonstração, tabelas e ações de shopfloor.
