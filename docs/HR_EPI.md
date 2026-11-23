# Gestão de EPI

Em **Recursos humanos → Gestão de EPI**, o RH pode gerir o catálogo, registar entregas e consultar o histórico. A ficha do colaborador apresenta o separador **EPI Entregues**. O acesso **EPI** no Shopfloor abre o histórico pessoal. Na ficha de utilizador, a administração pode atribuir **Registar entregas de EPI** ou **Gerir EPI (SST)**.

O responsável pode apresentar a entrega pendente ao colaborador para confirmação com o PIN individual. O colaborador também pode confirmar mais tarde no seu histórico. Cinco PINs inválidos bloqueiam novas tentativas naquele colaborador por até 15 minutos. O comprovativo documenta a confirmação eletrónica no GesTISSER. Cada retificação ou devolução é acrescentada ao histórico da entrega.

## SharePoint

- A ligação Microsoft Graph em **Integrações → SharePoint** deve apontar para o site **Tisser Central**. O servidor utiliza o segredo já configurado na integração. A aplicação Graph precisa de acesso para escrever itens nesse site e de acesso para criar listas e ajustar colunas quando a estrutura ainda está incompleta.
- Podem ser pré-criadas as listas **RH_CatalogoEPI** e **RH_EntregasEPI**, com os nomes internos e tipos definidos em `app/Services/HrEpiSharePoint.php`. Configure **EPI_ID** e **EntregaID**, respetivamente, como texto indexado com valores únicos. Se já existirem duplicados, reveja esses dados no SharePoint antes de configurar a coluna como única.
- Use o cron SharePoint existente, `cron/sharepoint_runner.php`, em PHP CLI 7.0.33. A fila EPI é processada mesmo que o agendamento de artigos esteja desativado, desde que o cron e os serviços externos estejam ativos na instalação. **Forçar sincronização** tenta imediatamente até cinco registos. O histórico, o catálogo e a auditoria registam o resultado. O worker volta a tentar falhas transitórias.
- Restrinja as permissões de leitura da lista de entregas a RH/SST e às pessoas com necessidade de acesso. Reserve a escrita à aplicação Graph e às contas administrativas com responsabilidade pela integração. As listas são uma cópia operacional do SQLite.

## Conservação e acesso

- O SQLite guarda o retrato dos dados da entrega e a declaração aceite para reproduzir o PDF. Os backups e os PDFs exportados devem seguir os mesmos controlos de acesso dos registos de RH.
- O RH/SST deve aplicar o prazo de conservação aprovado pela TISSER a todo o conjunto de dados, incluindo as cópias em SharePoint, os PDFs, a auditoria e os backups. A revisão deve considerar obrigações legais e eventuais ordens de conservação. A eliminação autorizada deve abranger também a fila, de modo a evitar republicação.
- Os registos de entrega são conservados após desativação do equipamento ou da conta. Para corrigir dados, use **Registar retificação**. O botão mantém o comprovativo original e associa a correção à linha correspondente.
