#Requires -RunAsAdministrator
$ErrorActionPreference='Stop'
Stop-Service GesTisserPrintAgent -ErrorAction SilentlyContinue
& sc.exe delete GesTisserPrintAgent
Write-Host 'Serviço removido. Revogue a credencial no ERP. Configuração, logs e diário preservados em ProgramData\GesTisserPrintAgent para diagnóstico e proteção contra duplicações.'
