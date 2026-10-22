<?php

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/preview_tokens.php';


// ============================================================
// 基本驗證
// ============================================================

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('<p style="color:red;">請先登入</p>');
}

$receivedPreview = isset($_GET['received_id']);
$adminPreview = !$receivedPreview && ($_GET['admin'] ?? '') === '1';
$previewOwner = (int)$_SESSION['user_id'];
if ($adminPreview) {
    require_once __DIR__.'/admin_service.php';
    try { requireStorageAdmin($pdo,$previewOwner); }
    catch (DomainException $error) { http_response_code(403); exit('沒有管理權限'); }
    $ownerStmt=$pdo->prepare('SELECT user_id FROM files WHERE file_id=?');
    $ownerStmt->execute([(int)($_GET['file_id']??0)]);
    $previewOwner=(int)$ownerStmt->fetchColumn();
}
if (!isset($_GET['file_id']) && !$receivedPreview) {
    http_response_code(400);
    exit('<p style="color:red;">無效的檔案編號</p>');
}

$fileId = (int) ($receivedPreview ? $_GET['received_id'] : $_GET['file_id']);

if ($fileId <= 0) {
    http_response_code(400);
    exit('<p style="color:red;">無效的檔案編號</p>');
}


// ============================================================
// 取得檔案資訊
// ============================================================

if ($receivedPreview) {
    $stmt = $pdo->prepare('SELECT id AS file_id, recipient_id AS user_id, original_filename, file_path FROM shared_files WHERE id = ? AND recipient_id = ? LIMIT 1');
} else {
    $stmt = $pdo->prepare('SELECT file_id, user_id, original_filename, file_size, stored_filename FROM files WHERE file_id = ? AND user_id = ? LIMIT 1');
}
$stmt->execute([$fileId, $previewOwner]);
$fileRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fileRow) {
    http_response_code(404);
    exit('<p style="color:red;">找不到此檔案，或你沒有權限預覽。</p>');
}


// ============================================================
// 實際檔案路徑
// ============================================================

$filePath = $receivedPreview ? __DIR__ . '/' . $fileRow['file_path'] : __DIR__ . '/uploads/' . $fileRow['stored_filename'];


if (!is_file($filePath)) {
    require_once __DIR__ . '/file_cleanup.php';
    if ($receivedPreview) cleanupMissingSharedFiles($pdo, (int) $_SESSION['user_id'], $fileId);
    elseif (!$adminPreview) cleanupMissingUserFiles($pdo, (int) $_SESSION['user_id']);
    http_response_code(404);
    exit('<p style="color:red;">檔案遺失，無法預覽。</p>');
}


// ============================================================
// 檔案資訊
// ============================================================

$originalName = $fileRow['original_filename'];

$ext = strtolower(
    pathinfo($originalName, PATHINFO_EXTENSION)
);

$fileTitle = htmlspecialchars(
    $originalName,
    ENT_QUOTES,
    'UTF-8'
);


// ============================================================
// Office 格式
// ============================================================

$microsoftOfficeFormats = [
    'doc',
    'docx',
    'xls',
    'xlsx',
    'ppt',
    'pptx'
];

$openDocumentFormats = [
    'odt',
    'ods',
    'odp'
];

$officeFormats = array_merge(
    $microsoftOfficeFormats,
    $openDocumentFormats
);


// 建立資料庫保存、1 小時有效的隨機預覽 Token，不需要環境變數。
try {
    $previewToken = $receivedPreview ? '' : createPreviewToken($pdo, $fileId, $previewOwner);
} catch (Throwable $error) {
    error_log('建立預覽 Token 失敗: ' . $error->getMessage());
    http_response_code(500);
    exit('<p style="color:red;">無法建立預覽連結，請確認資料庫權限。</p>');
}


// ============================================================
// 建立網站相對路徑
// ============================================================

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/preview.php';

$basePath = rtrim(
    str_replace('\\', '/', dirname($scriptName)),
    '/'
);


// 如果是在網站根目錄
if ($basePath === '/' || $basePath === '.') {
    $basePath = '';
}


$previewPath =
    $basePath .
    '/files.php?preview_token=' .
    rawurlencode($previewToken) .
    '&file_id=' .
    $fileId;
if ($receivedPreview) $previewPath = $basePath . '/download_shared_file.php?id=' . $fileId . '&preview=1';

// ============================================================
// 判斷 Zeabur 外部 HTTPS
// ============================================================

