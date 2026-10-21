<?php

session_start();
require 'config.php';
require_once 'preview_tokens.php';


$user_id = $_SESSION['user_id'] ?? null;

$error = '';


// ============================================================
// 【0】公開檔案預覽
//
// 使用：
// files.php?preview_token=xxx&file_id=123
//
// 不需要登入。
// 原因是線上文件檢視器必須能從外部伺服器
// 直接取得 DOCX / XLSX / PPTX 等文件。
// ============================================================

if (
    isset(
        $_GET['preview_token'],
        $_GET['file_id']
    )
) {

    $file_id = (int) $_GET['file_id'];

    $previewToken = (string) $_GET['preview_token'];


    // --------------------------------------------------------
    // 基本資料檢查
    // --------------------------------------------------------

    if ($file_id <= 0 || !preg_match('/^[a-f0-9]{64}$/', $previewToken)) {

        http_response_code(400);

        header('Content-Type: text/plain; charset=UTF-8');

        exit('無效的預覽請求');
    }


    // --------------------------------------------------------
    // 驗證資料庫保存的隨機 Token 與期限
    // --------------------------------------------------------

    try {
        ensurePreviewTokensTable($pdo);
        $stmt = $pdo->prepare("
            SELECT f.original_filename, f.stored_filename, f.file_size
            FROM preview_tokens pt
            INNER JOIN files f ON f.file_id = pt.file_id
            WHERE pt.token = ?
              AND pt.file_id = ?
              AND pt.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$previewToken, $file_id]);
    } catch (Throwable $error) {
        error_log('驗證預覽 Token 失敗: ' . $error->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('無法驗證預覽連結');
    }

    $file = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$file) {

        http_response_code(403);

        header('Content-Type: text/plain; charset=UTF-8');

        exit('預覽連結無效或已過期');
    }


    // --------------------------------------------------------
    // 實際檔案
    // --------------------------------------------------------

    $path =
        __DIR__ .
        '/uploads/' .
        $file['stored_filename'];


    if (!is_file($path)) {

        http_response_code(404);

        header('Content-Type: text/plain; charset=UTF-8');

        exit('檔案不存在或已刪除');
    }


    // --------------------------------------------------------
    // 非常重要：
    //
    // 清除 PHP Output Buffer。
    //
    // 避免任何：
    // Warning
    // Notice
    // Deprecated
    // BOM
    // 空白
    //
    // 被插入 JPG / PDF / XLSX / DOCX / PPTX。
    // --------------------------------------------------------

    while (ob_get_level() > 0) {

        ob_end_clean();

    }


    // --------------------------------------------------------
    // 取得副檔名
    // --------------------------------------------------------

    $extension = strtolower(
        pathinfo(
            $file['original_filename'],
            PATHINFO_EXTENSION
        )
    );


    // --------------------------------------------------------
    // MIME Type
    // --------------------------------------------------------

    $mimeType = 'application/octet-stream';


    // PHP finfo
    if (function_exists('finfo_open')) {

        $finfo = finfo_open(
            FILEINFO_MIME_TYPE
        );


        if ($finfo !== false) {

            $detectedMime = finfo_file(
                $finfo,
                $path
            );


            if ($detectedMime !== false) {

                $mimeType = $detectedMime;

            }


            /*
             * PHP 8.5
             *
             * 不要：
             *
             * finfo_close($finfo);
             *
             * PHP 8.5 已 Deprecated。
             * finfo object 會自動釋放。
             */

        }

    }


    // --------------------------------------------------------
    // MIME 對照
    //
    // Office OpenXML 本質是 ZIP，
    // finfo 有時會判斷成 application/zip。
    //
    // 所以依副檔名強制修正。
    // --------------------------------------------------------

    $mimeMap = [

        // Word

        'doc' =>
            'application/msword',

        'docx' =>
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',


        // Excel

        'xls' =>
            'application/vnd.ms-excel',

        'xlsx' =>
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',


        // PowerPoint

        'ppt' =>
            'application/vnd.ms-powerpoint',

        'pptx' =>
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',


        // OpenDocument

        'odt' =>
            'application/vnd.oasis.opendocument.text',

        'ods' =>
            'application/vnd.oasis.opendocument.spreadsheet',

        'odp' =>
            'application/vnd.oasis.opendocument.presentation',


        // PDF

        'pdf' =>
            'application/pdf',


        // Images

        'jpg' =>
            'image/jpeg',

        'jpeg' =>
            'image/jpeg',

        'png' =>
            'image/png',

        'gif' =>
            'image/gif',

        'webp' =>
            'image/webp',


        // Text

        'txt' =>
            'text/plain; charset=UTF-8',

        'log' =>
            'text/plain; charset=UTF-8',

        'html' =>
            'text/html; charset=UTF-8',

        'css' =>
            'text/css; charset=UTF-8',

        'js' =>
            'application/javascript; charset=UTF-8',

        'json' =>
            'application/json; charset=UTF-8',

        'xml' =>
            'application/xml; charset=UTF-8',


        // Video

        'mp4' =>
            'video/mp4',

        'webm' =>
            'video/webm',

        'ogg' =>
            'video/ogg',


        // Audio

        'mp3' =>
            'audio/mpeg',

        'wav' =>
            'audio/wav'

    ];


    if (isset($mimeMap[$extension])) {

        $mimeType =
            $mimeMap[$extension];

    }


    // --------------------------------------------------------
    // 原始檔名
    // --------------------------------------------------------

    $originalName =
        basename(
            $file['original_filename']
        );


    // 防止 Header Injection
    $originalName = str_replace(
        ["\r", "\n"],
        '',
        $originalName
    );


    // --------------------------------------------------------
    // ASCII fallback filename
    //
    // 中文真正檔名由 filename*=UTF-8'' 傳送。
    // --------------------------------------------------------

    $fallbackName =
        'preview' .
        ($extension !== ''
            ? '.' . $extension
            : '');


    // --------------------------------------------------------
    // Headers
    // --------------------------------------------------------

    header(
        'Content-Type: ' .
        $mimeType
    );


    $fileSize = filesize($path);
    $rangeStart = 0;
    $rangeEnd = $fileSize - 1;


    // 影片、音訊及大型 PDF 需要支援瀏覽器的分段讀取。
    if (!empty($_SERVER['HTTP_RANGE'])) {

        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $rangeMatch)) {

            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }


        if ($rangeMatch[1] === '' && $rangeMatch[2] !== '') {

            $suffixLength = (int) $rangeMatch[2];
            $rangeStart = max(0, $fileSize - $suffixLength);

        } else {

            $rangeStart = (int) $rangeMatch[1];
        }


        if ($rangeMatch[2] !== '' && $rangeMatch[1] !== '') {

            $rangeEnd = min((int) $rangeMatch[2], $fileSize - 1);
        }


        if ($rangeStart < 0 || $rangeStart > $rangeEnd || $rangeStart >= $fileSize) {

            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }


        http_response_code(206);
        header('Content-Range: bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $fileSize);
    }


    $contentLength = $rangeEnd - $rangeStart + 1;


    header(
        'Content-Length: ' .
        $contentLength
    );


    header(
        'Content-Disposition: inline; ' .
        'filename="' .
        $fallbackName .
        '"; ' .
        "filename*=UTF-8''" .
        rawurlencode($originalName)
    );


    header(
        'Cache-Control: public, max-age=3600'
    );


    header(
        'X-Content-Type-Options: nosniff'
    );


    header(
        'Accept-Ranges: bytes'
    );


    // --------------------------------------------------------
    // 輸出完整檔案或指定的 byte range
    // --------------------------------------------------------

    session_write_close();

    $stream = fopen($path, 'rb');

    if ($stream === false) {

        http_response_code(500);
        exit;
    }

    fseek($stream, $rangeStart);
    $remaining = $contentLength;

    while ($remaining > 0 && !feof($stream)) {

        $chunk = fread($stream, min(8192, $remaining));

        if ($chunk === false || $chunk === '') {
            break;
        }

        echo $chunk;
        $remaining -= strlen($chunk);
    }

    fclose($stream);

    exit;
}


