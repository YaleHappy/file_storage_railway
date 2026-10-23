param([string]$Password='123')
Import-Module Microsoft.PowerShell.Utility
$ErrorActionPreference='Stop';$base='http://localhost/file_storage/'
function ActivityLogin($name){$s=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$null=Invoke-WebRequest ($base+'login.php') -WebSession $s -Method Post -Body @{username=$name;password=$Password;authcode=('stust'+(Get-Date -Format MMdd));login=1};return $s}
$admin=ActivityLogin 'admin';$user=ActivityLogin 'user1';$name='activity-test-'+[guid]::NewGuid().ToString('N')
$result=Invoke-RestMethod ($base+'folders.php?action=create') -WebSession $user -Method Post -Body @{folder_name=$name;parent_folder=0}
if(!$result.success){throw 'Create failed'}
try{
 $url=$base+'admin.php?section=activity&format=json&q='+[uri]::EscapeDataString($name)
 $log=Invoke-RestMethod $url -WebSession $admin
 if($log.data.total -ne 1 -or $log.data.items[0].actor_name -ne 'user1' -or $log.data.items[0].actor_role -ne 'user'){throw 'Missing activity or incorrect role'}
 $id=$log.data.items[0].target_id
 $bad=Invoke-RestMethod ($base+'folders.php?action=create') -WebSession $user -Method Post -Body @{folder_name=$name;parent_folder=0}
 if($bad.success -or (Invoke-RestMethod $url -WebSession $admin).data.total -ne 1){throw 'Failed operation logged'}
 try{Invoke-WebRequest $url -WebSession $user|Out-Null;throw 'User accessed logs'}catch{if(!$_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 403){throw}}
 $html=(Invoke-WebRequest ($base+'admin.php?section=activity') -WebSession $admin).Content
 if($html -notlike '*使用者活動紀錄*' -or $html -notlike '*管理員操作紀錄*' -or $html -notlike '*一般使用者*'){throw 'Missing UI labels'}
 'User activity HTTP: successful operation, actor role, rejected duplicate, admin-only access and UI labels passed.'
}finally{if($id){$null=Invoke-RestMethod ($base+'folders.php?action=delete') -WebSession $user -Method Post -Body @{folder_id=$id}}}
