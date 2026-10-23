# PHP de produção

- O destino de produção é PHP 7.0. Todo o código da aplicação e os testes devem funcionar em PHP 7.0.33.
- Confirme o runtime com `php -v` antes da validação. Execute `python3 scripts/test_php70.py --php /caminho/para/php70`; o comando recusa PHP de outras versões.
- Neste ambiente: `source /workspace/gestisser-env/activate.sh` e `python3 scripts/test_php70.py`. O comando `php` usa PHP 7.0.33 após a ativação.
- Resultados em PHP 8 ou posteriores não substituem a validação em PHP 7.0 e não aprovam um PR para produção.
- Preserve `config.platform.php=7.0.33` no Composer e use dependências compatíveis com esse destino. O Composer pode executar num runtime de ferramentas mais recente, mas a aplicação e os testes têm de executar em PHP 7.0.
- Não introduza `: void`, tipos anuláveis (`?int`, `?array`, etc.), propriedades tipadas, arrow functions ou desestruturação com `[]`. Tenha em conta os tipos devolvidos pelo PDO e o controlo de transações SQLite no PHP 7.0 real.
- Não substitua a execução em PHP 7.0 por verificações de texto ou lint em versões posteriores. Comunique separadamente os testes aprovados, as falhas e as verificações que não puderam executar.
