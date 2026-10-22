<?php
session_start();
require_once __DIR__ . '/config.php';
header('Cache-Control: no-store');
require_once __DIR__.'/admin_service.php';
require_once __DIR__.'/admin_data.php';
require_once __DIR__.'/admin_storage.php';
$json=($_GET['format']??'')==='json' || ($_GET['presence']??'')==='1';
if(empty($_SESSION['user_id'])){if($json){http_response_code(401);header('Content-Type: application/json');echo json_encode(['success'=>false,'msg'=>'請先登入']);}else header('Location: login.php');exit;}
try {$actor=requireStorageAdmin($pdo,(int)$_SESSION['user_id']);}catch(DomainException $error){$_SESSION['is_admin']=0;$_SESSION['role']='user';http_response_code(403);if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'msg'=>$error->getMessage()]);}else echo '<meta charset="utf-8"><p>沒有管理權限。<a href="index.php">回到前台</a></p>';exit;}
ensureAdminSchema($pdo);
require_once __DIR__.'/presence_helpers.php';
refreshPresenceStatuses($pdo);
if(($_GET['presence']??'')==='1'){
 $presenceRows=adminQuery($pdo,'SELECT u.user_id,u.is_admin,u.status,u.last_active,COALESCE(a.disabled,0) AS disabled FROM users u LEFT JOIN admin_account_state a ON a.user_id=u.user_id')->fetchAll(PDO::FETCH_ASSOC);
 $online=0;$updates=[];
 foreach($presenceRows as $presenceUser){
  if(!$presenceUser['disabled'] && in_array($presenceUser['status'],['online','idle','away'],true))$online++;
  $updates[]=['id'=>(int)$presenceUser['user_id'],'html'=>adminUserStatus($presenceUser),'last_active'=>$presenceUser['last_active']??'尚無紀錄'];
 }
 header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>true,'users'=>$updates,'online'=>$online],JSON_UNESCAPED_UNICODE);exit;
}
$_SESSION['is_admin']=1;$_SESSION['role']='admin';
$_SESSION['admin_csrf']??=bin2hex(random_bytes(32));
$sections=['dashboard'=>'總覽','users'=>'使用者','storage'=>'檔案管理','folders'=>'資料夾','files'=>'檔案','user_files'=>'使用者檔案','groups'=>'群組','notifications'=>'通知紀錄','chats'=>'私訊紀錄','announcements'=>'公告','logs'=>'操作紀錄'];
$section=(string)($_GET['section']??'dashboard');if(!isset($sections[$section]))$section='dashboard';
$q=mb_substr(trim((string)($_GET['q']??'')),0,200);$page=max(1,(int)($_GET['page']??1));$uid=max(0,(int)($_GET['uid']??0));
$folder=isset($_GET['folder'])?max(0,(int)$_GET['folder']):null;
if($uid===0 || !in_array($section,['storage','folders','files','user_files'],true))$folder=null;
foreach(['delUser','delFile','delFolder','delNotification','delMessage','delAnnouncement'] as $legacy)if(isset($_GET[$legacy])){http_response_code(405);header('Allow: POST');echo '此操作必須透過後臺表單送出。';exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['admin_csrf'],(string)($_POST['admin_csrf']??'')))throw new DomainException('操作驗證失效，請重新整理頁面');
  $response=adminAction($pdo,(int)$actor['user_id'],(string)($_POST['action']??''),$_POST);
 }catch(Throwable $error){$status=$error instanceof DomainException?403:($error instanceof OutOfBoundsException?404:($error instanceof InvalidArgumentException?400:500));http_response_code($status);if($status===500)error_log('Admin action failed: '.$error->getMessage());$response=['success'=>false,'msg'=>$status===500?'操作未完成，資料已保留，請稍後再試':$error->getMessage()];}
 if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode($response,JSON_UNESCAPED_UNICODE);exit;}
 $_SESSION['admin_flash']=$response;header('Location: admin.php?'.http_build_query(['section'=>$section,'q'=>$q,'page'=>$page,'uid'=>$uid,'folder'=>$folder]));exit;
}
if($folder>0 && !adminQuery($pdo,'SELECT folder_id FROM folders WHERE folder_id=? AND user_id=?',[$folder,$uid])->fetchColumn()){
 http_response_code(404);
 if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'msg'=>'找不到此使用者的資料夾'],JSON_UNESCAPED_UNICODE);}
 else echo '<meta charset="utf-8"><p>找不到此使用者的資料夾。<a href="admin.php?section=folders">回到資料夾列表</a></p>';
 exit;
}
$stats=$section==='dashboard'?adminOverview($pdo):[];
$data=$section==='storage'?adminStorageListData($pdo,$q,$page,$uid,$folder):adminListData($pdo,$section==='dashboard'?'logs':$section,$q,$page,$uid,$folder);
$groupMembers=[];$groupDetail=null;
if($section==='groups'&&!empty($_GET['group'])){$groupDetail=adminQuery($pdo,'SELECT id,name FROM chat_groups WHERE id=?',[(int)$_GET['group']])->fetch(PDO::FETCH_ASSOC);if($groupDetail)$groupMembers=adminQuery($pdo,'SELECT u.username,m.role,m.joined_at FROM chat_group_members m JOIN users u ON u.user_id=m.user_id WHERE m.group_id=? ORDER BY m.joined_at',[$groupDetail['id']])->fetchAll(PDO::FETCH_ASSOC);}
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>true,'csrf_token'=>$_SESSION['admin_csrf'],'stats'=>$stats,'data'=>$data,'members'=>$groupMembers],JSON_UNESCAPED_UNICODE);exit;}
if($section==='dashboard')$data['items']=array_slice($data['items'],0,5);
function ah($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function adminUrl(array $changes=[]):string{global $section,$q,$uid,$folder;return 'admin.php?'.http_build_query(array_merge(['section'=>$section,'q'=>$q,'uid'=>$uid,'folder'=>$folder],$changes));}
function adminHidden(string $action,int $id=0):void{echo '<input type="hidden" name="admin_csrf" value="'.ah($_SESSION['admin_csrf']).'"><input type="hidden" name="action" value="'.ah($action).'"><input type="hidden" name="id" value="'.$id.'">';}
function adminButton(string $action,int $id,string $label,string $confirm=''):string{ob_start();echo '<form method="post" class="admin-inline" data-confirm="'.ah($confirm).'">';adminHidden($action,$id);echo '<button class="btn btn-sm '.(str_starts_with($action,'delete')?'btn-danger':'btn-soft').'">'.ah($label).'</button></form>';return ob_get_clean();}
function adminSize($bytes):string{return $bytes>=1073741824?number_format($bytes/1073741824,2).' GB':($bytes>=1048576?number_format($bytes/1048576,1).' MB':number_format($bytes/1024,1).' KB');}
function adminBadge(string $label,string $tone='neutral'):string{return '<span class="admin-badge badge-'.ah($tone).'">'.ah($label).'</span>';}
function adminUserStatus(array $user):string {
 $disabled=(bool)$user['disabled'];
 $presence=$disabled?'offline':($user['status']??'offline');
 $label=['online'=>'在線','offline'=>'離線','idle'=>'閒置','away'=>'請勿打擾','hidden'=>'隱形','dnd'=>'請勿打擾'][$presence]??'狀態未知';
 return '<dl class="admin-user-status"><div><dt>角色</dt><dd>'.($user['is_admin']?'管理員':'一般使用者').'</dd></div><div><dt>帳號</dt><dd class="'.($disabled?'status-danger':'status-success').'">'.($disabled?'已停用':'正常').'</dd></div><div><dt>狀態</dt><dd class="'.(['online'=>'status-online','idle'=>'status-idle','away'=>'status-dnd','dnd'=>'status-dnd','hidden'=>'status-hidden'][$presence]??'status-muted').'">'.ah($label).'</dd></div></dl>';
}
require __DIR__.'/admin_view.php';
