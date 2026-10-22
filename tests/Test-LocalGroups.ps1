param([Parameter(Mandatory=$true)][string]$Password)
$ErrorActionPreference = 'Stop'
$base = 'http://localhost/file_storage/'
$checks = 0
function Assert-Local([bool]$Condition, [string]$Label) {
    if (!$Condition) { throw $Label }
    $script:checks++
}
function Login-Local([string]$Username) {
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $null = Invoke-WebRequest -Uri ($base + 'login.php') -WebSession $session
    $null = Invoke-WebRequest -Uri ($base + 'login.php') -Method Post -WebSession $session -Body @{
        username=$Username; password=$Password; authcode=('stust' + [TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd')); login='1'
    }
    $page = Invoke-WebRequest -Uri ($base + 'chat_history.php?group=1') -WebSession $session
    $match = [regex]::Match($page.Content, 'window.groupBoot\s*=\s*(\{[^<]+\});')
    if (!$match.Success) { throw "Login or chat page failed for $Username" }
    $boot = $match.Groups[1].Value | ConvertFrom-Json
    return @{ Session=$session; Token=$boot.csrf; UserId=$boot.userId }
}
function Get-Group($Client) { Invoke-RestMethod -Uri ($base + 'group_api.php?group_id=1') -WebSession $Client.Session }
function Send-Action($Client, [string]$Action, $Payload, [string]$FilePath='') {
    $form = @{ csrf_token=$Client.Token; group_id='1'; action=$Action; payload=($Payload | ConvertTo-Json -Compress -Depth 8) }
    if ($FilePath) { $form.file = Get-Item -LiteralPath $FilePath }
    Invoke-RestMethod -Uri ($base + 'group_api.php') -WebSession $Client.Session -Method Post -Form $form
}
function Expect-Status([scriptblock]$Operation, [int]$Status) {
    try { & $Operation | Out-Null } catch {
        if ([int]$_.Exception.Response.StatusCode -eq $Status) { $script:checks++; return }
        throw
    }
    throw "Expected HTTP $Status"
}
$owner = Login-Local 'user1'
$member = Login-Local 'user2'
$initial = Get-Group $owner
Assert-Local ($initial.group.name -eq '功能驗證小組 1021') 'Refusing to change a non-test group'
Assert-Local ($initial.group.role -eq 'admin') 'Owner session must be admin'
$originalMode = (Get-Group $member).group.notification_mode
$textId = $null; $replyId = $null; $quietId = $null; $fileId = $null; $missingId = $null; $copyId = $null
$memberRemoved = $false
$privateFileId = $null
$privateMessageIds = @()
$cloudDeliveryId = $null; $cloudGroupMessageId = $null
$root = [IO.Path]::GetFullPath('C:\xampp\htdocs\file_storage\uploads')
$privateRoot = Join-Path $root '.group-files'
$fixture = Join-Path $PSScriptRoot 'group-smoke-upload.txt'
try {
    $sent = Invoke-RestMethod -Uri ($base + 'chat_send.php') -WebSession $owner.Session -Method Post -Body @{recipient_id=$member.UserId;message='功能驗證：個人聊天回覆與表情置頂'}
    $privateMessageIds += [int]$sent.message_id
    $reply = Invoke-RestMethod -Uri ($base + 'chat_send.php') -WebSession $member.Session -Method Post -Body @{recipient_id=$owner.UserId;message='功能驗證：指定回覆';reply_id=$sent.message_id;reply_type='message'}
    $privateMessageIds += [int]$reply.message_id
    $null = Invoke-RestMethod -Uri ($base + 'chat_message_action.php') -WebSession $member.Session -Method Post -Body @{csrf_token=$member.Token;message_id=$sent.message_id;event_type='message';action='react';emoji='👍'}
    $null = Invoke-RestMethod -Uri ($base + 'chat_message_action.php') -WebSession $member.Session -Method Post -Body @{csrf_token=$member.Token;message_id=$sent.message_id;event_type='message';action='pin'}
    $privateTimeline = Invoke-RestMethod -Uri ($base + 'chat_timeline.php?recipient_id=' + $member.UserId) -WebSession $owner.Session
    $originalRow = @($privateTimeline.events | Where-Object {$_.event_type -eq 'message' -and $_.event_id -eq $sent.message_id})[0]
    $replyRow = @($privateTimeline.events | Where-Object {$_.event_type -eq 'message' -and $_.event_id -eq $reply.message_id})[0]
    Assert-Local ($originalRow.pinned -eq 1) 'Private recipient pin visible to sender'
    Assert-Local (@($originalRow.reactions).Count -eq 1) 'Private recipient reaction visible to sender'
    Assert-Local ($replyRow.reply.event_id -eq $sent.message_id) 'Private reply points to selected message'
    $null = Invoke-RestMethod -Uri ($base + 'chat_message_action.php') -WebSession $owner.Session -Method Post -Body @{csrf_token=$owner.Token;message_id=$sent.message_id;event_type='message';action='recall'}
    $privateTimeline = Invoke-RestMethod -Uri ($base + 'chat_timeline.php?recipient_id=' + $owner.UserId) -WebSession $member.Session
    $replyRow = @($privateTimeline.events | Where-Object {$_.event_type -eq 'message' -and $_.event_id -eq $reply.message_id})[0]
    Assert-Local ($null -eq $replyRow.reply) 'Private recall clears quote for other user'
    $beforePrivate = Invoke-RestMethod -Uri ($base + 'chat_timeline.php?recipient_id=' + $member.UserId) -WebSession $owner.Session
    $privateSent = Invoke-RestMethod -Uri ($base + 'send_file.php') -WebSession $owner.Session -Method Post -Form @{recipient_id=$member.UserId;file_option='upload';file=(Get-Item -LiteralPath $fixture)}
    Assert-Local ($privateSent.success) 'Private conversation uploads attachment'
    $afterPrivate = Invoke-RestMethod -Uri ($base + 'chat_timeline.php?recipient_id=' + $member.UserId) -WebSession $owner.Session
    $newPrivate = @($afterPrivate.events | Where-Object {$_.event_type -eq 'file' -and $_.original_filename -eq 'group-smoke-upload.txt' -and $_.event_id -notin @($beforePrivate.events | Where-Object {$_.event_type -eq 'file'}).event_id})
    Assert-Local ($newPrivate.Count -eq 1) 'Private attachment appears once in timeline'
    $privateFileId = [int]$newPrivate[0].event_id
    $privateDownload = Invoke-WebRequest -Uri ($base + 'download_shared_file.php?id=' + $privateFileId) -WebSession $member.Session
    Assert-Local ($privateDownload.StatusCode -eq 200 -and $privateDownload.RawContentLength -gt 0) 'Private recipient downloads attachment'
    $null = Invoke-RestMethod -Uri ($base + 'chat_message_action.php') -WebSession $owner.Session -Method Post -Body @{csrf_token=$owner.Token;message_id=$privateFileId;event_type='file';action='recall'}
    $afterRecall = Invoke-RestMethod -Uri ($base + 'chat_timeline.php?recipient_id=' + $member.UserId) -WebSession $owner.Session
    Assert-Local (@($afterRecall.events | Where-Object {$_.event_type -eq 'file' -and [int]$_.event_id -eq $privateFileId}).Count -eq 0) 'Recalled private attachment removed from timeline'
    $privateFileId = $null
    $null = Send-Action $member 'preference' @{mode='mentions'}
    $created = Send-Action $owner 'send' @{content='本機驗證：@user2 請確認這是一則可丟棄的測試訊息。'}
    $textId = [int]$created.message_id
    $received = Get-Group $member
    $message = @($received.messages | Where-Object { [int]$_.id -eq $textId })[0]
    Assert-Local ($message.content -like '*本機驗證*') 'Second independent session receives group message'
    Assert-Local (@($message.mentions | Where-Object {[int]$_.user_id -eq [int]$member.UserId}).Count -eq 1) 'Chinese punctuation mention parsed'
    $notifications = Invoke-RestMethod -Uri ($base + 'check_notifications.php') -WebSession $member.Session
    Assert-Local (@($notifications.notifications | Where-Object {$_.group_id -eq 1 -and $_.message -like '*提及*'}).Count -gt 0) 'Mention-only notification is delivered'
    Expect-Status { Send-Action $member 'edit' @{message_id=$textId;content='forbidden'} } 403
    $null = Send-Action $owner 'edit' @{message_id=$textId;content='本機驗證：@user2 訊息編輯完成。'}
    $null = Send-Action $member 'react' @{message_id=$textId;emoji='👍'}
    $null = Send-Action $member 'read' @{last_id=$textId}
    $message = @((Get-Group $owner).messages | Where-Object {[int]$_.id -eq $textId})[0]
    Assert-Local ($message.content -like '*編輯完成*') 'Edit visible to group'
    Assert-Local (@($message.reactions).Count -gt 0) 'Reaction persisted'
    Assert-Local (@($message.read_by | Where-Object {[int]$_.user_id -eq [int]$member.UserId}).Count -gt 0) 'Second session read receipt persisted'
    $reply = Send-Action $member 'send' @{content='回覆驗證完成';reply_to_id=$textId}
    $replyId = [int]$reply.message_id
    $replyRow = @((Get-Group $owner).messages | Where-Object {[int]$_.id -eq $replyId})[0]
    Assert-Local ([int]$replyRow.reply.id -eq $textId) 'Reply references correct group message'
    $null = Send-Action $member 'preference' @{mode='mute'}
    $mutedNotices = (Invoke-RestMethod -Uri ($base + 'check_notifications.php') -WebSession $member.Session).notifications
    Assert-Local (@($mutedNotices | Where-Object {$_.group_id -eq 1 -and [int]$_.sound_allowed -ne 0}).Count -eq 0) 'Existing group notifications become silent when muted'
    $beforeNoticeIds = @((Invoke-RestMethod -Uri ($base + 'check_notifications.php') -WebSession $member.Session).notifications.id)
    $quiet = Send-Action $owner 'send' @{content='@everyone 靜音模式測試'}; $quietId=[int]$quiet.message_id
    $quietRow = @((Get-Group $owner).messages | Where-Object {[int]$_.id -eq $quietId})[0]
    Assert-Local (@($quietRow.mentions).Count -eq 2) 'Discord everyone resolves active group members'
    $afterNotices = (Invoke-RestMethod -Uri ($base + 'check_notifications.php') -WebSession $member.Session).notifications
    Assert-Local (@($afterNotices | Where-Object {$_.id -notin $beforeNoticeIds -and $_.group_id -eq 1}).Count -eq 0) 'Mute suppresses even mention-all notifications'
    $null = Send-Action $member 'preference' @{mode=$originalMode}
    $null = Send-Action $owner 'pin' @{message_id=$textId}
    Assert-Local (@((Get-Group $member).pins | Where-Object {[int]$_.id -eq $textId}).Count -eq 1) 'Pin visible to member'
    $null = Send-Action $owner 'pin' @{message_id=$textId}
    Expect-Status { Send-Action $member 'settings' @{name='forbidden';announcement='forbidden'} } 403
    $null = Send-Action $owner 'settings' @{name=$initial.group.name;announcement='本機公告驗證完成'}
    Assert-Local ((Get-Group $member).group.announcement -eq '本機公告驗證完成') 'Announcement visible to member'

    $priorAttachments = @(Get-ChildItem -LiteralPath $privateRoot -File | ForEach-Object {$_.FullName})
    $file = Send-Action $owner 'file' @{content='附件驗證'} $fixture
    $fileId = [int]$file.message_id
    $newAttachments = @(Get-ChildItem -LiteralPath $privateRoot -File | Where-Object {$_.FullName -notin $priorAttachments -and $_.Name -match '^[a-f0-9]{48}\.bin$'})
    Assert-Local ($newAttachments.Count -eq 1) 'Uploaded private attachment identified'
    Expect-Status { Invoke-WebRequest -Uri ($base + 'uploads/.group-files/' + $newAttachments[0].Name) -WebSession $member.Session } 403
    $download = Invoke-WebRequest -Uri ($base + "group_download.php?group_id=1&message_id=$fileId") -WebSession $member.Session
    Assert-Local ($download.StatusCode -eq 200 -and $download.RawContentLength -gt 0) 'Member downloads actual uploaded attachment'
    $beforeFiles = @( (Invoke-RestMethod -Uri ($base + 'group_api.php') -WebSession $member.Session).files.file_id )
    $null = Send-Action $member 'save_file' @{message_id=$fileId}
    $newCopies = @((Invoke-RestMethod -Uri ($base + 'group_api.php') -WebSession $member.Session).files | Where-Object {$_.file_id -notin $beforeFiles -and $_.original_filename -eq 'group-smoke-upload.txt'})
    Assert-Local ($newCopies.Count -eq 1) 'Group attachment saved to own cloud'
    $copyId = [int]$newCopies[0].file_id
    $beforeCloud = Invoke-RestMethod -Uri ($base+'chat_timeline.php?recipient_id='+$owner.UserId) -WebSession $member.Session
    $cloudSend = Invoke-RestMethod -Uri ($base+'send_file.php') -Method Post -WebSession $member.Session -Body @{recipient_id=$owner.UserId;file_option='select';existing_file_id=$copyId}
    Assert-Local $cloudSend.success 'Private chat sends own cloud file'
    $cloudTimeline = Invoke-RestMethod -Uri ($base+'chat_timeline.php?recipient_id='+$owner.UserId) -WebSession $member.Session
    $cloudRows = @($cloudTimeline.events | Where-Object {$_.event_type -eq 'file' -and $_.event_id -notin @($beforeCloud.events | Where-Object {$_.event_type -eq 'file'}).event_id})
    Assert-Local ($cloudRows.Count -eq 1) 'Cloud file creates private delivery'
    $cloudDeliveryId = [int]$cloudRows[0].event_id
    $cloudDownload = Invoke-WebRequest -Uri ($base+'download_shared_file.php?id='+$cloudDeliveryId) -WebSession $owner.Session
    Assert-Local ($cloudDownload.StatusCode -eq 200 -and $cloudDownload.RawContentLength -gt 0) 'Private recipient downloads cloud attachment'
    $deniedCloud = Invoke-RestMethod -Uri ($base+'send_file.php') -Method Post -WebSession $owner.Session -Body @{recipient_id=$member.UserId;file_option='select';existing_file_id=$copyId}
    Assert-Local (!$deniedCloud.success) 'Cannot send another users cloud file'
    $cloudGroupSend = Send-Action $member 'file' @{cloud_file_id=$copyId;content='雲端附件驗證'}
    $cloudGroupMessageId = [int]$cloudGroupSend.message_id
    $cloudGroupDownload = Invoke-WebRequest -Uri ($base+"group_download.php?group_id=1&message_id=$cloudGroupMessageId") -WebSession $owner.Session
    Assert-Local ($cloudGroupDownload.StatusCode -eq 200 -and $cloudGroupDownload.RawContentLength -gt 0) 'Group receives selected cloud file'
    $null = Send-Action $member 'recall' @{message_id=$cloudGroupMessageId}; $cloudGroupMessageId=$null
    $null = Send-Action $owner 'remove_member' @{user_id=[int]$member.UserId}; $memberRemoved=$true
    Expect-Status { Get-Group $member } 404
    Expect-Status { Invoke-WebRequest -Uri ($base + "group_download.php?group_id=1&message_id=$fileId") -WebSession $member.Session } 404
    $null = Send-Action $owner 'add_member' @{user_id=[int]$member.UserId}; $memberRemoved=$false
    Assert-Local ((Get-Group $member).success) 'Re-added member regains group access'
    $null = Send-Action $owner 'recall' @{message_id=$fileId}; $fileId=$null
    Expect-Status { Invoke-WebRequest -Uri ($base + "group_download.php?group_id=1&message_id=$($file.message_id)") -WebSession $member.Session } 404
    Assert-Local (@((Invoke-RestMethod -Uri ($base + 'group_api.php') -WebSession $member.Session).files | Where-Object {[int]$_.file_id -eq $copyId}).Count -eq 1) 'Saved cloud copy survives recall'

    $beforePaths = @(Get-ChildItem -LiteralPath $privateRoot -File | ForEach-Object {$_.FullName})
    $missing = Send-Action $owner 'file' @{content='缺檔清理驗證'} $fixture
    $missingId = [int]$missing.message_id
    $newPaths = @(Get-ChildItem -LiteralPath $privateRoot -File | Where-Object {$_.FullName -notin $beforePaths})
    Assert-Local ($newPaths.Count -eq 1) 'Exactly one disposable attachment created'
    $path = [IO.Path]::GetFullPath($newPaths[0].FullName)
    if (!$path.StartsWith([IO.Path]::GetFullPath($privateRoot) + '\') -or (Get-FileHash -LiteralPath $path).Hash -ne (Get-FileHash -LiteralPath $fixture).Hash) { throw 'Refusing to remove any non-test file' }
    Remove-Item -LiteralPath $path
    Assert-Local (@((Get-Group $member).messages | Where-Object {[int]$_.id -eq $missingId}).Count -eq 0) 'Missing group attachment removed from timeline and DB'
    $list = Invoke-RestMethod -Uri ($base + 'group_api.php?group_id=1&view=files') -WebSession $member.Session
    Assert-Local (@($list.files | Where-Object {[int]$_.id -eq $missingId}).Count -eq 0) 'Missing group attachment removed from files list'
    Expect-Status { Invoke-WebRequest -Uri ($base + "group_download.php?group_id=1&message_id=$missingId") -WebSession $member.Session } 404
    $missingId=$null
    Write-Output ("Local Apache/MySQL integration: $checks checks passed; two independent user sessions.")
} finally {
    if ($cloudDeliveryId) { try { Invoke-RestMethod -Uri ($base+'chat_message_action.php') -WebSession $member.Session -Method Post -Body @{csrf_token=$member.Token;message_id=$cloudDeliveryId;event_type='file';action='recall'} | Out-Null } catch {} }
    if ($cloudGroupMessageId) { try { Send-Action $member 'recall' @{message_id=$cloudGroupMessageId} | Out-Null } catch {} }
    foreach ($messageId in $privateMessageIds) { foreach ($client in @($owner,$member)) { try { Invoke-RestMethod -Uri ($base + 'chat_message_action.php') -WebSession $client.Session -Method Post -Body @{csrf_token=$client.Token;message_id=$messageId;event_type='message';action='recall'} | Out-Null } catch {} } }
    if ($privateFileId) { try { Invoke-RestMethod -Uri ($base + 'chat_message_action.php') -WebSession $owner.Session -Method Post -Body @{csrf_token=$owner.Token;message_id=$privateFileId;event_type='file';action='recall'} | Out-Null } catch {} }
    if ($memberRemoved) { try { Send-Action $owner 'add_member' @{user_id=[int]$member.UserId} | Out-Null } catch {} }
    if ($replyId) { try { Send-Action $member 'recall' @{message_id=$replyId} | Out-Null } catch {} }
    if ($quietId) { try { Send-Action $owner 'recall' @{message_id=$quietId} | Out-Null } catch {} }
    if ($textId) { try { Send-Action $owner 'recall' @{message_id=$textId} | Out-Null } catch {} }
    if ($fileId) { try { Send-Action $owner 'recall' @{message_id=$fileId} | Out-Null } catch {} }
    if ($missingId) { try { Send-Action $owner 'recall' @{message_id=$missingId} | Out-Null } catch {} }
    if ($copyId) { try { Invoke-RestMethod -Uri ($base + 'bulk_delete.php') -WebSession $member.Session -Method Post -ContentType 'application/json' -Body (@{ids=@($copyId)} | ConvertTo-Json -Compress) | Out-Null } catch {} }
    try { Send-Action $member 'preference' @{mode=$originalMode} | Out-Null } catch {}
    try { Send-Action $owner 'settings' @{name=$initial.group.name;announcement=$initial.group.announcement} | Out-Null } catch {}
}
