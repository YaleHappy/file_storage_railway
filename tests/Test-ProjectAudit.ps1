param([Parameter(Mandatory=$true)][string]$Password)
$ErrorActionPreference='Stop'
$base='http://localhost/file_storage/'
$session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
$null=Invoke-WebRequest ($base+'login.php') -WebSession $session
$null=Invoke-WebRequest ($base+'login.php') -Method Post -WebSession $session -Body @{username='user1';password=$Password;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login='1'}
$checks=0; $fileId=0
function Assert-Audit($Condition,$Label) { if (!$Condition) { throw $Label }; $script:checks++ }
function Expect-AuditStatus([scriptblock]$Operation,[int]$Status) {
 try { & $Operation | Out-Null } catch { if ([int]$_.Exception.Response.StatusCode -eq $Status) { $script:checks++; return }; throw }
 throw "Expected HTTP $Status"
}
try {
 $directory=Invoke-RestMethod ($base+'group_api.php') -WebSession $session
 Assert-Audit $directory.success 'Audit login successful'
 Expect-AuditStatus { Invoke-WebRequest ($base+'uploads/group-smoke-upload.txt') } 403
 Expect-AuditStatus { Invoke-WebRequest ($base+'move_file.php') -Method Post -WebSession $session -Body @{file_id=999999;target_folder=999999} } 403
 Expect-AuditStatus { Invoke-WebRequest ($base+'folders.php?action=create') -Method Post -WebSession $session -Body @{folder_name='audit-invalid';parent_folder=999999} } 403
 $fixture=Join-Path $PSScriptRoot 'group-smoke-upload.txt'
 Expect-AuditStatus { Invoke-WebRequest ($base+'upload_ajax.php') -Method Post -WebSession $session -Form @{'files[]'=(Get-Item $fixture);folder_id=999999} } 403
 $before=@($directory.files.file_id)
 $upload=Invoke-RestMethod ($base+'upload_ajax.php') -Method Post -WebSession $session -Form @{'files[]'=(Get-Item $fixture);folder_id=0}
 Assert-Audit $upload.success 'Valid upload succeeds'
 $after=Invoke-RestMethod ($base+'group_api.php') -WebSession $session
 $added=@($after.files | Where-Object {$_.file_id -notin $before -and $_.original_filename -eq 'group-smoke-upload.txt'})
 Assert-Audit ($added.Count -eq 1) 'Only audit file was created'
 $fileId=[int]$added[0].file_id
 $share=Invoke-RestMethod ($base+'share_ajax.php') -Method Post -WebSession $session -Body @{file_id=$fileId;share_password='audit-test-only'}
 Assert-Audit $share.success 'Password share created'
 $token=([uri]$share.link).Query.TrimStart('?').Split('=')[1]
 $bypass=Invoke-WebRequest ($base+'files.php?token='+$token)
 Assert-Audit (!$bypass.Headers['Content-Disposition']) 'Legacy token route does not download protected share'
 $download=Invoke-WebRequest ($base+'share.php?token='+$token+'&download=1') -Method Post -Body @{password='audit-test-only'}
 Assert-Audit ([bool]$download.Headers['Content-Disposition']) 'Correct share password still downloads'
 $badMove=Invoke-RestMethod ($base+'move_file.php') -Method Post -WebSession $session -Body @{file_id=$fileId;target_folder=0}
 Assert-Audit $badMove.success 'Moving own file to root remains valid'
 Write-Output "Project audit HTTP: $checks checks passed."
} finally {
 if ($fileId) { $null=Invoke-RestMethod ($base+'bulk_delete.php') -Method Post -WebSession $session -ContentType 'application/json' -Body (@{ids=@($fileId)} | ConvertTo-Json -Compress) }
}
