<?php
session_start();
require 'config.php';
header('Content-Type: application/json');

// 1) 未登入 → 拒絕
if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'msg' => '請先登入']);
  exit;
}

// 2) 接收對話對象 ID
$myId = $_SESSION['user_id'];
$recipientId = isset($_GET['recipient_id']) ? (int)$_GET['recipient_id'] : 0;
if ($recipientId <= 0) {
  echo json_encode(['success' => false, 'msg' => '缺少對象 ID']);
  exit;
}

// 3) 從資料庫撈取雙方的對話紀錄
$stmt = $pdo->prepare("
  SELECT *
  FROM chat_messages
  WHERE (sender_id = :me AND recipient_id = :you)
     OR (sender_id = :you AND recipient_id = :me)
  ORDER BY sent_at ASC
");
$stmt->execute([
  ':me'  => $myId,
  ':you' => $recipientId
]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4) 回傳 JSON
echo json_encode([
  'success'  => true,
  'messages' => $messages
]);