$forwardedProto = '';

if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {

    $protoParts = explode(
        ',',
        $_SERVER['HTTP_X_FORWARDED_PROTO']
    );

    $forwardedProto = strtolower(
        trim($protoParts[0])
    );
}


$isHttps =
    $forwardedProto === 'https'
    ||
    (
        !empty($_SERVER['HTTPS'])
        &&
        strtolower($_SERVER['HTTPS']) !== 'off'
    )
    ||
    (
        (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
    );


$scheme = $isHttps ? 'https' : 'http';


// ============================================================
// 取得外部 Host
// ============================================================

$forwardedHost = '';

if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {

    $hostParts = explode(
        ',',
        $_SERVER['HTTP_X_FORWARDED_HOST']
    );

    $forwardedHost = trim(
        $hostParts[0]
    );
}


$host = $forwardedHost !== ''
    ? $forwardedHost
    : ($_SERVER['HTTP_HOST'] ?? 'localhost');


// 避免 Host Header Injection
$host = preg_replace(
    '/[^A-Za-z0-9\.\-\:\[\]]/',
    '',
    $host
);


if ($receivedPreview && in_array($ext, $officeFormats, true)) {
    $receivedToken = createReceivedPreviewToken($pdo, $fileId, (int)$_SESSION['user_id']);
    $previewPath = $basePath . '/received_preview_file.php?id=' . $fileId . '&token=' . rawurlencode($receivedToken);
}

// ============================================================
// 完整公開網址
// 第三方文件檢視器需要從外部連得到
// ============================================================

$fileUrl =
    $scheme .
    '://' .
    $host .
    $previewPath;


// ============================================================
// Office 文件檢視器 URL
// ============================================================

$microsoftViewerUrl =
    'https://view.officeapps.live.com/op/embed.aspx?src=' .
    rawurlencode($fileUrl);

$googleViewerUrl =
    'https://docs.google.com/gview?embedded=1&url=' .
    rawurlencode($fileUrl);

$officeViewerUrl = in_array($ext, $microsoftOfficeFormats, true)
    ? $microsoftViewerUrl
    : $googleViewerUrl;


// ============================================================
// 下載網址
// ============================================================

$downloadUrl =
    'files.php?download=' .
    $fileId;
if ($adminPreview) $downloadUrl = 'admin_download.php?file_id=' . $fileId;
if ($receivedPreview) $downloadUrl = 'download_shared_file.php?id=' . $fileId;

?>
<!DOCTYPE html>

<html lang="zh-TW">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <link
        rel="icon"
        type="image/png"
        href="image/icon.png"
    >

    <title>雲上聊頁來｜檔案預覽 - <?= $fileTitle ?></title>


    <style>

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100%;
            font-family:
                Arial,
                "Microsoft JhengHei",
                sans-serif;

            background: #f5f5f5;
        }


        .preview-container {
            width: 100%;
            padding: 16px;
        }


        .preview-header {

            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;

            margin-bottom: 15px;
        }


        .preview-title {

            margin: 0;

            font-size: 20px;

            word-break: break-all;
        }


        .download-button {

            display: inline-block;

            flex-shrink: 0;

            padding: 9px 16px;

            background: #007bff;

            color: #fff;

            text-decoration: none;

            border-radius: 5px;

            font-size: 14px;
        }


        .download-button:hover {
            background: #0056b3;
        }


        .preview-frame {

            display: block;

            width: 100%;

            height: 75vh;

            min-height: 500px;

            border: 1px solid #ddd;

            background: #fff;
        }


        .image-container {

            width: 100%;

            text-align: center;

            background: #222;

            padding: 20px;

            border-radius: 5px;

            overflow: auto;
        }


        .preview-image {

            display: inline-block;

            max-width: 100%;

            height: auto;
        }


        .video-preview {

            display: block;

            width: 100%;

            max-height: 75vh;

            background: #000;
        }


        .audio-preview {

            width: 100%;
        }


        .text-preview {

            width: 100%;

            max-height: 75vh;

            overflow: auto;

            white-space: pre-wrap;

            word-break: break-word;

            background: #fff;

            padding: 15px;

            border: 1px solid #ddd;

            border-radius: 5px;
        }


        .fallback-message {

            padding: 20px;

            margin-top: 15px;

            background: #fff;

            border: 1px solid #ddd;

            border-radius: 5px;

            line-height: 1.8;
        }


        .fallback-message p {
            margin-top: 0;
        }


        @media (max-width: 768px) {

            .preview-container {
                padding: 10px;
            }


            .preview-header {

                align-items: flex-start;

                flex-direction: column;
            }


            .preview-title {
                font-size: 17px;
            }


            .preview-frame {

                height: 70vh;

                min-height: 400px;
            }

        }

    </style>


    <link
        href="assets/css/responsive.css?v=20261022-2"
        rel="stylesheet"
    >

