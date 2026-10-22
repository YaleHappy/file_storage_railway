param([Parameter(Mandatory=$true)][string]$Password)
$ErrorActionPreference='Stop'; $base='http://localhost/file_storage/'; $checks=0; $groupId=0; $copyId=0
function Assert-Life($Value,$Label) { if (!$Value) { throw $Label }; $script:checks++ }
function Login-Life($Name) {
 $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
 $null=Invoke-WebRequest ($base+'login.php') -WebSession $session
 $null=Invoke-WebRequest ($base+'login.php') -WebSession $session -Method Post -Body @{username=$Name;password=$Password;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login=1}
 $page=Invoke-WebRequest ($base+'chat_history.php?group=1') -WebSession $session
 $match=[regex]::Match($page.Content,'window.groupBoot\s*=\s*(\{[^<]+\});')
 if (!$match.Success) { throw 'Missing test login token' }
 $boot=$match.Groups[1].Value | ConvertFrom-Json
 @{Session=$session;Token=$boot.csrf;Id=$boot.userId}
}
function Action-Life($Client,$Action,$Payload,[string]$File='') {
 $form=@{csrf_token=$Client.Token;group_id=$script:groupId;action=$Action;payload=($Payload|ConvertTo-Json -Compress)}
 if ($File) { $form.file=Get-Item -LiteralPath $File }
 Invoke-RestMethod ($base+'group_api.php') -WebSession $Client.Session -Method Post -Form $form
}
function Denied-Life([scriptblock]$Operation,[int]$Status) {
 try { & $Operation | Out-Null } catch { if ([int]$_.Exception.Response.StatusCode -eq $Status) { $script:checks++; return }; throw }
 throw "Expected HTTP $Status"
}
$owner=Login-Life 'user1'; $member=Login-Life 'user2'; $activeOwner=$owner
$privateCopyId=0; $privateDeliveryId=0; $officeDeliveryId=0; $officeFixture=$null; $senderCopyId=0
try {
 $beforePrivate=Invoke-RestMethod ($base+'chat_timeline.php?recipient_id='+$member.Id) -WebSession $owner.Session
 $sentPrivate=Invoke-RestMethod ($base+'send_file.php') -Method Post -WebSession $owner.Session -Form @{recipient_id=$member.Id;file_option='upload';file=(Get-Item (Join-Path $PSScriptRoot 'group-smoke-upload.txt'))}
 Assert-Life $sentPrivate.success 'Private file sent for cloud-save test'
 $afterPrivate=Invoke-RestMethod ($base+'chat_timeline.php?recipient_id='+$member.Id) -WebSession $owner.Session
 $privateRows=@($afterPrivate.events|Where-Object {$_.event_type -eq 'file' -and $_.event_id -notin @($beforePrivate.events|Where-Object {$_.event_type -eq 'file'}).event_id})
 Assert-Life ($privateRows.Count -eq 1) 'New private delivery identified'
 $privateDeliveryId=[int]$privateRows[0].event_id
 $beforeCloud=Invoke-RestMethod ($base+'group_api.php') -WebSession $member.Session
 $savedPrivate=Invoke-RestMethod ($base+'add_to_my_cloud.php?id='+$privateDeliveryId) -WebSession $member.Session
 Assert-Life $savedPrivate.success 'Recipient saves received file to own cloud'
 $afterCloud=Invoke-RestMethod ($base+'group_api.php') -WebSession $member.Session
 $newPrivateCopies=@($afterCloud.files|Where-Object {$_.file_id -notin @($beforeCloud.files.file_id) -and $_.original_filename -eq 'group-smoke-upload.txt'})
 Assert-Life ($newPrivateCopies.Count -eq 1) 'Received file creates independent cloud record'
 $privateCopyId=[int]$newPrivateCopies[0].file_id
 Denied-Life { Invoke-RestMethod ($base+'add_to_my_cloud.php?id='+$privateDeliveryId) -WebSession $member.Session } 409
 $previewResponse=Invoke-WebRequest ($base+'download_shared_file.php?id='+$privateDeliveryId+'&preview=1') -WebSession $member.Session
 Assert-Life ($previewResponse.Headers['Content-Type'] -like 'text/plain*') 'Recipient can preview supported file'
 $unifiedPreview=Invoke-WebRequest ($base+'preview.php?received_id='+$privateDeliveryId) -WebSession $member.Session
 Assert-Life ($unifiedPreview.Content.Contains('preview-header') -and $unifiedPreview.Content.Contains('group-smoke-upload.txt')) 'Recipient uses shared preview layout'
 Denied-Life { Invoke-WebRequest ($base+'preview.php?received_id='+$privateDeliveryId) -WebSession $owner.Session } 404
 Denied-Life { Invoke-WebRequest ($base+'download_shared_file.php?id='+$privateDeliveryId+'&preview=1') -WebSession $owner.Session } 404
 $senderBefore=Invoke-RestMethod ($base+'group_api.php') -WebSession $owner.Session
 $senderSave=Invoke-RestMethod ($base+'add_to_my_cloud.php?id='+$privateDeliveryId) -WebSession $owner.Session
 Assert-Life $senderSave.success 'Sender saves own personal-chat attachment'
 $senderAfter=Invoke-RestMethod ($base+'group_api.php') -WebSession $owner.Session
 $senderCopies=@($senderAfter.files|Where-Object {$_.file_id -notin @($senderBefore.files.file_id) -and $_.original_filename -eq 'group-smoke-upload.txt'})
 Assert-Life ($senderCopies.Count -eq 1) 'Sender gets own independent cloud copy'
 $senderCopyId=[int]$senderCopies[0].file_id
 Denied-Life {Invoke-RestMethod ($base+'add_to_my_cloud.php?id='+$privateDeliveryId) -WebSession $owner.Session} 409
 $ownDownload=Invoke-WebRequest ($base+'download_shared_file.php?id='+$privateDeliveryId) -WebSession $owner.Session
 Assert-Life ($ownDownload.StatusCode -eq 200) 'Sender downloads own personal-chat attachment'
 $outsider=Login-Life 'admin'
 $outsiderSave=Invoke-RestMethod ($base+'add_to_my_cloud.php?id='+$privateDeliveryId) -WebSession $outsider.Session
 Assert-Life (!$outsiderSave.success) 'Unrelated user cannot save attachment'
 Denied-Life {Invoke-WebRequest ($base+'download_shared_file.php?id='+$privateDeliveryId) -WebSession $outsider.Session} 404
 Invoke-RestMethod ($base+'bulk_delete.php') -WebSession $owner.Session -Method Post -ContentType 'application/json' -Body (@{ids=@($senderCopyId)}|ConvertTo-Json -Compress)|Out-Null
 $senderCopyId=0
 $officeFixture=Join-Path $env:TEMP ('preview-smoke-'+[guid]::NewGuid().ToString('N')+'.pptx')
 Set-Content -LiteralPath $officeFixture -Value 'Disposable preview authorization fixture'
 $officeBefore=Invoke-RestMethod ($base+'chat_timeline.php?recipient_id='+$member.Id) -WebSession $owner.Session
 $officeSent=Invoke-RestMethod ($base+'send_file.php') -Method Post -WebSession $owner.Session -Form @{recipient_id=$member.Id;file_option='upload';file=(Get-Item -LiteralPath $officeFixture)}
 Assert-Life $officeSent.success 'Office preview fixture delivered'
 $officeAfter=Invoke-RestMethod ($base+'chat_timeline.php?recipient_id='+$member.Id) -WebSession $owner.Session
 $officeDeliveryId=[int](@($officeAfter.events|Where-Object {$_.event_type -eq 'file' -and $_.event_id -notin @($officeBefore.events|Where-Object {$_.event_type -eq 'file'}).event_id})[0].event_id)
 $officePreview=Invoke-WebRequest ($base+'preview.php?received_id='+$officeDeliveryId) -WebSession $member.Session
 Assert-Life ($officePreview.Content.Contains('view.officeapps.live.com/op/embed.aspx') -and !$officePreview.Content.Contains('此文件格式請下載後開啟')) 'Received Office uses same viewer as owned files'
 $officeSource=[regex]::Match($officePreview.Content,'src="(https://view.officeapps[^" ]+)"').Groups[1].Value
 $officeUrl=[uri]::UnescapeDataString(([System.Net.WebUtility]::HtmlDecode($officeSource) -split 'src=',2)[1])
 $officeFile=Invoke-WebRequest $officeUrl
 Assert-Life ($officeFile.Headers['Content-Type'] -like '*presentationml.presentation*') 'Short-lived token serves exact received Office file'
 Denied-Life {Invoke-WebRequest ($base+'received_preview_file.php?id='+$officeDeliveryId+'&token='+('0'*64))} 403
 Denied-Life {Invoke-WebRequest ($base+'preview.php?received_id='+$officeDeliveryId) -WebSession $owner.Session} 404
 $created=Action-Life $owner 'create' @{name=('一次性功能測試 '+[guid]::NewGuid().ToString('N').Substring(0,8));members=@($member.Id)}
 $groupId=[int]$created.group_id
 Assert-Life ($groupId -gt 0) 'Disposable group created'
 $null=Action-Life $owner 'save_settings' @{name='一次性搜尋驗證';announcement='test';mode='mentions'}
 $null=Action-Life $member 'save_settings' @{mode='mute';name='forged';announcement='forged'}
 $snapshot=Invoke-RestMethod ($base+"group_api.php?group_id=$groupId") -WebSession $owner.Session
 Assert-Life ($snapshot.group.name -eq '一次性搜尋驗證' -and $snapshot.group.notification_mode -eq 'mentions') 'Single save changes admin fields and own mode'
 $memberSnapshot=Invoke-RestMethod ($base+"group_api.php?group_id=$groupId") -WebSession $member.Session
 Assert-Life ($memberSnapshot.group.notification_mode -eq 'mute') 'Member save only changes own mode'
 $message=Action-Life $owner 'send' @{content='search-lifecycle-needle @user2'}
 $search=Invoke-RestMethod ($base+"chat_search.php?group_id=$groupId&q=search-lifecycle-needle") -WebSession $member.Session
 Assert-Life (@($search.results).Count -eq 1) 'Member searches actual group history'
 $literal=Invoke-RestMethod ($base+"chat_search.php?group_id=$groupId&q=%25") -WebSession $member.Session
 Assert-Life (@($literal.results).Count -eq 0) 'Search percent is literal'
 $file=Action-Life $owner 'file' @{content='attachment'} (Join-Path $PSScriptRoot 'group-smoke-upload.txt')
 $searchFile=Invoke-RestMethod ($base+"chat_search.php?group_id=$groupId&q=group-smoke-upload") -WebSession $member.Session
 Assert-Life (@($searchFile.results | Where-Object {$_.id -eq $file.message_id}).Count -eq 1) 'Search includes attachment filename'
 $directory=Invoke-RestMethod ($base+'group_api.php') -WebSession $member.Session
 $before=@($directory.files.file_id)
 Denied-Life { Action-Life $member 'save_file' @{message_id=$file.message_id} } 403
 $directory=Invoke-RestMethod ($base+'group_api.php') -WebSession $owner.Session
 $before=@($directory.files.file_id)
 $null=Action-Life $owner 'save_file' @{message_id=$file.message_id}
 $directory=Invoke-RestMethod ($base+'group_api.php') -WebSession $owner.Session
 $copy=@($directory.files | Where-Object {$_.file_id -notin $before -and $_.original_filename -eq 'group-smoke-upload.txt'})
 Assert-Life ($copy.Count -eq 1) 'Independent cloud copy created'
 $copyId=[int]$copy[0].file_id
 Denied-Life { Action-Life $member 'dissolve' @{} } 403
 Denied-Life { Action-Life $member 'transfer_owner' @{user_id=$owner.Id} } 403
 $null=Action-Life $owner 'transfer_owner' @{user_id=$member.Id}; $activeOwner=$member
 Denied-Life { Action-Life $owner 'dissolve' @{} } 403
 $null=Action-Life $owner 'leave' @{}
 Denied-Life { Invoke-WebRequest ($base+"chat_search.php?group_id=$groupId&q=needle") -WebSession $owner.Session } 403
 $null=Action-Life $member 'dissolve' @{}
 Denied-Life { Invoke-WebRequest ($base+"group_api.php?group_id=$groupId") -WebSession $member.Session } 404
 Denied-Life { Invoke-WebRequest ($base+"group_download.php?group_id=$groupId&message_id=$($file.message_id)") -WebSession $member.Session } 404
 $directory=Invoke-RestMethod ($base+'group_api.php') -WebSession $owner.Session
 Assert-Life (@($directory.files | Where-Object {$_.file_id -eq $copyId}).Count -eq 1) 'Dissolve preserves saved cloud copy'
 $groupId=0
 Write-Output "Chat search and group lifecycle: $checks live HTTP checks passed; existing groups unchanged."
} finally {
 if ($senderCopyId) { Invoke-RestMethod ($base+'bulk_delete.php') -WebSession $owner.Session -Method Post -ContentType 'application/json' -Body (@{ids=@($senderCopyId)}|ConvertTo-Json -Compress)|Out-Null }
 if ($officeDeliveryId) { try { Invoke-RestMethod ($base+'chat_message_action.php') -WebSession $owner.Session -Method Post -Body @{csrf_token=$owner.Token;event_type='file';message_id=$officeDeliveryId;action='recall'} | Out-Null } catch {} }
 if ($officeFixture -and (Test-Path -LiteralPath $officeFixture)) { Remove-Item -LiteralPath $officeFixture }
 if ($privateDeliveryId) { try { Invoke-RestMethod ($base+'chat_message_action.php') -WebSession $owner.Session -Method Post -Body @{csrf_token=$owner.Token;event_type='file';message_id=$privateDeliveryId;action='recall'} | Out-Null } catch {} }
 if ($privateCopyId) { Invoke-RestMethod ($base+'bulk_delete.php') -WebSession $member.Session -Method Post -ContentType 'application/json' -Body (@{ids=@($privateCopyId)}|ConvertTo-Json -Compress) | Out-Null }
 if ($groupId) { try { Action-Life $activeOwner 'dissolve' @{} | Out-Null } catch {} }
 if ($copyId) { Invoke-RestMethod ($base+'bulk_delete.php') -WebSession $owner.Session -Method Post -ContentType 'application/json' -Body (@{ids=@($copyId)}|ConvertTo-Json -Compress) | Out-Null }
}
