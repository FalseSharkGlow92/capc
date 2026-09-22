<?php
$config = [
    'api_host' => 'https://api.masqrad.io',
    'api_key' => 'MASQRAD-01M0CXC6W0JFQ6BV3FWW10QGZX',
    'campaign_id' => 'campaign-id-a4ad6b12-dca7-4354-a9b7-7ac67a2ae9a3',
    'integration' => 'native',
    'version' => '1.0.8'
];
class MASQRAD
{
    private $config;
    private $inDiag = false;
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function run()
    {
        $this->checkRecursion();
        $this->hidePhpSignature();
        if ($this->shouldIgnoreRequest()) {
            return;
        }
        $this->disableCache();
        $this->ignoreUnsupportedMethods();

        if (isset($_GET['worker'])) {
            $mode = isset($_GET['worker']) ? $_GET['worker'] : 'quick';
            $resp = $this->apiRequest('/worker.js?mode=' . urlencode($mode), ['Accept-Encoding' => 'gzip, deflate, br'], null, true, 'GET');
            $this->abortOnApiFailure($resp, 'worker.js');
            http_response_code(200);
            header('Content-Type: application/javascript; charset=utf-8');

            header("Content-Security-Policy: " .
                "default-src 'none'; " .
                "script-src 'self'; " .
                "worker-src 'self'; " .
                "connect-src 'self'; " .
                "img-src 'self' data:; " .
                "style-src 'self'; " .
                "base-uri 'none'; " .
                "frame-ancestors 'none'"
            );

            echo isset($resp['body']) ? $resp['body'] : '';
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_SESSION_INIT'])) {
            $raw = file_get_contents('php://input');
            $data = json_encode($this->buildClientData(json_decode($raw, true)), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $response = $this->apiRequest("/check", ['Content-Type' => 'application/json'], $data, false, 'POST');
            $this->abortOnApiFailure($response, 'check(session-init)');
            $response = json_decode(isset($response['body']) ? $response['body'] : '', true);
            $response = is_array($response) ? $response : [];
            $this->showContent($response);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ((isset($_SERVER['HTTP_X_MASQRAD_DEBUG']) ? $_SERVER['HTTP_X_MASQRAD_DEBUG'] : '') === 'run')) {
            $this->runDiagnostic();
            return;
        } else if (!empty($_GET['key']) && $_GET['key'] === $this->config['api_key']) {
            $this->handleDiagnosticRequests();
            return;
        }

        if (empty($_GET['debug'])) {
            $payload = $this->buildClientData(null);
            if (isset($_GET['noscript'])) {
                $payload['noscript'] = true;
            }
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $response = $this->apiRequest("/check", ['Content-Type' => 'application/json'], $json, false, 'POST');
            $this->abortOnApiFailure($response, 'check(normal)');
            $response = json_decode(isset($response['body']) ? $response['body'] : '', true);
            $response = is_array($response) ? $response : [];
        }

        if (!empty($response) && empty($_GET['debug'])) {
            if (!empty($response['cookie'])) {
                $cookieName = !empty($response['cookieName']) ? $response['cookieName'] : 'sid';
                setcookie($cookieName, $response['cookie'], time() + (30 * 24 * 60 * 60), "/");
            }
            $this->showContent($response);
        }
    }

    private function checkRecursion()
    {
        if (defined('SCRIPT_LOADED')) {
            die('Recursion Error');
        }

        define('SCRIPT_LOADED', true);
    }

    private function hidePhpSignature()
    {
        @ini_set('expose_php', '0');
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
    }

    private function handleDiagnosticRequests()
    {
        if (empty($_GET['debug'])) {
            die('Debug Request Error');
        }

        switch ($_GET['debug']) {
            case 'cache':
                if ( (!empty($_SERVER['HTTP_X_MASQRAD_TEST_HEADER']) && $_SERVER['HTTP_X_MASQRAD_TEST_HEADER'] === 'cache')
                    || (!empty($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT'] === 'MASQRAD_DIAGNOSTIC')) {
                    echo $this->generateDynamicResponse('cache_');
                    exit();
                } else {
                    echo 'No custom headers';
                    exit();
                }
            case 'cookie':
                if (!empty($_SERVER['HTTP_X_MASQRAD_TEST_HEADER']) && $_SERVER['HTTP_X_MASQRAD_TEST_HEADER'] === 'cookie') {
                    setcookie("MasqradCookie", "masqrad", time() + 3600, "/", "", false, false);
                    echo "Cookie set: MasqradCookie=masqrad";
                    exit();
                } else if (empty($_SERVER['HTTP_X_MASQRAD_TEST_HEADER']) && !empty($_COOKIE['MasqradCookie'])) {
                    $cookie = $_COOKIE['MasqradCookie'];
                    echo "Cookie recognized: MasqradCookie=$cookie";
                    exit();
                } else {
                    echo 'No custom headers';
                    exit();
                }
            case 'ping':
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'ts' => time()],  JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit();
        }
    }

    private function disableCache()
    {
           header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
           header('Cache-Control: post-check=0, pre-check=0', false);
           header('Pragma: no-cache');
           header('Expires: 0');
           header('Surrogate-Control: no-store');
    }

    private function shouldIgnoreRequest()
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $fetchMode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? null;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if (isset($_GET['worker'])) {
            return false;
        }

        if ($method === 'POST' && isset($_SERVER['HTTP_X_SESSION_INIT'])) {
            return false;
        }

        if ($method === 'POST' && (($_SERVER['HTTP_X_MASQRAD_DEBUG'] ?? '') === 'run')) {
            return false;
        }

        if (!empty($_GET['key']) && isset($this->config['api_key']) && $_GET['key'] === $this->config['api_key']) {
            return false;
        }

        $ignoredPaths = [
            '/favicon.ico',
            '/apple-touch-icon.png',
            '/apple-touch-icon-precomposed.png',
            '/manifest.json',
            '/site.webmanifest',
            '/robots.txt',
            '/service-worker.js',
            '/sw.js',
        ];

        if (in_array($path, $ignoredPaths, true)) {
            http_response_code(204);
            header('Content-Type: text/plain; charset=utf-8');
            exit;
        }

        if ($fetchMode !== null && $fetchMode !== 'navigate' && $fetchMode !== 'nested-navigate') {
            return true;
        }

        return false;
    }

    private function ignoreUnsupportedMethods()
    {
        if (in_array($_SERVER['REQUEST_METHOD'], ['HEAD', 'PUT', 'DELETE'], true)) {
            http_response_code(200);
            exit;
        }
    }

    private function showContent($response)
    {
        switch ($response['action']) {
            case 'local' :
                $this->renderLocal($response['content']);
                exit;
            case 'http_302':
                header("Location: {$response['content']}", true, 302);
                exit;
            case 'http_302_privacy':
                header("Referrer-Policy: no-referrer");
                header("Content-Security-Policy: referrer no-referrer");
                header("Location: {$response['content']}", true, 302);
                exit;
            case 'nginx_redirect':
                $target = trim((string)($response['content'] ?? ''));
                if ($target === '') {
                    http_response_code(500);
                    exit('Empty internal redirect target');
                }
                if ($target[0] !== '/') {
                    $target = '/' . $target;
                }
                header('X-Accel-Redirect: ' . $target);
                exit;
            case 'meta':
                echo "<!DOCTYPE html><head><title></title><meta http-equiv=\"refresh\" content=\"0;url={$response['content']}\"></head><body></body></html>";
                exit;
            case 'meta_privacy':
                header("Referrer-Policy: no-referrer");
                header("Content-Security-Policy: referrer no-referrer");
                echo "<!DOCTYPE html><head><title></title><meta http-equiv=\"refresh\" content=\"0;url={$response['content']}\"></head><body></body></html>";
                exit;
            case 'js_redirect' :
                echo "<!DOCTYPE html><head><title></title><noscript><meta http-equiv=\"refresh\" content=\"0;url=?noscript=1\"></noscript><script>window.location.href = \"{$response['content']}\";</script></head><body></body></html>";
                exit;
            case 'js_replace' :
                echo "<!DOCTYPE html><head><title></title><noscript><meta http-equiv=\"refresh\" content=\"0;url=?noscript=1\"></noscript><script>window.location.replace(\"{$response['content']}\");</script></head><body></body></html>";
                exit;
            case 'iframe':
                echo "<!DOCTYPE html><head><title></title></head><body style=\"margin:0;padding:0\"><iframe  src=\"{$response['content']}\" style=\"position:fixed;inset:0;border:0;width:100vw;height:100vh\" rel=\"noreferrer noopener \"></iframe></body></html>";
                exit;
            case 'iframe-privacy':
                header("Referrer-Policy: no-referrer");
                header("Content-Security-Policy: referrer no-referrer");
                echo "<!DOCTYPE html><head><title></title></head><body style=\"margin:0;padding:0\"><iframe src=\"{$response['content']}\" style=\"position:fixed;inset:0;border:0;width:100vw;height:100vh\" rel=\"noreferrer noopener \"></iframe></body></html>";
                exit;
            case 'load_content':
                $this->fetchAndEcho($response['content']);
                exit;
            case 'http_status':
                $code = isset($response['content']) ? (int)$response['content'] : 204;
                if ($code < 100 || $code > 599) $code = 204;
                http_response_code($code);
                header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Pragma: no-cache");
                exit;
            case 'json':
                header('Content-Type: application/json; charset=utf-8');
                $content = isset($response['content']) ? $response['content'] : null;
                $access  = isset($response['access'])  ? $response['access']  : null;
                $allow  = isset($response['allow'])  ? $response['allow']  : null;
                echo json_encode([
                  'content' => $content,
                  'allow'  => $allow,
                  'access'  => $access,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            case 'collect' :
                echo "<!DOCTYPE html><head><style>html,body{margin:0;width:100%;height:100%;background:#fff;}body{display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial;}.wrap{display:flex;flex-direction:column;align-items:center;gap:12px;}.spinner{width:28px;height:28px;border-radius:50%;border:3px solid rgba(0,0,0,.15);border-top-color:rgba(0,0,0,.65);animation:spin .5s linear infinite;}@keyframes spin{to{transform:rotate(360deg)}}.text{font-size:13px;opacity:.7;user-select:none;}</style></head><body><div class=\"wrap\" aria-busy=\"true\"><div class=\"spinner\"></div></div><script src=\"{$response['content']}\"></script><noscript><meta http-equiv=\"refresh\" content=\"0;url=?noscript=1\"></noscript></body></html>";
                exit;
            case 'nothing':
                $GLOBALS['__MASQRAD_RESULT__'] = [
                   'allow' => array_key_exists('allow', $response)
                       ? filter_var($response['allow'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                       : null,
                   'content' => isset($response['content']) ? $response['content'] : null,
                ];
                return;
            case 'download_local':
                $this->downloadLocal($response['content']);
                exit;
            case 'final':
                header('Content-Type: application/json; charset=utf-8');
                echo $response['content'];
                exit;
            default:
                http_response_code(500);
                exit("Unsupported action format: {$response['action']}");
        }
    }

    private function downloadLocal($inputPath)
    {
        $inputPath = trim((string)$inputPath);
        if ($inputPath === '') {
            http_response_code(404);
            echo 'Invalid local file path';
            exit;
        }

        $docRoot = rtrim(realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);
        if (!$docRoot) {
            http_response_code(500);
            echo 'Document root not found';
            exit;
        }

        $u = parse_url($inputPath);
        $path = $u['path'] ?? '';

        if ($path === '') {
            http_response_code(404);
            echo 'Invalid local file path';
            exit;
        }

        $path = str_replace('\\', '/', $path);

        if (strpos($path, './') === 0) {
            $path = substr($path, 2);
        }

        if (strpos($path, '/') !== 0) {
            $path = '/' . $path;
        }

        $target = realpath($docRoot . $path);

        if ($target === false) {
            http_response_code(404);
            echo 'Local file not found';
            exit;
        }

        if (strpos($target, $docRoot . DIRECTORY_SEPARATOR) !== 0 && $target !== $docRoot) {
            http_response_code(403);
            echo 'Access denied';
            exit;
        }

        if (is_dir($target)) {
            foreach (['index.php', 'index.html', 'index.htm'] as $index) {
                $candidate = realpath($target . DIRECTORY_SEPARATOR . $index);
                if ($candidate && is_file($candidate) && is_readable($candidate)) {
                    $target = $candidate;
                    break;
                }
            }
        }

        if (!is_file($target) || !is_readable($target)) {
            http_response_code(404);
            echo 'Local file not found';
            exit;
        }

        $fileName = basename($target);
        $fileSize = filesize($target);
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        $contentType = 'application/octet-stream';

        switch ($ext) {
            case 'php':
                $contentType = 'application/x-httpd-php';
                break;
            case 'html':
            case 'htm':
                $contentType = 'text/html; charset=utf-8';
                break;
            case 'js':
                $contentType = 'application/javascript; charset=utf-8';
                break;
            case 'json':
                $contentType = 'application/json; charset=utf-8';
                break;
            case 'txt':
                $contentType = 'text/plain; charset=utf-8';
                break;
            case 'zip':
                $contentType = 'application/zip';
                break;
        }

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: public');

        if ($fileSize !== false) {
            header('Content-Length: ' . $fileSize);
        }

        readfile($target);
        exit;
    }

    private function renderLocal($inputPath)
    {
       $inputPath = trim($inputPath);
       if ($inputPath === '') {
           http_response_code(404);
           echo 'Invalid local file path';
           exit;
       }

       $docRoot = rtrim(realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);
       if (!$docRoot) {
           http_response_code(500);
           echo 'Document root not found';
           exit;
       }

       $u = parse_url($inputPath);
       $path = $u['path'] ?? '';
       $query = $u['query'] ?? '';

       if ($path === '') {
           http_response_code(404);
           echo 'Invalid local file path';
           exit;
       }

       $path = str_replace('\\', '/', $path);

       if (strpos($path, './') === 0) {
           $path = substr($path, 2);
       }

       if (strpos($path, '/') !== 0) {
           $path = '/' . $path;
       }

       $target = realpath($docRoot . $path);

       if ($target === false) {
           http_response_code(404);
           echo 'Local file not found';
           exit;
       }

       if (strpos($target, $docRoot . DIRECTORY_SEPARATOR) !== 0 && $target !== $docRoot) {
           http_response_code(403);
           echo 'Access denied';
           exit;
       }

       if (is_dir($target)) {
           foreach (['index.php', 'index.html', 'index.htm'] as $index) {
               $candidate = realpath($target . DIRECTORY_SEPARATOR . $index);
               if ($candidate && is_file($candidate) && is_readable($candidate)) {
                   $target = $candidate;
                   break;
               }
           }
       }

       if (!is_file($target) || !is_readable($target)) {
           http_response_code(404);
           echo 'Local file not found';
           exit;
       }

       $_SERVER['REQUEST_METHOD'] = 'GET';
       $_POST = [];
       $_GET = [];
       parse_str($query, $_GET);
       $_SERVER['QUERY_STRING'] = $query;
       $_SERVER['REQUEST_URI'] = $path . ($query !== '' ? '?' . $query : '');

       chdir(dirname($target));

       $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

       if (in_array($ext, ['php', 'phtml', 'php5', 'php4', 'php3'], true)) {
           require $target;
           exit;
       }

       if (in_array($ext, ['html', 'htm'], true)) {
           $html = file_get_contents($target);
           if ($html === false) {
               http_response_code(500);
               echo 'Failed to read file';
               exit;
           }

           $webDir = rtrim(str_replace('\\', '/', dirname($path)), '/');
           $baseHref = $webDir === '' || $webDir === '.' ? '/' : $webDir . '/';

           if (!preg_match('~<base\s[^>]*href=~i', $html)) {
               if (preg_match('~<head[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
                   $pos = $m[0][1] + strlen($m[0][0]);
                   $html = substr($html, 0, $pos) . "\n<base href=\"" . htmlspecialchars($baseHref, ENT_QUOTES) . "\">\n" . substr($html, $pos);
               }
           }

           header('Content-Type: text/html; charset=utf-8');
           echo $html;
           exit;
       }

       http_response_code(403);
       echo 'Unsupported file type';
       exit;
   }

    private function runDiagnostic()
    {
        $this->inDiag = true;
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw, true);

        $result = [
            'success'      => false,
            'systemErrors' => [],
            'whitePages'   => [],
            'offerPages'   => [],
            'message'      => null,
        ];

        if (!is_array($in)) {
            $result['systemErrors'][] = ['code' => 'BAD_REQUEST', 'message' => 'Invalid JSON body'];
            $this->jsonOut($result, 400);
        }

        if ((isset($in['api_key']) ? $in['api_key'] : '') !== $this->config['api_key']) {
            $result['systemErrors'][] = ['code' => 'API_KEY_MISMATCH', 'message' => 'Invalid api_key'];
            $this->jsonOut($result, 403);
        }

        $white = is_array(isset($in['white']) ? $in['white'] : null) ? $in['white'] : [];
        $black = is_array(isset($in['black']) ? $in['black'] : null) ? $in['black'] : [];

        $sysErrors = [];
        $this->checkPHPVersion($sysErrors);
        $this->checkCoreFunctions($sysErrors);
        $this->checkConnection($sysErrors);
        $this->checkCache($sysErrors);
        $this->testCookie($sysErrors);

        $whiteDiag = [];
        foreach ($white as $w) {
            $whiteDiag[] = $this->toPageDiagnostic($this->diagnoseTarget($w));
        }

        $blackDiag = [];
        foreach ($black as $b) {
            $blackDiag[] = $this->toPageDiagnostic($this->diagnoseTarget($b));
        }

        $result['systemErrors'] = $sysErrors;
        $result['whitePages']   = $whiteDiag;
        $result['offerPages']   = $blackDiag;

        $hasPageErrors = false;
        foreach ($whiteDiag as $p) { if (isset($p['status']) && $p['status'] === 'error') { $hasPageErrors = true; break; } }
        if (!$hasPageErrors) {
            foreach ($blackDiag as $p) { if (isset($p['status']) && $p['status'] === 'error') { $hasPageErrors = true; break; } }
        }

        $hasSys = !empty($sysErrors);

        $result['success'] = (!$hasSys && !$hasPageErrors);
        $result['message'] = $result['success']
            ? 'Integration test completed successfully'
            : 'Some checks failed';

        $this->inDiag = false;
        $this->jsonOut($result, 200);
    }

    private function toPageDiagnostic(array $r)
    {
        $kind = $r['kind'] ?? '';
        if ($kind === 'url') $k = 'url';
        else if ($kind === 'http_status') $k = 'http_status';
        else $k = 'file';

        return [
            'input'    => $r['input'] ?? '',
            'kind'     => $k,
            'resolved' => $r['normalized'] ?? null,
            'status'   => (!empty($r['ok'])) ? 'ok' : 'error',
            'code'     => (!empty($r['ok'])) ? null : $this->mapDiagCode($r),
            'message'  => (!empty($r['ok'])) ? null : ($r['why'] ?? 'Unknown error'),
        ];
    }

    private function mapDiagCode(array $r)
    {
        $kind = isset($r['kind']) ? $r['kind'] : '';

        if ($kind === 'http_status') {
            $why = isset($r['why']) ? $r['why'] : '';

            if ($why === 'Empty status code')        return 'HTTP_STATUS_EMPTY';
            if ($why === 'Invalid HTTP status format') return 'HTTP_STATUS_BAD_FORMAT';
            if ($why === 'HTTP status out of range') return 'HTTP_STATUS_RANGE';

            return 'HTTP_STATUS_BAD';
        }

        if ($kind === 'url') {
            if (isset($r['why']) && substr($r['why'], 0, 14) === 'Upstream HTTP ') return 'URL_HTTP_ERROR';
            if (isset($r['why']) && substr($r['why'], 0, 13) === 'Network error') return 'URL_NETWORK';
            return 'URL_BAD';
        }

        $why = isset($r['why']) ? $r['why'] : '';
        if ($why === 'Local file not found') return 'FILE_NOT_FOUND';
        if ($why === 'Index file not found') return 'INDEX_NOT_FOUND';
        if ($why === 'Permission denied')    return 'FILE_FORBIDDEN';
        if ($why === 'Path outside base')    return 'PATH_OUTSIDE_BASE';
        if ($why === 'Not a file')           return 'NOT_A_FILE';
        if ($why === 'Conflict index name')  return 'INDEX_NAME_CONFLICT';
        return 'FILE_BAD';
    }

    private function jsonOut(array $payload, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function checkPHPVersion(array &$errors)
    {
        $phpMinVersion     = '5.4';
        $phpCurrentVersion = PHP_VERSION;

        if (version_compare(PHP_VERSION, $phpMinVersion, '<')) {
            $errors[] = ['code' => 'UNSUPPORTED_PHP_VERSION', 'message' => $phpCurrentVersion];
            return true;
        }
        return false;
    }

    private function checkCoreFunctions(array &$errors)
    {
        if (!function_exists('curl_init'))
            $errors[] = ['code' => 'CURL_NOT_FOUND'];

        if (!extension_loaded('openssl'))
            $errors[] = ['code' => 'OPENSSL_NOT_FOUND'];

        if (!function_exists('json_encode') && !function_exists('json_decode'))
            $errors[] = ['code' => 'JSON_NOT_FOUND'];

        if (!function_exists('setcookie'))
            $errors[] = ['code' => 'COOKIE_NOT_FOUND'];

        if (!function_exists('file_get_contents') || !function_exists('file_put_contents') || !function_exists('file'))
            $errors[] = ['code' => 'FILE_NOT_FOUND'];

        if (!function_exists('http_build_query')) {
            $errors[] = ['code' => 'HTTP_BQ_NOT_FOUND'];
        }

        if (!empty($errors)) {
            return true;
        }
        return false;
    }

   private function buildBaseUrl()
   {
       $scheme = (
           (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
           || (!empty($_SERVER['HTTP_CF_VISITOR']) && stristr($_SERVER['HTTP_CF_VISITOR'], 'https'))
           || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
           || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       ) ? 'https' : 'http';
       $host = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
       $port = !empty($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : null;
       if ($port && !in_array($port, array(80, 443), true)) {
           $host .= ':' . $port;
       }
       $path = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
       $url  = $scheme . '://' . $host . $path;
       return $url;
   }

   private function isRootIndexFile($abs, $base)
   {
       $dir = dirname($abs);
       $file = strtolower(basename($abs));

       if ($dir !== $base) {
           return false;
       }

       return in_array($file, ['index.php', 'index.htm', 'index.html'], true);
   }

   private function diagnoseTarget($value)
   {
       if (is_array($value)) {
           $dt = strtoupper(trim((string)($value['action'] ?? '')));
           $v  = trim((string)($value['value'] ?? ''));

           if ($dt === 'HTTP_STATUS') {
               return $this->diagnoseHttpStatus($v);
           }

           $value = $v;
       }

       $value = trim((string)$value);

       if (preg_match('#^https?://#i', $value)) {
           $u = @parse_url($value);
           if (!$u || empty($u['scheme']) || empty($u['host'])) {
               return [
                   'ok' => false,
                   'kind' => 'url',
                   'input' => $value,
                   'normalized' => $value,
                   'why' => 'Bad URL'
               ];
           }

           $resp = $this->httpFetch($value, [
               'method'    => 'HEAD',
               'timeout'   => 8,
               'follow'    => 4,
               'verifySSL' => false,
               'headers'   => [
                   'User-Agent' => 'MasqueradeDiag/1.0',
                   'Accept' => '*/*'
               ],
           ]);

           if (isset($resp['status']) && $resp['status'] === 405) {
               $resp = $this->httpFetch($value, [
                   'method'    => 'GET',
                   'timeout'   => 8,
                   'follow'    => 4,
                   'verifySSL' => false,
                   'headers'   => [
                       'User-Agent' => 'MasqueradeDiag/1.0',
                       'Accept' => '*/*'
                   ],
               ]);
           }

           if ((isset($resp['errorCode']) ? $resp['errorCode'] : 0) !== 0) {
               return [
                   'ok' => false,
                   'kind' => 'url',
                   'input' => $value,
                   'normalized' => $value,
                   'why' => 'Network error: ' . (isset($resp['error']) ? $resp['error'] : '')
               ];
           }

           if (!isset($resp['status']) || $resp['status'] < 200 || $resp['status'] >= 400) {
               return [
                   'ok' => false,
                   'kind' => 'url',
                   'input' => $value,
                   'normalized' => $value,
                   'why' => 'Upstream HTTP ' . (isset($resp['status']) ? $resp['status'] : 'unknown')
               ];
           }

           return [
               'ok' => true,
               'kind' => 'url',
               'input' => $value,
               'normalized' => $value,
               'why' => ''
           ];
       }

       if ($value === '') {
           return [
               'ok' => false,
               'kind' => 'local_file',
               'input' => $value,
               'normalized' => '',
               'why' => 'Empty path'
           ];
       }

       $docRoot = rtrim(realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);
       if (!$docRoot) {
           return [
               'ok' => false,
               'kind' => 'local_file',
               'input' => $value,
               'normalized' => '',
               'why' => 'Document root not found'
           ];
       }

       $u = @parse_url($value);
       $path = $u['path'] ?? '';

       if ($path === '') {
           return [
               'ok' => false,
               'kind' => 'local_file',
               'input' => $value,
               'normalized' => '',
               'why' => 'Invalid local file path'
           ];
       }

       $path = str_replace('\\', '/', $path);

       if (strpos($path, './') === 0) {
           $path = substr($path, 2);
       }

       if (strpos($path, '/') !== 0) {
           $path = '/' . $path;
       }

       $abs = @realpath($docRoot . $path);
       if ($abs === false) {
           return [
               'ok' => false,
               'kind' => 'local_file',
               'input' => $value,
               'normalized' => '',
               'why' => 'Local file not found'
           ];
       }

       if (strpos($abs, $docRoot . DIRECTORY_SEPARATOR) !== 0 && $abs !== $docRoot) {
           return [
               'ok' => false,
               'kind' => 'local_file',
               'input' => $value,
               'normalized' => $abs,
               'why' => 'Path outside document root'
           ];
       }

       if (is_dir($abs)) {
           foreach (['index.php', 'index.html', 'index.htm'] as $idx) {
               $try = @realpath($abs . DIRECTORY_SEPARATOR . $idx);
               if ($try && is_file($try) && is_readable($try)) {
                   if ($this->isRootIndexFile($try, $docRoot)) {
                       return [
                           'ok' => false,
                           'kind' => 'local_file',
                           'input' => $value,
                           'normalized' => $try,
                           'why' => 'Conflict index name'
                       ];
                   }

                   return [
                       'ok' => true,
                       'kind' => 'local_file',
                       'input' => $value,
                       'normalized' => $try,
                       'why' => ''
                   ];
               }
           }

           return [
               'ok' => false,
               'kind' => 'local_dir',
               'input' => $value,
               'normalized' => $abs,
               'why' => 'Index file not found'
           ];
       }

       if (!is_file($abs)) {
           return [
               'ok' => false,
               'kind' => 'local_file',
               'input' => $value,
               'normalized' => $abs,
               'why' => 'Not a file'
           ];
       }

       if (!is_readable($abs)) {
           return [
               'ok' => false,
               'kind' => 'local_file',
               'input' => $value,
               'normalized' => $abs,
               'why' => 'Permission denied'
           ];
       }

       if ($this->isRootIndexFile($abs, $docRoot)) {
           return [
               'ok' => false,
               'kind' => 'local_file',
               'input' => $value,
               'normalized' => $abs,
               'why' => 'Conflict index name'
           ];
       }

       return [
           'ok' => true,
           'kind' => 'local_file',
           'input' => $value,
           'normalized' => $abs,
           'why' => ''
       ];
   }

    private function diagnoseHttpStatus(string $raw)
    {
        $raw = trim($raw);

        if ($raw === '') {
            return ['ok' => false, 'kind' => 'http_status', 'input' => $raw, 'normalized' => null, 'why' => 'Empty http status'];
        }
        if (!preg_match('/^\d{3}$/', $raw)) {
            return ['ok' => false, 'kind' => 'http_status', 'input' => $raw, 'normalized' => null, 'why' => 'Invalid http status format'];
        }

        $code = (int)$raw;
        if ($code < 100 || $code > 599) {
            return ['ok' => false, 'kind' => 'http_status', 'input' => $raw, 'normalized' => $code, 'why' => 'Http status out of range'];
        }

        return ['ok' => true, 'kind' => 'http_status', 'input' => $raw, 'normalized' => $code, 'why' => ''];
    }

    private function checkCache(array &$errors)
    {
        $baseURL = $this->buildBaseUrl();

        $doRequest = function ($headers) use ($baseURL) {
            $sep = (strpos($baseURL, '?') !== false) ? '&' : '?';
            $url = $baseURL . $sep . "debug=cache" . "&key=" . $this->config['api_key'] . "&r=" . mt_rand(1, 1000000000);

            $resp = $this->httpFetch($url, [
                'method'  => 'GET',
                'headers' => $headers,
                'timeout' => 15,
                'follow'  => 1,
            ]);
            return trim(isset($resp['body']) ? $resp['body'] : '');
        };

        $groupA = [];
        $groupB = [];

        $headersA = ['User-Agent' => 'MASQRAD_DIAGNOSTIC'];
        $headersB = ['X-MASQRAD-TEST-HEADER' => 'cache'];

        for ($i = 0; $i < 4; $i++) $groupA[] = $doRequest($headersA);
        for ($i = 0; $i < 4; $i++) $groupB[] = $doRequest($headersB);

        if (in_array('No custom headers', $groupB, true)) {
            $errors[] = ['code' => 'UNSUPPORTED_CUSTOM_HEADERS'];
            return;
        }

        $uniqueA = count(array_unique($groupA));
        $uniqueB = count(array_unique($groupB));

        if ($uniqueA < 4 || $uniqueB < 4) {
            $errors[] = ['code' => 'CACHING'];
        }
    }

    private function testCookie(array &$errors)
    {
        $baseURL = $this->buildBaseUrl();
        $sep = (strpos($baseURL, '?') !== false) ? '&' : '?';
        $url = $baseURL . $sep . "debug=cookie" . "&key=" . $this->config['api_key'];

        $resp1 = $this->httpFetch($url, [
            'method'  => 'GET',
            'headers' => ['X-MASQRAD-TEST-HEADER' => 'cookie'],
            'timeout' => 15,
            'follow'  => 1,
        ]);

        if (empty($resp1['headers'])) {
            $errors[] = ['code' => 'EMPTY_RESPONSE_HEADERS'];
            return;
        }

        $setCookies = isset($resp1['headers']['Set-Cookie']) ? $resp1['headers']['Set-Cookie'] : (isset($resp1['headers']['set-cookie']) ? $resp1['headers']['set-cookie'] : []);
        if (!is_array($setCookies) || count($setCookies) === 0) {
            $errors[] = ['code' => 'DOES_NOT_SET_COOKIE'];
            return;
        }

        $rawCookie = '';
        foreach ($setCookies as $line) {
            $pair = trim(strtok($line, ';'));
            if ($pair !== '' && stripos($pair, 'MasqradCookie=') === 0) {
                $rawCookie = $pair;
                break;
            }
        }
        if ($rawCookie === '') {
            $errors[] = ['code' => 'DOES_NOT_SET_COOKIE'];
            return;
        }

        $resp2 = $this->httpFetch($url, [
            'method'  => 'GET',
            'headers' => ['Cookie' => $rawCookie],
            'timeout' => 15,
            'follow'  => 1,
        ]);

        $body = trim(isset($resp2['body']) ? $resp2['body'] : '');
        if ($body !== "Cookie recognized: MasqradCookie=masquerade" && $body !== "Cookie recognized: MasqradCookie=masqrad") {
            $errors[] = ['code' => 'DOES_NOT_SET_COOKIE'];
        }
    }

    private function generateDynamicResponse($prefix = 'masqrad-')
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: User-Agent, X-MASQRAD-TEST-HEADER');

        $rand = $this->random_bytes_compat(4);
        $suffix = $this->bin2hex_compat($rand);

        return uniqid($prefix, true) . '-' . $suffix;
    }

    private function getHeaders()
    {
        $headers = $_SERVER;

        $headers['path'] = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        if (empty($headers['path'])) {
            if (empty($_SERVER['QUERY_STRING']) && empty($_GET)) {
                $headers['path'] = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
            } else {
                $q = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : http_build_query($_GET);
                $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index-v1.php';
                $headers['path'] = $scriptName . (empty($q) ? '' : '?' . $q);
            }
        }

        if (empty($_SERVER['HTTP_HOST'])) {
            if (!empty($_SERVER['HTTP_AUTHORITY'])) {
                $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_AUTHORITY'];
            } else if (!empty($_SERVER['SERVER_NAME'])) {
                $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'];
            }
        }

        $headers['REQUEST_METHOD'] = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

        if ( (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443)
            || !empty($_SERVER['HTTPS']) || !empty($_SERVER['SSL'])) {
            $headers['HTTP_HTTPS'] = '1';
        }

        return $headers;
    }

    private function checkConnection(array &$errors)
    {
        $resp = $this->apiRequest("/ping", array('X-MASQRAD-PING' => '1'), null, false, 'GET');

        if (!empty($resp['errorCode'])) {
            $code = isset($resp['errorKey']) ? $resp['errorKey'] : ("CURL_" . $resp['errorCode']);
            $msg  = isset($resp['error']) ? $resp['error'] : '';
            $errors[] = array('code' => $code, 'message' => $msg);
            return;
        }

        $status = (int)(isset($resp['status']) ? $resp['status'] : 0);
        if ($status < 200 || $status >= 300) {
            $errors[] = array('code' => "CONNECTION_HTTP_CODE_WRONG", 'message' => $status);
            return;
        }

        $body = trim(isset($resp['body']) ? $resp['body'] : '');
        if ($body === "") {
            $errors[] = array('code' => "CONNECTION_EMPTY_BODY");
            return;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = array('code' => "CONNECTION_INVALID_BODY");
            return;
        }

        if (!isset($decoded['ping']) || $decoded['ping'] !== true) {
            $errors[] = array('code' => "CONNECTION_CORRUPTED_BODY");
        }
    }

    private function buildClientData($data)
    {
        $apiKey     = $this->config['api_key'];
        $campaignId = $this->config['campaign_id'];
        $integration = $this->config['integration'];
        $version = $this->config['version'];
        $headers    = $this->getHeaders();
        $params     = $_GET;

        if (empty($headers)) {
            $headers = new stdClass();
        }
        if (empty($params)) {
            $params = new stdClass();
        }

        return [
            'apiKey' => $apiKey,
            'campaignId' => $campaignId,
            'integration' => $integration,
            'version' => $version,
            'headers' => $headers,
            'params' => $params,
            'fingerprint' => $data,
            'noscript' => false
        ];
    }


    private function guardUrl($url, array $allowHosts = [])
    {
        $u = parse_url($url);
        if (!$u || empty($u['scheme']) || !in_array(strtolower($u['scheme']), array('http','https'), true) || empty($u['host'])) {
            return $this->guardFail('BAD_URL', 400, 'Bad URL');
        }

        $host = strtolower($u['host']);

        foreach ($allowHosts as $allowed) {
            if ($host === strtolower($allowed) || $this->ends_with($host, '.' . strtolower($allowed))) {
                return true;
            }
        }

        if (in_array($host, array('localhost','127.0.0.1','::1'), true)) {
            return $this->guardFail('FORBIDDEN_HOST', 403, 'Forbidden host');
        }

        $ips = array();
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $a = gethostbynamel($host) ?: array();
            $aaaaRecords = function_exists('dns_get_record') ? dns_get_record($host, DNS_AAAA) : array();
            $ipv6s = array();
            if (is_array($aaaaRecords)) {
                foreach ($aaaaRecords as $rec) {
                    if (isset($rec['ipv6'])) $ipv6s[] = $rec['ipv6'];
                }
            }
            $ips = array_values(array_filter(array_merge($a, $ipv6s)));
        }

        if (empty($ips)) {
            return $this->guardFail('DNS_RESOLVE_FAILED', 502, 'DNS resolve failed');
        }

        foreach ($ips as $ip) {
            $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($isPublic === false) {
                return $this->guardFail('FORBIDDEN_IP', 403, 'Forbidden IP');
            }
        }

        return true;
    }

    private function guardFail($code, $http, $publicMsg) {
        if ($this->inDiag) {
            return array('_guard_error' => true, 'code' => $code, 'http' => $http, 'message' => $publicMsg);
        }
        http_response_code($http);
        echo $publicMsg;
        exit;
    }

    private function httpFetch($url, array $options = [])
    {
        $method    = strtoupper(isset($options['method']) ? $options['method'] : 'GET');
        $hdrAssoc  = isset($options['headers']) ? $options['headers'] : [];
        $payload   = array_key_exists('body', $options) ? $options['body'] : null;
        $timeout   = (int)(isset($options['timeout']) ? $options['timeout'] : 30);
        $follow    = (int)(isset($options['follow']) ? $options['follow'] : 5);
        $verifySSL = (bool)(isset($options['verifySSL']) ? $options['verifySSL'] : false);

        $result = ['status' => 0, 'headers' => [], 'body' => '', 'error' => '', 'errorCode' => 0];

        $hdrList = [];
        foreach ($hdrAssoc as $k => $v) {
            $hdrList[] = $k . ': ' . $v;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => $follow > 0,
                CURLOPT_MAXREDIRS      => $follow,
                CURLOPT_SSL_VERIFYPEER => $verifySSL,
                CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
                CURLOPT_ENCODING       => '', // gzip/deflate/br
                CURLOPT_USERAGENT      => isset($hdrAssoc['User-Agent']) ? $hdrAssoc['User-Agent'] : 'MasqueradeHTTP/1.0',
                CURLOPT_HEADER         => true,
            ]);

            if (!empty($hdrList)) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrList);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            } elseif ($method === 'HEAD') {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            }

            $resp = curl_exec($ch);
            if ($resp === false) {
                $result['error'] = curl_error($ch);
                $result['errorCode'] = curl_errno($ch);
                return $result;
            }

            $status     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($resp, 0, $headerSize) ?: '';
            $body       = substr($resp, $headerSize) ?: '';

            $hdrLines = preg_split('/\r\n|\r|\n/', $rawHeaders);
            $hdrMap = [];
            foreach ($hdrLines as $line) {
                if (strpos($line, ':') !== false) {
                    $parts = explode(':', $line, 2);
                    $name = trim($parts[0]);
                    $val  = trim($parts[1]);
                    if (!isset($hdrMap[$name])) $hdrMap[$name] = [];
                    $hdrMap[$name][] = $val;
                }
            }

            $result['status']  = $status;
            $result['headers'] = $hdrMap;
            $result['body']    = $body;
            return $result;
        }

        $headerLines = '';
        foreach ($hdrAssoc as $k => $v) {
            $headerLines .= "$k: $v\r\n";
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'timeout'       => $timeout,
                'header'        => $headerLines,
                'ignore_errors' => true,
            ],
            'ssl'  => [
                'verify_peer'      => $verifySSL,
                'verify_peer_name' => $verifySSL,
            ],
        ]);
        if ($method === 'POST' && $payload !== null) {
            $opts = stream_context_get_options($ctx);
            $opts['http']['content'] = $payload;
            $ctx = stream_context_create($opts);
        }

        $resp = @file_get_contents($url, false, $ctx);
        $result['body'] = $resp === false ? '' : $resp;

       $lastResponseHeaders = function_exists('http_get_last_response_headers')
           ? http_get_last_response_headers()
           : [];

       if (is_array($lastResponseHeaders)) {
           foreach ($lastResponseHeaders as $line) {
               if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $line, $m)) {
                   $result['status'] = (int)$m[1];
               } elseif (strpos($line, ':') !== false) {
                   $parts = explode(':', $line, 2);
                   $name = trim($parts[0]);
                   $val  = trim($parts[1]);
                   if (!isset($result['headers'][$name])) {
                       $result['headers'][$name] = [];
                   }
                   $result['headers'][$name][] = $val;
               }
           }
       }
        if ($resp === false) {
            $err = error_get_last();
            if ($err) {
                $result['error'] = $err['message'];
                $result['errorCode'] = 1;
            }
        }
        return $result;
    }

    private function apiRequest($path, $headers, $data, $returnHeaders, $method = 'GET')
    {
        $base = rtrim($this->config['api_host'], '/');
        $url  = $base . $path;

        $apiHost = parse_url($base, PHP_URL_HOST);
        $allow = $apiHost ? array($apiHost) : array();



        $g = $this->guardUrl($url, $allow);
        if (is_array($g) && !empty($g['_guard_error'])) {
            return array(
                'status'    => $g['http'],
                'headers'   => array(),
                'body'      => '',
                'error'     => $g['message'],
                'errorCode' => -1001,
                'errorKey'  => $g['code'],
            );
        }

        $resp = $this->httpFetch($url, array(
            'method'    => $method,
            'headers'   => $headers,
            'body'      => $data,
            'timeout'   => 45,
            'follow'    => 5,
            'verifySSL' => false,
        ));

        if (!$returnHeaders) {
            $resp['headers'] = array();
        }
        return $resp;
    }

     private function abortOnApiFailure($resp, $where)
    {
        $status    = isset($resp['status']) ? (int)$resp['status'] : 0;
        $hasNetErr = !empty($resp['errorCode']);
        $badHttp   = ($status < 200 || $status >= 300);

        if ($hasNetErr || $badHttp) {

            $errorKey = isset($resp['errorKey']) ? $resp['errorKey'] : null;

            if ($errorKey) {
                $reason = $errorKey;
            } elseif ($hasNetErr) {
                $reason = 'CURL_' . $resp['errorCode'];
            } else {
                $reason = 'UPSTREAM_HTTP_' . $status;
            }

            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            if (!empty($_GET['dev']) && $_GET['dev'] === '1') {
                echo "502 Bad Gateway ({$where} | {$reason})";
            } else {
                echo "502 Bad Gateway";
            }

            exit;
        }
    }

    private function fetchAndEcho($url)
    {
        $this->guardUrl($url, []);

        $resp = $this->httpFetch($url, [
            'method'  => 'GET',
            'headers' => ['User-Agent' => 'MasqueradeFetcher/1.0', 'Accept' => '*/*'],
            'timeout' => 30,
            'follow'  => 5,
            'verifySSL' => false,
        ]);

        if (!empty($resp['errorCode'])) {
            http_response_code(502);
            header('Cache-Control: no-store');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bad Gateway';
            exit;
        }

        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            http_response_code(502);
            header('Cache-Control: no-store');
            echo 'Upstream HTTP ' . $resp['status'];
            exit;
        }

        $ctype = 'text/html; charset=utf-8';
        foreach (['Content-Type', 'content-type'] as $h) {
            if (isset($resp['headers'][$h]) && count($resp['headers'][$h])) {
                $ctype = trim(end($resp['headers'][$h]));
                break;
            }
        }

        header('Cache-Control: no-store');
        header('Content-Type: ' . $ctype);
        echo $resp['body'];
        exit;
    }

    private function ends_with($haystack, $needle)
    {
        $len = strlen($needle);
        if ($len === 0) return true;
        return substr($haystack, -$len) === $needle;
    }

    private function random_bytes_compat($length)
    {
        if (function_exists('random_bytes')) {
            return random_bytes($length);
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($bytes !== false && $strong === true) {
                return $bytes;
            }
        }
        $buf = '';
        for ($i = 0; $i < $length; $i++) {
            $buf .= chr(mt_rand(0, 255));
        }
        return $buf;
    }

    private function bin2hex_compat($str)
    {
        if (function_exists('bin2hex')) return bin2hex($str);
        $hex = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $hex .= sprintf("%02x", ord($str[$i]));
        }
        return $hex;
    }
}

$masqrad = new MASQRAD($config);
$masqrad->run();