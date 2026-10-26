#Requires -RunAsAdministrator
param([Parameter(Mandatory=$true)][string]$Url,[Parameter(Mandatory=$true)][string]$AgentId,[Parameter(Mandatory=$true)][string[]]$Queues,[string]$PdfExe='')
$ErrorActionPreference='Stop'
if(([uri]$Url).Scheme -ne 'https'){throw 'HTTPS obrigatório'}
$root=Join-Path $env:ProgramData 'GesTisserPrintAgent'
New-Item -ItemType Directory -Path $root -Force | Out-Null
# Only SYSTEM and Administrators can read agent credentials, payloads and journals.
& icacls.exe $root /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
if($LASTEXITCODE -ne 0){throw 'Falha ao proteger diretório'}
$compiler=Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if(!(Test-Path $compiler)){throw '.NET Framework 4.x com compilador necessário'}
if(Get-Service GesTisserPrintAgent -ErrorAction SilentlyContinue){Stop-Service GesTisserPrintAgent}
& $compiler /nologo /target:exe /out:"$root\GesTisserPrintAgent.exe" /reference:System.ServiceProcess.dll /reference:System.Web.Extensions.dll /reference:System.Drawing.dll /reference:System.Security.dll "$PSScriptRoot\GesTisserPrintAgent.cs"
if($LASTEXITCODE -ne 0){throw 'Compilação falhou'}
$secret=Read-Host 'Token individual do agente' -AsSecureString
$ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
try{$plain=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr);Add-Type -AssemblyName System.Security;$protected=[Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($plain),$null,[Security.Cryptography.DataProtectionScope]::LocalMachine)}finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr);$plain=$null}
@{url=$Url;agent_id=$AgentId;token_protected=[Convert]::ToBase64String($protected);queues=@($Queues);pdf_exe=$PdfExe}|ConvertTo-Json|Set-Content -Encoding UTF8 "$root\config.json"
if(!(Get-Service GesTisserPrintAgent -ErrorAction SilentlyContinue)){New-Service -Name GesTisserPrintAgent -BinaryPathName "`"$root\GesTisserPrintAgent.exe`"" -DisplayName 'GesTisser Print Agent' -StartupType Automatic | Out-Null}
& sc.exe failure GesTisserPrintAgent reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null
Start-Service GesTisserPrintAgent
Write-Host "Instalado. Logs e configuração: $root. Confirme inventário no ERP antes de ativar a impressão."
