# Backups e recuperação de desastre

Cada backup é um ZIP com uma cópia consistente e verificada da base SQLite, um manifesto e os ficheiros enviados existentes em `uploads/`, `assets/uploads/` e `storage/uploads/`. Guarde cópias fora do servidor da aplicação: um disco perdido não pode recuperar backups guardados no mesmo disco.

## Automatização

Configure a periodicidade, hora e retenção em **Administração → Backups**. Depois execute o agendador a cada cinco minutos (ajuste o caminho):

```cron
*/5 * * * * /usr/bin/php /caminho/GesTisser/cron_backups.php >> /var/log/gestisser-backups.log 2>&1
```

O comando só cria uma cópia quando a programação estiver vencida. Uma execução com falha devolve código diferente de zero, adequado para monitorização. A retenção elimina apenas os arquivos mais antigos depois de uma nova cópia ter sido concluída.

Sem acesso a `cron`, a aplicação também verifica a programação no primeiro pedido web após a hora escolhida. Se a hora já tiver passado quando guardar a programação, cria imediatamente a primeira cópia. Como não há processos PHP ativos quando ninguém visita o sistema, o `cron` continua a ser a opção recomendada para garantir a hora mesmo em períodos sem utilização.

## Recuperação normal ou após falha fatal

Estes passos não dependem de a interface web funcionar.

1. Pare o servidor PHP/web ou coloque-o em manutenção. Não restaure com processos a escrever na base.
2. Copie o ZIP escolhido para uma pasta temporária e valide o SHA-256 indicado no ecrã: `sha256sum gestisser_*.zip`.
3. Extraia-o: `unzip gestisser_AAAAMMDD_HHMMSS_xxxxxxxx.zip -d /tmp/gestisser-restore`.
4. Preserve o estado avariado: `cp database.sqlite database.sqlite.before-restore` (guarde também eventuais ficheiros `database.sqlite-wal` e `database.sqlite-shm`).
5. Valide antes de restaurar: `sqlite3 /tmp/gestisser-restore/database.sqlite 'PRAGMA integrity_check;'`. O resultado obrigatório é `ok`.
6. Remova `database.sqlite-wal` e `database.sqlite-shm`, e substitua atomicamente a base: `cp /tmp/gestisser-restore/database.sqlite database.sqlite.new && mv database.sqlite.new database.sqlite`.
7. Reponha os diretórios de anexos extraídos sobre a raiz da aplicação (por exemplo, `cp -a /tmp/gestisser-restore/uploads/. uploads/`). Não elimine ficheiros atuais sem confirmar que pretende regressar exatamente ao estado da cópia.
8. Atribua ao utilizador do servidor web a propriedade/permissão de escrita de `database.sqlite`, `storage/` e dos diretórios de uploads.
9. Inicie o serviço, entre como administrador e valide utilizadores, documentos e o histórico em **Backups**.

Se o código da aplicação também tiver sido perdido, instale primeiro a mesma versão do GesTisser, restaure a base e os uploads como acima e só depois faça uma atualização. Nunca exponha um ZIP de backup num URL público: contém dados pessoais e credenciais cifradas.
