param([Parameter(Mandatory=$true)][string]$Password)
Import-Module Microsoft.PowerShell.Utility
$ErrorActionPreference='Stop';$base='http://localhost/file_storage/';$checks=0;$fileId=0
function Check-Edge($ok,$label){if(!$ok){throw $label};$script:checks++}
function Login-Edge($name){$s=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$null=Invoke-WebRequest ($base+'login.php') -WebSession $s -Method Post -Body @{username=$name;password=$Password;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login=1};return $s}
function Expect-Edge([scriptblock]$action,$status){try{& $action|Out-Null}catch{Check-Edge ([int]$_.Exception.Response.StatusCode -eq $status) "Expected HTTP $status";return};throw "Expected HTTP $status but operation was accepted"}
$one=Login-Edge 'user1';$two=Login-Edge 'user2'
$fixture=Join-Path $env:TEMP ('logic-edge-'+[guid]::NewGuid().ToString('N')+'.txt');Set-Content -LiteralPath $fixture -Value 'Disposable share edge test'
try {
 Expect-Edge {Invoke-WebRequest ($base+'folders.php?action=delete') -WebSession $one -Method Post -Body @{folder_id=2147483647}} 404
 Expect-Edge {Invoke-WebRequest ($base+'folders.php?action=delete') -WebSession $one} 405
 $upload=Invoke-RestMethod ($base+'upload_ajax.php') -WebSession $one -Method Post -Form @{'files[]'=(Get-Item $fixture);folder_id=0};Check-Edge $upload.success 'Upload fixture'
 $name=[IO.Path]::GetFileName($fixture);$found=Invoke-RestMethod ($base+'cloud_search.php?q='+$name) -WebSession $one;$fileId=[int]$found.results[0].id;Check-Edge ($fileId -gt 0) 'Locate own fixture'
 $share=Invoke-RestMethod ($base+'share_ajax.php') -WebSession $one -Method Post -Body @{file_id=$fileId;share_password='edge-test-only'}
 Check-Edge ($share.success -and !$share.PSObject.Properties['debug']) 'Share response excludes debugging data'
 $html=(Invoke-WebRequest ($base+'my_shares.php') -WebSession $one).Content
 $match=[regex]::Match($html,'class="btn btn-soft btn-sm share-settings" data-id="(\d+)" data-expiry="([^"]*)" data-name="'+[regex]::Escape($name)+'"')
 Check-Edge $match.Success 'Find temporary share setting'
 $shareId=[int]$match.Groups[1].Value;$expiry=$match.Groups[2].Value
 Check-Edge ($expiry -ne '') 'Password-protected share still expires by default'
 $remaining=([datetime]::ParseExact($expiry,'yyyy-MM-ddTHH:mm',$null)-[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time')).TotalDays
 Check-Edge ($remaining -gt 6.99 -and $remaining -le 7.01) 'Default lifetime is seven days'
 Expect-Edge {Invoke-WebRequest ($base+'share_ajax.php') -WebSession $one -Method Post -Body @{file_id=$fileId;expires_at='2026-02-30T10:00'}} 400
 Expect-Edge {Invoke-WebRequest ($base+'my_shares.php') -WebSession $one -Method Post -Body @{update=1;share_id=$shareId;expires_at='invalid';share_password='must-not-be-saved'}} 400
 $after=(Invoke-WebRequest ($base+'my_shares.php') -WebSession $one).Content;Check-Edge ($after -like ('*data-id="'+$shareId+'" data-expiry="'+$expiry+'"*')) 'Invalid date leaves old expiry unchanged'
 $download=Invoke-WebRequest ($share.link+'&download=1') -Method Post -Body @{password='edge-test-only'};Check-Edge ([bool]$download.Headers['Content-Disposition']) 'Invalid update leaves original password valid'
 Expect-Edge {Invoke-WebRequest ($base+'my_shares.php') -WebSession $two -Method Post -Body @{update=1;share_id=$shareId;expires_at=''}} 404
 Expect-Edge {Invoke-WebRequest ($base+'my_shares.php') -WebSession $two -Method Post -Body @{delete=$shareId}} 404
 $clear=Invoke-RestMethod ($base+'my_shares.php') -WebSession $one -Method Post -Body @{update=1;share_id=$shareId;expires_at=''};Check-Edge $clear.success 'Explicitly clearing expiry remains supported'
 "Project edge cases: $checks HTTP checks passed."
}finally{
 if($fileId){$null=Invoke-RestMethod ($base+'bulk_delete.php') -WebSession $one -Method Post -ContentType 'application/json' -Body (@{ids=@($fileId)}|ConvertTo-Json -Compress)}
 Remove-Item -LiteralPath $fixture
}