// ============================================================
// 【1】分享 Token 下載
//
// 不需要登入
// ============================================================

if (isset($_GET['token'])) {

    $token = (string) $_GET['token'];


    // --------------------------------------------------------
    // 查詢分享
    // --------------------------------------------------------

    $stmt = $pdo->prepare("
        SELECT
            f.original_filename,
            f.stored_filename
        FROM file_shares s

        INNER JOIN files f
            ON s.file_id = f.file_id

        WHERE s.share_token = ?

          AND (
              s.expires_at IS NULL
              OR
              s.expires_at > NOW()
          )

        LIMIT 1
    ");


    $stmt->execute([
        $token
    ]);


    $file =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if ($file) {

        $filepath =
            __DIR__ .
            '/uploads/' .
            $file['stored_filename'];


        if (is_file($filepath)) {


            // 清除 Buffer
            while (ob_get_level() > 0) {

                ob_end_clean();

            }


            $originalName =
                basename(
                    $file['original_filename']
                );


            $originalName =
                str_replace(
                    ["\r", "\n"],
                    '',
                    $originalName
                );


            $extension =
                strtolower(
                    pathinfo(
                        $originalName,
                        PATHINFO_EXTENSION
                    )
                );


            $fallbackName =
                'download' .
                ($extension
                    ? '.' . $extension
                    : '');


            header(
                'Content-Type: application/octet-stream'
            );


            header(
                'Content-Length: ' .
                filesize($filepath)
            );


            header(
                'Content-Disposition: attachment; ' .
                'filename="' .
                $fallbackName .
                '"; ' .
                "filename*=UTF-8''" .
                rawurlencode($originalName)
            );


            header(
                'X-Content-Type-Options: nosniff'
            );


            readfile($filepath);

            exit;

        }


        $error = '檔案不存在';

    } else {

        $error =
            '分享連結已過期或無效';

    }

}


// ============================================================
// 【2】使用者登入後功能
// ============================================================

if ($user_id) {


    // ========================================================
    // 刪除檔案
    // ========================================================

    if (isset($_GET['delete'])) {

        $file_id =
            (int) $_GET['delete'];


        $stmt = $pdo->prepare("
            SELECT
                stored_filename,
                folder_id
            FROM files
            WHERE file_id = ?
              AND user_id = ?
            LIMIT 1
        ");


        $stmt->execute([
            $file_id,
            $user_id
        ]);


        $file =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if ($file) {

            $path =
                __DIR__ .
                '/uploads/' .
                $file['stored_filename'];


            if (is_file($path)) {

                if (!unlink($path)) {

                    $error =
                        '無法刪除實體檔案';

                }

            }


            // -----------------------------------------------
            // 刪除 DB 紀錄
            // -----------------------------------------------

            $deleteStmt =
                $pdo->prepare("
                    DELETE FROM files
                    WHERE file_id = ?
                      AND user_id = ?
                ");


            $deleteStmt->execute([
                $file_id,
                $user_id
            ]);


            $folder_id =
                (int) (
                    $file['folder_id']
                    ?? 0
                );


            header(
                'Location: index.php?folder=' .
                $folder_id
            );


            exit;

        }


        header(
            'Location: index.php'
        );

        exit;

    }


    // ========================================================
    // 登入使用者下載
    // ========================================================

    if (isset($_GET['download'])) {

        $file_id =
            (int) $_GET['download'];


        $stmt = $pdo->prepare("
            SELECT
                original_filename,
                stored_filename
            FROM files
            WHERE file_id = ?
              AND user_id = ?
            LIMIT 1
        ");


        $stmt->execute([
            $file_id,
            $user_id
        ]);


        $file =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if ($file) {

            $path =
                __DIR__ .
                '/uploads/' .
                $file['stored_filename'];


            if (is_file($path)) {


                // 清除任何輸出
                while (ob_get_level() > 0) {

                    ob_end_clean();

                }


                $originalName =
                    basename(
                        $file['original_filename']
                    );


                $originalName =
                    str_replace(
                        ["\r", "\n"],
                        '',
                        $originalName
                    );


                $extension =
                    strtolower(
                        pathinfo(
                            $originalName,
                            PATHINFO_EXTENSION
                        )
                    );


                $fallbackName =
                    'download' .
                    ($extension
                        ? '.' . $extension
                        : '');


                header(
                    'Content-Type: application/octet-stream'
                );


                header(
                    'Content-Length: ' .
                    filesize($path)
                );


                header(
                    'Content-Disposition: attachment; ' .
                    'filename="' .
                    $fallbackName .
                    '"; ' .
                    "filename*=UTF-8''" .
                    rawurlencode($originalName)
                );


                header(
                    'X-Content-Type-Options: nosniff'
                );


                readfile($path);

                exit;

            }

        }


        $error =
            '無權限或檔案不存在';

    }


    // ========================================================
    // 檔案列表
    // ========================================================

    $stmt = $pdo->prepare("
        SELECT *
        FROM files
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");


    $stmt->execute([
        $user_id
    ]);


    $files =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

}

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


    <title>
        雲上聊頁來｜我的檔案
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link
        href="assets/css/responsive.css?v=20260928-7"
        rel="stylesheet"
    >

</head>


<body class="p-4 bg-light">


<div class="container">


    <h2 class="mb-4">
        我的檔案
    </h2>


    <?php if ($error): ?>

        <div class="alert alert-danger">

            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>

    <?php endif; ?>


    <?php if ($user_id): ?>


        <div class="table-responsive">


            <table class="table table-striped">


                <thead>

                <tr>

                    <th>
                        檔名
                    </th>

                    <th>
                        大小 (Bytes)
                    </th>

                    <th>
                        上傳時間
                    </th>

                    <th>
                        操作
                    </th>

                </tr>

                </thead>


                <tbody>


                <?php foreach ($files as $f): ?>


                    <tr>


                        <td>

                            <?= htmlspecialchars(
                                $f['original_filename'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </td>


                        <td>

                            <?= number_format(
                                $f['file_size']
                            ) ?>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                $f['created_at'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </td>


                        <td>


                            <a
                                href="preview.php?file_id=<?= (int) $f['file_id'] ?>"
                                class="btn btn-sm btn-primary"
                            >
                                預覽
                            </a>


                            <a
                                href="?download=<?= (int) $f['file_id'] ?>"
                                class="btn btn-sm btn-success"
                            >
                                下載
                            </a>


                            <a
                                href="?delete=<?= (int) $f['file_id'] ?>"
                                class="btn btn-sm btn-danger"
                                onclick="return confirm('確定要刪除這個檔案嗎？');"
                            >
                                刪除
                            </a>


                        </td>


                    </tr>


                <?php endforeach; ?>


                </tbody>


            </table>


        </div>


        <a
            href="index.php"
            class="btn btn-secondary"
        >
            返回首頁
        </a>


    <?php else: ?>


        <p class="text-muted">

            此分享連結僅允許下載，
            無法管理檔案。

        </p>


    <?php endif; ?>


</div>


</body>

</html>
