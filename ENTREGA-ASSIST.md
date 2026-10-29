# GesTISSER Assist — instalação no servidor

[**Atualização do painel e do botão para tablets**](dist/Gestisser-Assist-ATUALIZACAO-TABLETS.zip): três ficheiros, sem alterar a base de dados. Retira o link administrativo do painel e move Nova conversa para junto de Reportar problema. Inclui ícone de recurso para falhas do PNG. A conta do tablet precisa de `assist.use` e ativação/piloto; ver instruções do ZIP.

Já tem o Assist instalado? [**Descarregar apenas a atualização do botão e painel**](dist/Gestisser-Assist-ATUALIZACAO-UI.zip). Substituir os três ficheiros do pacote para atualizar apenas o aspeto; para importar Excel, usar o pacote abaixo. Conservar `assets/assist-saco.png`.

[**Descarregar o ZIP para o servidor**](dist/Gestisser-Assist-SERVIDOR.zip)

No GitHub, abrir o ZIP e clicar no ícone **Download raw file** para descarregar o pacote. Este ZIP contém os ficheiros da aplicação necessários e o ficheiro `LEIA-ME-SERVIDOR.txt`. O botão **Code → Download ZIP** descarrega o repositório completo, incluindo este pacote.

Extrair o pacote na raiz do GesTisser, onde estão `erp.php`, `shopfloor.php` e `config.php`, mantendo as pastas. Fazer backup primeiro. Os dois ficheiros existentes substituídos são `app/routes.php` e `partials/footer.php`; integrar eventuais alterações posteriores do servidor antes da substituição. Preservar `database.sqlite` e a configuração do servidor.

## Template e importação de conhecimento

[**Descarregar a atualização com template e importação Excel**](dist/Gestisser-Assist-ATUALIZACAO-EXCEL.zip) · [**Template Excel vazio**](dist/modelo_conhecimento_SACO.xlsx)

Depois de instalar a atualização, abrir **Base de conhecimento → Importar Excel → Descarregar template Excel** para obter as categorias do próprio servidor. Preencher a folha Conhecimento, pré-visualizar e confirmar. Cada linha cria um rascunho, sujeito a validação e publicação. O template estático acima contém apenas as categorias iniciais. O cabeçalho passa a mostrar **SACO · Sistema de Apoio a Conhecimento Operacional**.

## Mascote SACO

Colocar o PNG original em **`assets/assist-saco.png`**, relativamente à raiz do ERP.

Exemplo: `public_html/gestisser/assets/assist-saco.png` se o ERP estiver em `public_html/gestisser/`. A imagem não está incluída no pacote: não ficou acessível como ficheiro no ambiente da implementação.

## Ativação

Entrar como administrador e abrir `assist_admin.php`. A migração aditiva usa a base existente; a funcionalidade fica desativada para utilizadores comuns até configurar permissões e pilotos. Não há deploy automático desta branch.

[Manual técnico e funcional](docs/gestisser-assist.md) · [Resultados dos testes](docs/gestisser-assist-validation.md)
