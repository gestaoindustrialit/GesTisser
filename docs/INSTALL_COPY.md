# Ativação segura de uma cópia GesTISSER

## O que mudou

O instalador antigo carregava `helpers.php` antes de apresentar o formulário. Esse carregamento iniciava o bootstrap e podia criar/alterar tabelas imediatamente; depois, o formulário apenas verificava se havia utilizadores e criava o primeiro administrador, ativando também o cron inline de RH.

O instalador atual faz primeiro um diagnóstico isolado e sem carregar o bootstrap mutável. Distingue uma localização sem base, um SQLite vazio, uma cópia GesTISSER reconhecida e uma base inválida/incompatível. Uma base preenchida nunca oferece a instalação nova.

## Ativar uma cópia

1. Copiar `database.sqlite` para a localização definitiva da nova instalação. Não usar a localização montada pela produção.
2. Garantir escrita no ficheiro, no diretório que o contém e em `storage/`.
3. Se a base estiver noutro local, definir `GESTISSER_DB_PATH` antes de abrir `install.php`.
4. Abrir `install.php` e rever tamanho, data, versão, tabelas, integridade, chaves estrangeiras e existência de administradores. O diagnóstico não mostra dados pessoais ou comerciais.
5. Escolher **Ativar uma cópia existente do GesTISSER** e o ambiente. Para Teste, indicar o caminho conhecido da produção quando disponível.
6. Confirmar o backup e as migrações em falta. A ativação só prossegue depois de uma cópia com SHA-256 idêntico ao original.
7. Guardar o caminho do backup mostrado na conclusão e iniciar sessão com uma conta já existente.
8. Confirmar externamente que o servidor não tem crontab antigo a apontar para esta cópia. O bloqueio da aplicação é uma segunda linha de defesa, não substitui a remoção de agendamentos no sistema operativo.

Em Teste, a configuração fora da base usa sessão, uploads e logs próprios, envia `noindex`, apresenta uma faixa visível e bloqueia emails, cron e clientes de integrações. As integrações e os seus fluxos são também desativados na cópia.

## Rollback

Se uma migração falhar, a transação em curso é revertida, as migrações seguintes não são executadas e o backup validado é mantido. Não tente continuar no navegador.

Para um rollback administrativo:

1. Colocar a aplicação em manutenção e parar PHP workers e cron jobs desta instalação.
2. Guardar o ficheiro que falhou para análise, sem o reutilizar em produção.
3. Verificar o SHA-256 do backup indicado pelo instalador e trabalhar sempre numa cópia adicional.
4. Restaurar o backup para uma **nova localização vazia**; não o copiar por cima de uma base aberta.
5. Ajustar `GESTISSER_DB_PATH`/`storage/installation.json` para a localização restaurada.
6. Só remover `storage/install.lock` durante uma intervenção administrativa controlada. Em alternativa, definir temporariamente `GESTISSER_INSTALLER_UNLOCK=1`, removendo-a logo depois.
7. Repetir apenas o diagnóstico e investigar a migração antes de voltar a ativar.

Nunca executar o fluxo contra a base de produção. O instalador não faz importações históricas nem agenda tarefas no sistema operativo.
