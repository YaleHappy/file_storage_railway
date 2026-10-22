param([Parameter(Mandatory=$true)][string]$Password)
$ErrorActionPreference='Stop'
$base='http://localhost/file_storage/'; $checks=0; $rootId=0; $childId=0; $fileId=0; $paginationIds=@()
$session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
function Check-Storage($Condition,$Label) { if (!$Condition) { throw $Label }; $script:checks++ }
function Post-Storage($Route,$Body) { Invoke-RestMethod ($base+$Route) -WebSession $session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body $Body }
function Find-StorageFolder($Parent,$Name) {
 $html=(Invoke-WebRequest ($base+"index.php?folder=$Parent") -WebSession $session).Content
 foreach($match in [regex]::Matches($html,'data-folder="(\d+)"\s+data-folder-name="([^"]+)"')) {
  if ([System.Net.WebUtility]::HtmlDecode($match.Groups[2].Value) -eq $Name) { return [int]$match.Groups[1].Value }
 }
 throw 'Disposable folder not found'
}
$null=Invoke-WebRequest ($base+'login.php') -WebSession $session
$null=Post-Storage 'login.php' @{username='user1';password=$Password;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login=1}
# Recover only empty fixtures from interrupted runs of this named test workflow.
$fixturePage=(Invoke-WebRequest ($base+'index.php') -WebSession $session).Content
foreach($fixtureMatch in [regex]::Matches($fixturePage,'data-folder="(\d+)"\s+data-folder-name="(UX一次性測試-[a-f0-9]{8})"')) {
 $staleRoot=[int]$fixtureMatch.Groups[1].Value
 $stalePage=(Invoke-WebRequest ($base+"index.php?folder=$staleRoot") -WebSession $session).Content
 foreach($staleChild in [regex]::Matches($stalePage,'data-folder="(\d+)"\s+data-folder-name="(重新命名子資料夾|子資料夾)"')) {
  Post-Storage 'folders.php?action=delete' @{folder_id=[int]$staleChild.Groups[1].Value}|Out-Null
 }
 Post-Storage 'folders.php?action=delete' @{folder_id=$staleRoot}|Out-Null
}
$name='UX一次性測試-'+[guid]::NewGuid().ToString('N').Substring(0,8)
try {
 Check-Storage (Post-Storage 'folders.php?action=create' @{folder_name=$name;parent_folder=0}).success 'Create root folder'
 $rootId=Find-StorageFolder 0 $name
 Check-Storage (!(Post-Storage 'folders.php?action=create' @{folder_name=$name;parent_folder=0}).success) 'Duplicate sibling blocked'
 Check-Storage (Post-Storage 'folders.php?action=create' @{folder_name='子資料夾';parent_folder=$rootId}).success 'Create nested folder'
 $childId=Find-StorageFolder $rootId '子資料夾'
 Check-Storage (Post-Storage 'folders.php?action=rename' @{folder_id=$childId;new_name='重新命名子資料夾'}).success 'Rename folder'
 $childHtml=(Invoke-WebRequest ($base+"index.php?folder=$childId") -WebSession $session).Content
 Check-Storage ($childHtml.Contains($name) -and $childHtml.Contains('重新命名子資料夾')) 'Nested breadcrumb includes ancestors'
 Check-Storage (!(Post-Storage 'folders.php?action=delete' @{folder_id=$rootId}).success) 'Nonempty parent remains'
 $before=Invoke-RestMethod ($base+'group_api.php') -WebSession $session
 $uploaded=Invoke-RestMethod ($base+'upload_ajax.php') -WebSession $session -Method Post -Form @{'files[]'=(Get-Item (Join-Path $PSScriptRoot 'group-smoke-upload.txt'));folder_id=$rootId}
 Check-Storage $uploaded.success 'Upload into folder'
 $after=Invoke-RestMethod ($base+'group_api.php') -WebSession $session
 $newFiles=@($after.files|Where-Object {$_.file_id -notin @($before.files.file_id)})
 Check-Storage ($newFiles.Count -eq 1) 'Identify test upload only'
 $fileId=[int]$newFiles[0].file_id
 Check-Storage (Post-Storage 'move_file.php' @{file_id=$fileId;target_folder=$childId}).success 'Move into nested folder'
 $childHtml=(Invoke-WebRequest ($base+"index.php?folder=$childId") -WebSession $session).Content
 Check-Storage ($childHtml.Contains('group-smoke-upload.txt')) 'Moved file appears in destination'
 $search=Invoke-RestMethod ($base+'cloud_search.php?q=group-smoke-upload') -WebSession $session
 $searchFile=@($search.results|Where-Object {$_.kind -eq 'file' -and $_.id -eq $fileId})
 Check-Storage ($searchFile.Count -eq 1 -and $searchFile[0].path.Contains($name) -and $searchFile[0].path.Contains('重新命名子資料夾')) 'Cloud search finds nested file with full path'
 $folderSearch=Invoke-RestMethod ($base+'cloud_search.php?q='+[uri]::EscapeDataString($name)) -WebSession $session
 Check-Storage (@($folderSearch.results|Where-Object {$_.kind -eq 'folder' -and $_.id -eq $rootId}).Count -eq 1) 'Cloud search includes folders'
 $literalSearch=Invoke-RestMethod ($base+'cloud_search.php?q='+[uri]::EscapeDataString($name+'%')) -WebSession $session
 Check-Storage ($literalSearch.total -eq 0) 'Search percent is literal, not SQL wildcard'
 $scopedSearch=Invoke-RestMethod ($base+"cloud_search.php?q=group-smoke-upload&scope=folder&folder=$rootId") -WebSession $session
 Check-Storage (@($scopedSearch.results|Where-Object {$_.kind -eq 'file' -and $_.id -eq $fileId}).Count -eq 0) 'Current folder search excludes descendants'
 $scopedChild=Invoke-RestMethod ($base+"cloud_search.php?q=group-smoke-upload&scope=folder&folder=$childId") -WebSession $session
 Check-Storage (@($scopedChild.results|Where-Object {$_.kind -eq 'file' -and $_.id -eq $fileId}).Count -eq 1) 'Current folder finds its own file'
 $stranger=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
 $null=Invoke-WebRequest ($base+'login.php') -WebSession $stranger
 $null=Invoke-WebRequest ($base+'login.php') -WebSession $stranger -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{username='user2';password=$Password;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login=1}
 $privateSearch=Invoke-RestMethod ($base+'cloud_search.php?q='+[uri]::EscapeDataString($name)) -WebSession $stranger
 Check-Storage ($privateSearch.total -eq 0) 'Other account cannot search private folder'
 $scopeRejected=$false
 try {Invoke-WebRequest ($base+"cloud_search.php?q=group&scope=folder&folder=$childId") -WebSession $stranger|Out-Null} catch {if([int]$_.Exception.Response.StatusCode -eq 403){$scopeRejected=$true}else{throw}}
 Check-Storage $scopeRejected 'Foreign folder scope is forbidden'
 $anonymousRejected=$false
 try {Invoke-WebRequest ($base+'cloud_search.php?q=test')|Out-Null} catch {if([int]$_.Exception.Response.StatusCode -eq 401){$anonymousRejected=$true}else{throw}}
 Check-Storage $anonymousRejected 'Anonymous search is forbidden'

 Check-Storage (!(Post-Storage 'folders.php?action=delete' @{folder_id=$childId}).success) 'Folder containing file cannot be deleted'
 $share=Post-Storage 'share_ajax.php' @{file_id=$fileId}
 Check-Storage $share.success 'Create default seven day share'
 $shareHtml=(Invoke-WebRequest $share.link).Content
 Check-Storage ($shareHtml.Contains('group-smoke-upload.txt')) 'Public share displays test file'
 Check-Storage (Post-Storage 'move_file.php' @{file_id=$fileId;target_folder=0}).success 'Return file to cloud root'
 $delete=Invoke-RestMethod ($base+'bulk_delete.php') -WebSession $session -Method Post -ContentType 'application/json' -Body (@{ids=@($fileId)}|ConvertTo-Json -Compress)
 Check-Storage $delete.success 'Remove disposable file and associated share'
 $fileId=0
 for($searchIndex=1;$searchIndex -le 31;$searchIndex++) {
  $paginationName=$name+'-分頁-'+$searchIndex.ToString('00')
  $paginationCreated=Post-Storage 'folders.php?action=create' @{folder_name=$paginationName;parent_folder=$rootId}
  if(!$paginationCreated.success){throw 'Pagination fixture creation failed'}
  $paginationIds+=Find-StorageFolder $rootId $paginationName
 }
 $firstSearchPage=Invoke-RestMethod ($base+'cloud_search.php?q='+[uri]::EscapeDataString($name+'-分頁-')) -WebSession $session
 $secondSearchPage=Invoke-RestMethod ($base+'cloud_search.php?q='+[uri]::EscapeDataString($name+'-分頁-')+'&page=2') -WebSession $session
 Check-Storage ($firstSearchPage.total -eq 31 -and $firstSearchPage.results.Count -eq 30 -and $secondSearchPage.results.Count -eq 1) 'Cloud search is paginated beyond first 30 results'
 Check-Storage (@($secondSearchPage.results|Where-Object {$_.id -in @($firstSearchPage.results.id)}).Count -eq 0) 'Search pages do not repeat results'
 foreach($paginationId in $paginationIds){Post-Storage 'folders.php?action=delete' @{folder_id=$paginationId}|Out-Null}
 $paginationIds=@()
 $emptyChild=Post-Storage 'folders.php?action=delete' @{folder_id=$childId}
 Check-Storage $emptyChild.success ('Remove empty child: '+($emptyChild|ConvertTo-Json -Compress))
 $childId=0
 Check-Storage (Post-Storage 'folders.php?action=delete' @{folder_id=$rootId}).success 'Remove empty parent'
 $rootId=0
 Write-Output "Storage end-to-end: $checks live HTTP checks passed; disposable data removed."
} finally {
 foreach($paginationId in $paginationIds){Post-Storage 'folders.php?action=delete' @{folder_id=$paginationId}|Out-Null}
 if($fileId){Invoke-RestMethod ($base+'bulk_delete.php') -WebSession $session -Method Post -ContentType 'application/json' -Body (@{ids=@($fileId)}|ConvertTo-Json -Compress)|Out-Null}
 if($childId){Post-Storage 'folders.php?action=delete' @{folder_id=$childId}|Out-Null}
 if($rootId){Post-Storage 'folders.php?action=delete' @{folder_id=$rootId}|Out-Null}
}
