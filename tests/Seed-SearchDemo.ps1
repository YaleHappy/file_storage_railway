param([Parameter(Mandatory=$true)][string]$Password)
$ErrorActionPreference='Stop'
$base='http://localhost/file_storage/'; $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
$null=Invoke-WebRequest ($base+'login.php') -WebSession $session
$null=Invoke-WebRequest ($base+'login.php') -WebSession $session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{username='user1';password=$Password;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login=1}
$directory=Invoke-RestMethod ($base+'group_api.php') -WebSession $session
if(!$directory.success){throw 'User1 login failed'}
# Repair only files created by the earlier PowerShell multipart filename encoding.
$legacyName='=?utf-8?B?5pCc5bCL56S656+EX+WFseWQjOaWh+S7ti50eHQ=?='
$legacy=Invoke-RestMethod ($base+'cloud_search.php?q='+[uri]::EscapeDataString($legacyName)) -WebSession $session
$legacyIds=@($legacy.results|Where-Object {$_.kind -eq 'file' -and $_.name -eq $legacyName -and $_.path.StartsWith('我的雲端 / 搜尋示範－')}|ForEach-Object {$_.id})
if($legacyIds.Count){$null=Invoke-RestMethod ($base+'bulk_delete.php') -WebSession $session -Method Post -ContentType 'application/json' -Body (@{ids=$legacyIds}|ConvertTo-Json -Compress)}
$paths=@(
 '搜尋示範－工作', '搜尋示範－工作/專案A', '搜尋示範－工作/專案A/文件', '搜尋示範－工作/專案A/交付',
 '搜尋示範－工作/專案B', '搜尋示範－工作/專案B/文件', '搜尋示範－工作/行政', '搜尋示範－工作/行政/會議',
 '搜尋示範－學習', '搜尋示範－學習/課程', '搜尋示範－學習/課程/網頁開發', '搜尋示範－學習/課程/資料庫', '搜尋示範－學習/筆記',
 '搜尋示範－生活', '搜尋示範－生活/旅遊', '搜尋示範－生活/旅遊/台北', '搜尋示範－生活/旅遊/台南', '搜尋示範－生活/帳務', '搜尋示範－生活/帳務/2026'
)
$folderIds=@{}; $created=0; $uploaded=0
$fixtureDirectory=Join-Path $env:TEMP ('cloud-search-demo-'+[guid]::NewGuid().ToString('N'))
$null=New-Item -ItemType Directory -Path $fixtureDirectory
$fixture=Join-Path $fixtureDirectory 'cloud-search-demo.txt'
Set-Content -LiteralPath $fixture -Encoding utf8 -Value "這是一份全雲端搜尋示範文件。`n相同名稱、相同內容，分別存放在工作、學習與生活的不同層級資料夾。`n不含任何個人或敏感資料。"
try {
 foreach($path in $paths) {
  $parts=$path.Split('/'); $name=$parts[-1]; $parentPath=if($parts.Length -gt 1){($parts[0..($parts.Length-2)] -join '/')}else{''}
  $parent=if($parentPath){[int]$folderIds[$parentPath]}else{0}
  $query='cloud_search.php?scope=folder&folder='+$parent+'&q='+[uri]::EscapeDataString($name)
  $existing=Invoke-RestMethod ($base+$query) -WebSession $session
  if(!$existing.success){throw 'Cannot inspect folder'}
  $found=@($existing.results|Where-Object {$_.kind -eq 'folder' -and $_.name -eq $name -and $_.parent_id -eq $parent})
  if(!$found.Count) {
   $createdFolder=Invoke-RestMethod ($base+'folders.php?action=create') -WebSession $session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{folder_name=$name;parent_folder=$parent}
   if(!$createdFolder.success){throw $createdFolder.msg}
   $created++
   $existing=Invoke-RestMethod ($base+$query) -WebSession $session
   $found=@($existing.results|Where-Object {$_.kind -eq 'folder' -and $_.name -eq $name -and $_.parent_id -eq $parent})
  }
  if($found.Count -ne 1){throw "Cannot resolve demo folder: $path"}
  $folderId=[int]$found[0].id; $folderIds[$path]=$folderId
  $fileSearch=Invoke-RestMethod ($base+'cloud_search.php?scope=folder&folder='+$folderId+'&q='+[uri]::EscapeDataString('cloud-search-demo.txt')) -WebSession $session
  $sameFile=@($fileSearch.results|Where-Object {$_.kind -eq 'file' -and $_.name -eq 'cloud-search-demo.txt'})
  if(!$sameFile.Count){
   $upload=Invoke-RestMethod ($base+'upload_ajax.php') -WebSession $session -Method Post -Form @{'files[]'=(Get-Item -LiteralPath $fixture);folder_id=$folderId}
   if(!$upload.success){throw ($upload|ConvertTo-Json -Compress)}
   $uploaded++
  }
 }
 $verify=Invoke-RestMethod ($base+'cloud_search.php?q='+[uri]::EscapeDataString('cloud-search-demo.txt')) -WebSession $session
 if(!$verify.success -or $verify.total -lt $paths.Count){throw 'Demo file search verification failed'}
 Write-Output "User1 demo ready: $($paths.Count) folders, $($paths.Count) identical sample files; newly created $created folders and $uploaded files."
 Write-Output 'Search: cloud-search-demo'
} finally {
 Remove-Item -LiteralPath $fixture -ErrorAction SilentlyContinue
 Remove-Item -LiteralPath $fixtureDirectory -ErrorAction SilentlyContinue
}
