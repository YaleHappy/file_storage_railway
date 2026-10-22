param([Parameter(Mandatory=$true)][string]$Password)
Import-Module Microsoft.PowerShell.Utility
$ErrorActionPreference='Stop';$base='http://localhost/file_storage/';$checks=0;$ids=@();$secret=[guid]::NewGuid().ToString('N');$prefix='presenceaudit'+[guid]::NewGuid().ToString('N').Substring(0,8)
function Check-Presence($ok,$label){if(!$ok){throw $label};$script:checks++}
function Login-Presence($name,$password){$session=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$null=Invoke-WebRequest ($base+'login.php') -WebSession $session -Method Post -Body @{username=$name;password=$password;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login=1};return $session}
$admin=Login-Presence 'admin' $Password
$boot=Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $admin
function Action-Presence($action,$data){$body=@{action=$action;admin_csrf=$boot.csrf_token};foreach($key in $data.Keys){$body[$key]=$data[$key]};Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $admin -Method Post -Body $body}
try{
 $one=Action-Presence 'addUser' @{username=$prefix+'one';password=$secret;role='user'};$ids+=[int]$one.id
 $two=Action-Presence 'addUser' @{username=$prefix+'two';password=$secret;role='user'};$ids+=[int]$two.id
 $session=Login-Presence ($prefix+'one') $secret
 $other=Login-Presence ($prefix+'two') $secret
 foreach($id in $ids){& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'age_presence_fixture.php') $id;if($LASTEXITCODE){throw 'Aging fixture failed'}}
 $snapshot=Invoke-RestMethod ($base+'admin.php?presence=1') -WebSession $admin
 foreach($id in $ids){$row=$snapshot.users|Where-Object id -eq $id;Check-Presence ($row.html -like '*離線*') 'Stale account becomes offline without public user_status endpoint'}
 $null=Invoke-RestMethod ($base+'update_last_active.php') -WebSession $session -Method Post -Body @{state='active';activity=1}
 $snapshot=Invoke-RestMethod ($base+'admin.php?presence=1') -WebSession $admin;$row=$snapshot.users|Where-Object id -eq $ids[0];Check-Presence ($row.html -like '*status-online*') 'Fresh heartbeat restores online'
 foreach($mode in @(@{request='idle';label='閒置';css='status-idle'},@{request='away';label='請勿打擾';css='status-dnd'},@{request='hidden';label='隱形';css='status-hidden'})){
  $null=Invoke-RestMethod ($base+'update_status.php') -WebSession $session -Method Post -Body @{status=$mode.request}
  $snapshot=Invoke-RestMethod ($base+'admin.php?presence=1') -WebSession $admin;$row=$snapshot.users|Where-Object id -eq $ids[0]
  Check-Presence ($row.html -like ('*'+$mode.label+'*') -and $row.html -like ('*'+$mode.css+'*')) 'Manual status matches label and color'
 }
 try{Invoke-WebRequest ($base+'admin.php?presence=1') -WebSession $session|Out-Null;throw 'Regular user accessed admin presence'}catch{if(!$_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 403){throw};$checks++}
 $cache=Invoke-WebRequest ($base+'admin.php?presence=1') -WebSession $admin;Check-Presence ($cache.Headers['Cache-Control'] -like '*no-store*') 'Presence response bypasses cache'
 "Admin presence: $checks live checks passed; stale accounts, heartbeat, manual modes and permissions."
}finally{foreach($id in $ids){$null=Action-Presence 'deleteUser' @{id=$id}}}
