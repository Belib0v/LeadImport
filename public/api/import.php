<?php

declare(strict_types=1);

use App\Database;
use App\Importer;

require __DIR__ . '/../../vendor/autoload.php';

const MAX_FILE_SIZE_MB = 30; // same limit as data-max-size-mb in index.html

header('Content-Type: application/json; charset=utf-8');

try {
    // The request size is known even when PHP has dropped the file; it is only a few hundred bytes over the file.
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_FILE_SIZE_MB * 1024 * 1024) {
        throw new InvalidArgumentException('The file is too large, the limit is ' . MAX_FILE_SIZE_MB . ' MB');
    }
    // PHP's own limits may be lower than ours (by default upload_max_filesize is 2M, post_max_size is 8M).
    if (!$_FILES && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { // the whole body was dropped
        throw new InvalidArgumentException('The file is too large, the server accepts up to ' . ini_get('post_max_size'));
    }
    if (($_FILES['file']['error'] ?? null) === UPLOAD_ERR_INI_SIZE) {
        throw new InvalidArgumentException('The file is too large, the server accepts up to ' . ini_get('upload_max_filesize'));
    }

    $path = $_FILES['file']['tmp_name'] ?? '';
    if (!is_uploaded_file($path)) {
        throw new InvalidArgumentException('No file uploaded');
    }

    $importer = new Importer(Database::fromEnv());
    echo json_encode($importer->import($path));
} catch (InvalidArgumentException $e) { // a problem with the uploaded file: safe to show
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) { // anything else (e.g. a database error) is logged, not shown
    error_log((string)$e);
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
