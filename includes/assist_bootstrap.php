<?php
require_once dirname(__DIR__).'/helpers.php';
require_once dirname(__DIR__).'/assist_migrations.php';
require_once dirname(__DIR__).'/app/Services/AssistService.php';
assist_run_migrations($pdo);
// Use the ERP session and active user lookup; no parallel login or PIN flow.
$assistUser=current_user($pdo);
$assistActive=$pdo->prepare('SELECT is_active FROM users WHERE id=?');$assistActive->execute([(int)($assistUser['id']??0)]);
if (!$assistUser || (int)$assistActive->fetchColumn()!==1) { http_response_code(401); exit('Autenticação obrigatória.'); }
$assist=AssistService::fromSession($pdo,$assistUser);
