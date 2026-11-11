# Runs one command as a new local account without administrator rights.
#
# GitHub's Windows runner account is an administrator, so PHP's symlink()
# succeeds there. Most Windows users lack the symbolic-link privilege, and
# PHP does not request unprivileged creation in Developer Mode either.
param(
    [Parameter(Mandatory = $true)][string] $WorkingDirectory,
    [Parameter(Mandatory = $true)][string] $Command
)
$ErrorActionPreference = 'Stop'
$userName = 'reprintstandard'
$sandbox = 'C:\reprint-standard-user'
$password = ConvertTo-SecureString ('Rp-' + [guid]::NewGuid().ToString('N') + '!') -AsPlainText -Force
if (Get-LocalUser -Name $userName -ErrorAction SilentlyContinue) {
    Set-LocalUser -Name $userName -Password $password
} else {
    New-LocalUser -Name $userName -Password $password -PasswordNeverExpires | Out-Null
    Add-LocalGroupMember -Group Users -Member $userName
}
New-Item -ItemType Directory -Force "$sandbox\tmp" | Out-Null
icacls $sandbox /grant "${userName}:(OI)(CI)F" | Out-Null
icacls $WorkingDirectory /grant "${userName}:(OI)(CI)RX" /T /Q | Out-Null
$credential = New-Object System.Management.Automation.PSCredential($userName, $password)
$script = "set TMP=$sandbox\tmp&& set TEMP=$sandbox\tmp&& whoami /priv | findstr /i SeCreateSymbolicLink& $Command"
$process = Start-Process -FilePath cmd.exe -ArgumentList '/d', '/c', ('"' + $script + '"') `
    -Credential $credential -WorkingDirectory $WorkingDirectory -LoadUserProfile `
    -RedirectStandardOutput "$sandbox\stdout.log" -RedirectStandardError "$sandbox\stderr.log" `
    -Wait -PassThru
Get-Content "$sandbox\stdout.log"
Get-Content "$sandbox\stderr.log"
exit $process.ExitCode
