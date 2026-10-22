param([Parameter(Mandatory=$true)][string]$Password)
$ErrorActionPreference='Stop';$base='http://localhost/file_storage/';$checks=0
function Assert-Browse($ok){if(!$ok){throw "Browse check $($script:checks+1) failed"};$script:checks++}
function Login-Browse($name){$s=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$null=Invoke-WebRequest ($base+'login.php') -WebSession $s -Method Post -Body @{username=$name;password=$Password;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login=1};return $s}
function Expect-Denied([scriptblock]$action,$status){try{& $action|Out-Null}catch{Assert-Browse ([int]$_.Exception.Response.StatusCode -eq $status);return};throw 'Access unexpectedly allowed'}
$admin=Login-Browse 'admin';$member=Login-Browse 'user1'
$list=Invoke-RestMethod ($base+'admin.php?format=json&section=files&q=cloud-search-demo.txt') -WebSession $admin
$file=$list.data.items|Select-Object -First 1
if(!$file){throw 'Run Seed-SearchDemo.ps1 first'}
Assert-Browse ($file.full_path -like '我的雲端 / *')
$folder=Invoke-RestMethod ($base+"admin.php?format=json&section=files&uid=$($file.user_id)&folder=$($file.folder_id)") -WebSession $admin
Assert-Browse (@($folder.data.items|Where-Object {[int]$_.folder_id -ne [int]$file.folder_id}).Count -eq 0)
$preview=Invoke-WebRequest ($base+"preview.php?admin=1&file_id=$($file.id)") -WebSession $admin
Assert-Browse ($preview.Content -like '*cloud-search-demo.txt*')
$download=Invoke-WebRequest ($base+"admin_download.php?file_id=$($file.id)") -WebSession $admin
Assert-Browse ($download.StatusCode -eq 200 -and $download.Headers['Content-Disposition'] -like '*attachment*')
Expect-Denied {Invoke-WebRequest ($base+"preview.php?admin=1&file_id=$($file.id)") -WebSession $member} 403
Expect-Denied {Invoke-WebRequest ($base+"admin_download.php?file_id=$($file.id)") -WebSession $member} 403
Expect-Denied {Invoke-WebRequest ($base+'admin.php?format=json&section=folders&uid=4&folder='+$file.folder_id) -WebSession $admin} 404
Expect-Denied {Invoke-WebRequest ($base+'admin_download.php?file_id=2147483647') -WebSession $admin} 404
"Admin storage HTTP: $checks checks passed (browse, preview, download, ownership and role checks)."
