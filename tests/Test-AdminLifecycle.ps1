param([Parameter(Mandatory=$true)][string]$Password)
$ErrorActionPreference='Stop';$base='http://localhost/file_storage/';$checks=0
$users=@();$files=@();$folderId=0;$groupId=0;$announcementId=0;$messages=@();$root=$null
$testPassword='UX'+[guid]::NewGuid().ToString('N');$prefix='uxadmin'+[guid]::NewGuid().ToString('N').Substring(0,8)
function Check-Admin($Value,$Label){if(!$Value){throw $Label};$script:checks++}
function Deny-Admin([scriptblock]$Operation,[int]$Status){try{& $Operation|Out-Null}catch{if([int]$_.Exception.Response.StatusCode -eq $Status){$script:checks++;return};throw};throw "Expected HTTP $Status"}
function Login-AdminTest($Name,$Secret){
 $s=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$null=Invoke-WebRequest ($base+'login.php') -WebSession $s
 $null=Invoke-WebRequest ($base+'login.php') -WebSession $s -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{username=$Name;password=$Secret;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login=1}
 return @{Session=$s;Token='';Name=$Name}
}
function Admin-Call($Client,$Action,$InputData){
 if(!$Client.Token){$boot=Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $Client.Session;$Client.Token=$boot.csrf_token}
 $body=@{action=$Action;admin_csrf=$Client.Token};foreach($key in $InputData.Keys){$body[$key]=$InputData[$key]}
 Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $Client.Session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body $body
}
function Chat-Boot($Client){$html=(Invoke-WebRequest ($base+'chat_history.php?create=1') -WebSession $Client.Session).Content;$match=[regex]::Match($html,'window.groupBoot\s*=\s*(\{[^<]+\});');if(!$match.Success){throw 'Missing chat token'};$boot=$match.Groups[1].Value|ConvertFrom-Json;$Client.CSRF=$boot.csrf;$Client.Id=[int]$boot.userId}
function Group-Call($Client,$Action,$Payload){Invoke-RestMethod ($base+'group_api.php') -WebSession $Client.Session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{action=$Action;group_id=$script:groupId;csrf_token=$Client.CSRF;payload=($Payload|ConvertTo-Json -Compress)}}
try {
 $root=Login-AdminTest 'admin' $Password
 $dashboard=Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $root.Session;$root.Token=$dashboard.csrf_token
 Check-Admin ($dashboard.success -and $dashboard.stats.users -gt 0) 'Administrator overview loads'
 foreach($section in @('users','folders','files','groups','notifications','chats','announcements','logs')){ $page=Invoke-RestMethod ($base+"admin.php?format=json&section=$section") -WebSession $root.Session;Check-Admin ($page.success -and $page.data.items.Count -le 20) "$section uses server pagination" }
 $newAdmin=Admin-Call $root 'addUser' @{username=$prefix+'manager';password=$testPassword;role='admin'};$users+=[int]$newAdmin.id
 $newMember=Admin-Call $root 'addUser' @{username=$prefix+'member';password=$testPassword;role='user'};$users+=[int]$newMember.id
 Check-Admin ($newAdmin.success -and $newMember.success) 'Create two disposable accounts'
 $manager=Login-AdminTest ($prefix+'manager') $testPassword;$member=Login-AdminTest ($prefix+'member') $testPassword;$idleMember=Login-AdminTest ($prefix+'member') $testPassword
 Chat-Boot $member;Chat-Boot $manager
 Check-Admin ($member.Id -eq $newMember.id -and $manager.Id -eq $newAdmin.id) 'Both accounts log in independently'
 Deny-Admin {Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $member.Session} 403
 $managerPage=Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $manager.Session;$manager.Token=$managerPage.csrf_token
 Deny-Admin {Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $manager.Session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{action='disableUser';id=$member.Id;admin_csrf='invalid'}} 403
 Deny-Admin {Admin-Call $manager 'disableUser' @{id=$manager.Id}} 403
 Deny-Admin {Invoke-WebRequest ($base+'admin.php?delUser='+$member.Id) -WebSession $root.Session} 405
 $null=Admin-Call $manager 'disableUser' @{id=$member.Id}
 Deny-Admin {Invoke-RestMethod ($base+'group_api.php') -WebSession $member.Session} 403
 $disabledLogin=Login-AdminTest ($prefix+'member') $testPassword
 Deny-Admin {Invoke-RestMethod ($base+'group_api.php') -WebSession $disabledLogin.Session} 401
 $hiddenOnline=Invoke-RestMethod ($base+'user_status.php') -WebSession $manager.Session
 Check-Admin (@($hiddenOnline.users|Where-Object {$_.user_id -eq $newMember.id}).Count -eq 0) 'Disabled account is absent from online list'
 $null=Admin-Call $manager 'restoreUser' @{id=$newMember.id}
 Deny-Admin {Invoke-RestMethod ($base+'group_api.php') -WebSession $idleMember.Session} 403
 $member=Login-AdminTest ($prefix+'member') $testPassword;Chat-Boot $member
 Check-Admin ((Invoke-RestMethod ($base+'group_api.php') -WebSession $member.Session).success) 'Restored user can log in again'
 $null=Admin-Call $root 'editUser' @{id=$manager.Id;username=$manager.Name;email=($manager.Name+'@local.register');role='user'}
 Deny-Admin {Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $manager.Session} 403
 $null=Admin-Call $root 'editUser' @{id=$manager.Id;username=$manager.Name;email=($manager.Name+'@local.register');role='admin'}
 $manager=Login-AdminTest ($prefix+'manager') $testPassword;Chat-Boot $manager
 $null=Admin-Call $root 'disableUser' @{id=$manager.Id}
 Deny-Admin {Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $manager.Session -MaximumRedirection 0} 403
 $null=Admin-Call $root 'restoreUser' @{id=$newAdmin.id}
 $manager=Login-AdminTest ($prefix+'manager') $testPassword;Chat-Boot $manager
 Check-Admin ((Invoke-RestMethod ($base+'admin.php?format=json') -WebSession $manager.Session).success) 'Restored administrator can log in again'
 $createdFolder=Invoke-RestMethod ($base+'folders.php?action=create') -WebSession $member.Session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{folder_name=$prefix;parent_folder=0}
 $search=Invoke-RestMethod ($base+'cloud_search.php?q='+$prefix) -WebSession $member.Session;$folderId=[int](@($search.results|Where-Object {$_.kind -eq 'folder' -and $_.name -eq $prefix})[0].id)
 Check-Admin ($createdFolder.success -and $folderId -gt 0) 'User creates owned test folder'
 $fixture=Join-Path $env:TEMP ($prefix+'.pdf');Set-Content -LiteralPath $fixture -Value '%PDF-1.4 Disposable admin preview fixture'
 $upload=Invoke-RestMethod ($base+'upload_ajax.php') -WebSession $member.Session -Method Post -Form @{'files[]'=(Get-Item -LiteralPath $fixture);folder_id=$folderId}
 $fileSearch=Invoke-RestMethod ($base+'cloud_search.php?q='+$prefix+'.pdf') -WebSession $member.Session;$fileId=[int](@($fileSearch.results|Where-Object {$_.kind -eq 'file'})[0].id);$files+=$fileId
 Check-Admin ($upload.success -and $fileId -gt 0) 'User uploads test file'
 Deny-Admin {Admin-Call $root 'deleteFolder' @{id=$folderId}} 403
 Deny-Admin {Admin-Call $root 'deleteUser' @{id=$member.Id}} 403
 $share=Invoke-RestMethod ($base+'share_ajax.php') -WebSession $member.Session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{file_id=$fileId}
 $preview=(Invoke-WebRequest ($base+'preview.php?file_id='+$fileId) -WebSession $member.Session).Content
 $previewMatch=[regex]::Match($preview,'/files.php\?preview_token=([a-f0-9]{64})');Check-Admin ($share.success -and $previewMatch.Success) 'Share and preview dependencies created'
 $group=Group-Call $member 'create' @{name=$prefix;members=@($manager.Id)};$groupId=[int]$group.group_id
 Check-Admin ($groupId -gt 0) 'User creates disposable group'
 $groupFile=Group-Call $member 'file' @{cloud_file_id=$fileId;content='attachment'}
 $null=Group-Call $manager 'save_file' @{message_id=$groupFile.message_id}
 $copied=Invoke-RestMethod ($base+'group_api.php') -WebSession $manager.Session;$copyId=[int](@($copied.files|Where-Object {$_.original_filename -eq ($prefix+'.pdf')})[0].file_id);$files+=$copyId
 Check-Admin ($copyId -gt 0) 'Other account saves independent group attachment'
 $null=Admin-Call $root 'pauseGroup' @{id=$groupId}
 Deny-Admin {Group-Call $member 'send' @{content='blocked'}} 403
 Deny-Admin {Group-Call $member 'file' @{cloud_file_id=$fileId}} 403
 $paused=Invoke-RestMethod ($base+'group_api.php?group_id='+$groupId) -WebSession $member.Session
 Check-Admin ($paused.group.paused -and $paused.messages.Count -gt 0) 'Paused group preserves readable history'
 $null=Admin-Call $root 'resumeGroup' @{id=$groupId};$sent=Group-Call $member 'send' @{content='resumed'};Check-Admin ($sent.message_id -gt 0) 'Resumed group accepts messages'
 $null=Admin-Call $root 'deleteGroup' @{id=$groupId}
 Deny-Admin {Invoke-RestMethod ($base+'group_api.php?group_id='+$groupId) -WebSession $member.Session} 404
 Deny-Admin {Invoke-WebRequest ($base+"group_download.php?group_id=$groupId&message_id=$($groupFile.message_id)") -WebSession $member.Session} 404
 $savedAfter=Invoke-RestMethod ($base+'group_api.php') -WebSession $manager.Session
 Check-Admin (@($savedAfter.files|Where-Object {$_.file_id -eq $copyId}).Count -eq 1) 'Admin dissolution preserves independent saved copy';$groupId=0
 $null=Admin-Call $root 'deleteFile' @{id=$fileId}
 $invalidShare=(Invoke-WebRequest $share.link).Content;Check-Admin ($invalidShare.Contains('分享連結不存在或已失效')) 'File deletion invalidates share'
 Deny-Admin {Invoke-WebRequest ($base+'files.php?file_id='+$fileId+'&preview_token='+$previewMatch.Groups[1].Value)} 403
 Check-Admin (Admin-Call $root 'deleteFolder' @{id=$folderId}).success 'Empty folder can be deleted';$folderId=0
 $private=Invoke-RestMethod ($base+'chat_send.php') -WebSession $member.Session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{recipient_id=$manager.Id;message=$prefix};$messages+=[int]$private.message_id
 $null=Invoke-RestMethod ($base+'chat_message_action.php') -WebSession $member.Session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{csrf_token=$member.CSRF;event_type='message';message_id=$private.message_id;action='react';emoji='👍'}
 $null=Admin-Call $root 'deleteMessage' @{id=$private.message_id}
 Deny-Admin {Invoke-RestMethod ($base+'chat_message_action.php') -WebSession $member.Session -Method Post -ContentType 'application/x-www-form-urlencoded' -Body @{csrf_token=$member.CSRF;event_type='message';message_id=$private.message_id;action='pin'}} 404
 $noticeList=Invoke-RestMethod ($base+'admin.php?format=json&section=notifications&q='+$prefix) -WebSession $root.Session
 foreach($notice in $noticeList.data.items){$null=Admin-Call $root 'deleteNotification' @{id=$notice.id}}
 Check-Admin ((Invoke-RestMethod ($base+'admin.php?format=json&section=notifications&q='+$prefix) -WebSession $root.Session).data.total -eq 0) 'Notification removal and search agree'
 Deny-Admin {Admin-Call $root 'createAnnouncement' @{title=$prefix;content='test';start_date='2031-01-01T10:00';end_date='2030-01-01T10:00'}} 400
 $newAnnouncement=Admin-Call $root 'createAnnouncement' @{title=$prefix;content='Disposable inactive announcement'};$announcementId=[int]$newAnnouncement.id
 Check-Admin ($newAnnouncement.success -and $announcementId -gt 0) 'Create inactive announcement'
 Check-Admin (Admin-Call $root 'editAnnouncement' @{id=$announcementId;title=$prefix;content='Updated';start_date='2030-01-01T10:00';end_date='2031-01-01T10:00';is_active=1}).success 'Edit announcement schedule'
 $null=Admin-Call $root 'deleteAnnouncement' @{id=$announcementId};$announcementId=0
 $literal=Invoke-RestMethod ($base+'admin.php?format=json&section=users&q='+[uri]::EscapeDataString($prefix+'%')) -WebSession $root.Session;Check-Admin ($literal.data.total -eq 0) 'Admin search escapes wildcard characters'
 $logs=Invoke-RestMethod ($base+'admin.php?format=json&section=logs&q='+$prefix) -WebSession $root.Session
 Check-Admin ($logs.data.total -gt 0 -and !($logs|ConvertTo-Json -Depth 8).Contains($testPassword)) 'Audit records contain actions but no passwords'
 $logsPage1=Invoke-RestMethod ($base+'admin.php?format=json&section=logs') -WebSession $root.Session;$logsPage2=Invoke-RestMethod ($base+'admin.php?format=json&section=logs&page=2') -WebSession $root.Session
 Check-Admin ($logsPage1.data.pages -gt 1 -and @($logsPage2.data.items|Where-Object {$_.id -in @($logsPage1.data.items.id)}).Count -eq 0) 'Server-side audit pagination has no overlapping records'
 Write-Output "Admin two-account lifecycle: $checks checks passed, including suspension, restore, role revocation, dependencies and groups."
} finally {
 if($root){
  if($groupId){try{Admin-Call $root 'deleteGroup' @{id=$groupId}|Out-Null}catch{}}
  foreach($messageId in $messages){try{Admin-Call $root 'deleteMessage' @{id=$messageId}|Out-Null}catch{}}
  foreach($cleanupFile in $files){try{Admin-Call $root 'deleteFile' @{id=$cleanupFile}|Out-Null}catch{}}
  if($folderId){try{Admin-Call $root 'deleteFolder' @{id=$folderId}|Out-Null}catch{}}
  if($announcementId){try{Admin-Call $root 'deleteAnnouncement' @{id=$announcementId}|Out-Null}catch{}}
  foreach($cleanupUser in $users){try{Admin-Call $root 'deleteUser' @{id=$cleanupUser}|Out-Null}catch{Write-Warning "Cleanup account $cleanupUser needs attention: $_"}}
 }
 if($fixture -and (Test-Path -LiteralPath $fixture)){Remove-Item -LiteralPath $fixture}
}