<link href="assets/css/preview_theme.css?v=20261022-3" rel="stylesheet">
</head>


<body class="preview-page">


<div class="preview-container">


    <div class="preview-header">

        <h3 class="preview-title">
            檔案預覽：<?= $fileTitle ?>
        </h3>


        <a
            href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="download-button"
        >
            下載檔案
        </a>

    </div>


<?php


// ============================================================
// Office 文件
// ============================================================

if (in_array($ext, $officeFormats, true)) {

?>

    <iframe
        src="<?= htmlspecialchars($officeViewerUrl, ENT_QUOTES, 'UTF-8') ?>"
        class="preview-frame"
        frameborder="0"
        title="Office 文件預覽"
        referrerpolicy="no-referrer-when-downgrade"
        sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
        loading="eager"
    ></iframe>


    <div class="fallback-message">

        <p>
            如果 Office 文件預覽沒有載入，
            可能是線上文件檢視器暫時無法取得檔案。
        </p>

        <p>
            支援格式：
            DOC、DOCX、XLS、XLSX、PPT、PPTX。
        </p>

        <a
            href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="download-button"
        >
            下載檔案
        </a>

    </div>

<?php

}


// ============================================================
// 圖片
// ============================================================

elseif (
    in_array(
        $ext,
        ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        true
    )
) {

?>

    <div class="image-container">

        <img
            src="<?= htmlspecialchars($previewPath, ENT_QUOTES, 'UTF-8') ?>"
            alt="<?= $fileTitle ?>"
            class="preview-image"
        >

    </div>

<?php

}


// ============================================================
// PDF
// ============================================================

elseif ($ext === 'pdf') {

?>

    <iframe
        src="<?= htmlspecialchars($previewPath, ENT_QUOTES, 'UTF-8') ?>"
        class="preview-frame"
        title="PDF 預覽"
    ></iframe>

<?php

}


// ============================================================
// Video
// ============================================================

elseif (
    in_array(
        $ext,
        ['mp4', 'webm', 'ogg'],
        true
    )
) {

?>

    <video
        class="video-preview"
        controls
        preload="metadata"
    >

        <source
            src="<?= htmlspecialchars($previewPath, ENT_QUOTES, 'UTF-8') ?>"
        >

        你的瀏覽器不支援影片播放。

    </video>

<?php

}


// ============================================================
// Audio
// ============================================================

elseif (
    in_array(
        $ext,
        ['mp3', 'wav'],
        true
    )
) {

?>

    <audio
        class="audio-preview"
        controls
        preload="metadata"
    >

        <source
            src="<?= htmlspecialchars($previewPath, ENT_QUOTES, 'UTF-8') ?>"
        >

        你的瀏覽器不支援音訊播放。

    </audio>

<?php

}


// ============================================================
// Text
// ============================================================

elseif (
    in_array(
        $ext,
        [
            'txt',
            'log',
            'html',
            'css',
            'js',
            'json',
            'xml'
        ],
        true
    )
) {

    $maxPreviewSize = 2 * 1024 * 1024;


    if (filesize($filePath) <= $maxPreviewSize) {

        $content = file_get_contents($filePath);

?>

        <pre class="text-preview"><?= htmlspecialchars(
            $content,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) ?></pre>

<?php

    } else {

?>

        <div class="fallback-message">

            <p>
                此文字檔案超過 2 MB，
                為避免瀏覽器負擔過大，
                請下載後查看。
            </p>

            <a
                href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>"
                class="download-button"
            >
                下載檔案
            </a>

        </div>

<?php

    }

}


// ============================================================
// 不支援
// ============================================================

else {

?>

    <div class="fallback-message">

        <p>
            此檔案類型目前不支援線上預覽。
        </p>

        <p>
            檔案格式：
            <?= htmlspecialchars(
                strtoupper($ext ?: '未知'),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </p>

        <a
            href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="download-button"
        >
            下載檔案
        </a>

    </div>

<?php

}

?>


</div>


</body>

</html>
