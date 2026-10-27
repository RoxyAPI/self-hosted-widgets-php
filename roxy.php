<?php
/**
 * RoxyAPI widgets served from your own domain.
 * Upload this file to your site root, paste your publishable key below, then open /roxy.php?check= plus the last six characters of the key.
 * https://github.com/RoxyAPI/self-hosted-widgets-php
 */

$ROXY_KEY = ''; // Your publishable key from https://roxyapi.com/account?tab=keys, starting with pk_, with your site in its allowed origins

// Nothing to edit below this line.

const ROXY_API = 'https://roxyapi.com/api/v2';
const ROXY_PACKAGE = 'https://registry.npmjs.org/@roxyapi/ui/latest';
const ROXY_RELEASE = 'https://github.com/RoxyAPI/ui/releases/download/v%1$s/roxyapi-ui-%1$s.tgz';
const ROXY_REFRESH = 21600; // Seconds between checks for a new widget release.
const ROXY_MAX_BODY = 65536;
const PHP_RELEASES = 'https://www.php.net/releases/index.php?json&version=%s';

$dir = __DIR__ . '/roxy';
// A warning text would print server paths into a reading.
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');

if (isset($_GET['check'])) {
	check_page($ROXY_KEY, $dir);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
	guard_same_site();
	$raw = (string) file_get_contents('php://input', false, null, 0, ROXY_MAX_BODY + 1);
	$req = json_decode($raw, true);
	if (strlen($raw) > ROXY_MAX_BODY || !is_array($req) || !is_string($req['path'] ?? null)) refuse(400, 'Bad request');
	$method = strtoupper($req['method'] ?? 'GET');
	if ($method !== 'GET' && $method !== 'POST') refuse(405, 'Method not allowed');
	$query = is_array($req['query'] ?? null) ? array_filter($req['query'], 'is_scalar') : [];
	$body = $method === 'POST' ? json_encode($req['body'] ?? new stdClass()) : null;
	relay($ROXY_KEY, $method, $req['path'], $query, $body, $dir);
} elseif (isset($_GET['q'])) {
	guard_same_site();
	relay($ROXY_KEY, 'GET', '/location/search', array_filter($_GET, 'is_scalar'), null, $dir);
} else {
	refuse(404, 'Not found');
}

function refuse($status, $message)
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode(['error' => $message]);
	exit;
}

/** Only pages on the site that hosts this file may use it, so no other site runs readings on your key. */
function guard_same_site()
{
	$site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
	if ($site === 'same-origin') return;
	$from = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
	$u = parse_url($from) ?: [];
	$host = strtolower(($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : ''));
	if ($site === '' && $host !== '' && $host === strtolower($_SERVER['HTTP_HOST'] ?? '')) return;
	refuse(403, 'This address answers widgets on its own site only');
}

function relay($key, $method, $path, array $query, $body, $dir)
{
	// A fixed host plus a plain path: no scheme, host, query, traversal or header can be smuggled in.
	if (!preg_match('#^(/[A-Za-z0-9._~%-]+)+$#', $path) || strpos(rawurldecode($path), '..') !== false) refuse(400, 'Bad path');
	if ($key === '') refuse(503, 'The site owner has not added a key yet');
	$url = ROXY_API . $path . ($query ? '?' . http_build_query($query) : '');
	$headers = key_headers($key);
	if ($body !== null) $headers[] = 'Content-Type: application/json';
	$res = http($method, $url, $headers, $body);
	if ($res === null) refuse(502, 'The reading service could not be reached, try again in a minute');
	http_response_code($res[0]);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo $res[1];
	if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
	sync_widgets($dir, false);
}

/** A publishable key answers for the site in its allowed origins, so the request names this site. */
function key_headers($key)
{
	return ['X-API-Key: ' . $key, 'Origin: https://' . ($_SERVER['HTTP_HOST'] ?? ''), 'Accept: application/json'];
}

/** One HTTP request, through curl when present, else PHP streams. Returns [status, body] or null when unreachable. */
function http($method, $url, array $headers = [], $body = null, $timeout = 30)
{
	// Redirects are followed only for requests that carry no key, so the key never reaches another host.
	$follow = !$headers;
	$headers[] = 'User-Agent: roxy-php';
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => $follow,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_ENCODING => '',
		]);
		if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		$out = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return $out === false ? null : [$status, $out];
	}
	$ctx = stream_context_create(['http' => [
		'method' => $method,
		'header' => implode("\r\n", $headers),
		'content' => (string) $body,
		'timeout' => $timeout,
		'ignore_errors' => true,
		'follow_location' => $follow ? 1 : 0,
	]]);
	$out = @file_get_contents($url, false, $ctx);
	if ($out === false) return null;
	// After redirects the last status line wins.
	$lines = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
	preg_match_all('#^HTTP/\S+ (\d{3})#m', implode("\n", $lines), $m);
	return [(int) end($m[1]), $out];
}

/**
 * Keeps ./roxy holding a copy of the widget files from the latest release, as they ship: cdn/ and schemas/ side by side,
 * so the forms find their schemas beside the script. Checks for a new release every few hours.
 */
function sync_widgets($dir, $force)
{
	$stamp = "$dir/version.txt";
	// Files uploaded by hand count as a release, so they are served and replaced by the next one.
	if (!is_file($stamp) && is_file("$dir/cdn/roxy-ui.js")) @file_put_contents($stamp, 'manual');
	if (!$force && is_file($stamp) && time() - filemtime($stamp) < ROXY_REFRESH) return true;
	$lock = fopen(__FILE__, 'r');
	if (!flock($lock, LOCK_EX | LOCK_NB)) return is_file($stamp);
	@set_time_limit(300);
	// A failed check waits the same interval as a successful one, so a blocked download is not retried on every reading.
	$keep = function () use ($stamp) { return is_file($stamp) && touch($stamp); };
	$res = http('GET', ROXY_PACKAGE, [], null, 15);
	$meta = $res && $res[0] === 200 ? json_decode($res[1], true) : null;
	if (!isset($meta['version'], $meta['dist']['tarball'], $meta['dist']['shasum'])) return $keep();
	if (is_file($stamp) && trim(file_get_contents($stamp)) === $meta['version']) return touch($stamp);
	foreach ([$meta['dist']['tarball'], sprintf(ROXY_RELEASE, $meta['version'])] as $url) {
		$tgz = http('GET', $url, [], null, 240);
		if ($tgz && $tgz[0] === 200 && sha1($tgz[1]) === $meta['dist']['shasum']) break;
		$tgz = null;
	}
	if (!$tgz) return $keep();
	$tmp = "$dir.tgz";
	file_put_contents($tmp, $tgz[1]);
	unset($tgz);
	$ok = untar($tmp, "$dir.new");
	unlink($tmp);
	if (!$ok) return $keep();
	file_put_contents("$dir.new/version.txt", $meta['version']);
	remove_tree("$dir.old");
	if (is_dir($dir)) rename($dir, "$dir.old");
	rename("$dir.new", $dir);
	remove_tree("$dir.old");
	return true;
}

/** Extracts the .js and .json files of package/dist/cdn and package/dist/schemas from an npm tarball, so nothing the server would run lands in the folder. */
function untar($tgz, $to)
{
	remove_tree($to);
	$gz = gzopen($tgz, 'rb');
	$long = null;
	while (($head = gzread($gz, 512)) !== false && strlen($head) === 512 && trim($head, "\0") !== '') {
		$size = octdec(trim(substr($head, 124, 12), " \0"));
		$type = $head[156];
		$name = $long ?: rtrim(substr($head, 345, 155), "\0") . '/' . rtrim(substr($head, 0, 100), "\0");
		$data = $size ? gzread($gz, $size) : '';
		if ($size % 512) gzread($gz, 512 - $size % 512);
		$long = null;
		if ($type === 'x' && preg_match('/\d+ path=([^\n]+)\n/', $data, $m)) $long = $m[1];
		$name = ltrim($name, '/');
		if (($type !== '0' && $type !== "\0") || strpos($name, '..') !== false) continue;
		if (!preg_match('#^package/dist/((cdn|schemas)/[A-Za-z0-9._/-]+\.(js|json))$#', $name, $m)) continue;
		$file = "$to/$m[1]";
		if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true)) return false;
		if (file_put_contents($file, $data) !== strlen($data)) return false;
	}
	gzclose($gz);
	return is_file("$to/cdn/roxy-ui.js");
}

function remove_tree($path)
{
	if (!is_dir($path)) return;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
		$f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
	}
	rmdir($path);
}

/**
 * The PHP line of the check page: green on the latest release of its branch, yellow when php.net lists a newer one or no
 * longer supports the branch, so an owner hears about a security release without the widgets stopping. Green when php.net is unreachable.
 */
function php_update_line($web, $t)
{
	$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	$current = "$branch." . PHP_RELEASE_VERSION;
	$res = $web ? http('GET', sprintf(PHP_RELEASES, $branch), [], null, 10) : null;
	$feed = $res && $res[0] === 200 ? json_decode($res[1], true) : null;
	$label = 'PHP ' . PHP_VERSION;
	if (!isset($feed['version'], $feed['supported_versions']) || !is_array($feed['supported_versions'])) return [true, $label, ''];
	if (!in_array($branch, $feed['supported_versions'], true)) {
		return [null, $label, $t("PHP $branch no longer receives security fixes. Switch to the newest PHP version in your hosting panel, or ask your hosting provider to.", "PHP $branch больше не получает исправлений безопасности. Выберите самую новую версию PHP в панели хостинга или попросите об этом хостинг.")];
	}
	if (version_compare($current, $feed['version'], '<')) {
		$out = in_array('security', $feed['tags'] ?? [], true)
			? $t("PHP {$feed['version']} is out with security fixes.", "Вышел PHP {$feed['version']} с исправлениями безопасности.")
			: $t("PHP {$feed['version']} is out.", "Вышел PHP {$feed['version']}.");
		return [null, $label, $out . ' ' . $t('Ask your hosting provider to update PHP, or to confirm this build already carries its fixes.', 'Попросите хостинг обновить PHP или подтвердить, что эта сборка уже содержит его исправления.')];
	}
	return [true, $label, ''];
}

function check_page($key, $dir)
{
	$ru = stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'ru') === 0;
	$t = function ($en, $ruText) use ($ru) { return $ru ? $ruText : $en; };
	header('Content-Type: text/html; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Robots-Tag: noindex');
	// Once a key is set the report names the PHP version, so only someone holding the key reads it.
	if ($key !== '' && !hash_equals(substr($key, -6), (string) $_GET['check'])) {
		echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>RoxyAPI check</title>',
			'<p style="font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 16px">',
			htmlspecialchars($t('Add the last six characters of your key to the address: roxy.php?check=XXXXXX', 'Добавьте в адрес последние шесть символов ключа: roxy.php?check=XXXXXX')), '</p>';
		return;
	}
	$lines = [];
	$web = function_exists('curl_init') || ini_get('allow_url_fopen');
	$lines[] = version_compare(PHP_VERSION, '7.4', '>=')
		? php_update_line($web, $t)
		: [false, 'PHP ' . PHP_VERSION, $t('PHP 7.4 or newer is needed. Switch the PHP version in your hosting panel.', 'Нужен PHP 7.4 или новее. Переключите версию PHP в панели хостинга.')];
	$lines[] = [$web, $t('Outgoing connections', 'Исходящие соединения'), $t('Ask your hosting to enable the PHP curl extension.', 'Попросите хостинг включить расширение PHP curl.')];
	$res = $web ? http('GET', ROXY_API . '/location/openapi.json', [], null, 20) : null;
	$reach = $res && $res[0] === 200 && isset(json_decode($res[1], true)['paths']);
	$lines[] = [$reach, $t('This server reaches roxyapi.com', 'Сервер связывается с roxyapi.com'), $t('Your server cannot download from roxyapi.com. Ask your hosting to allow outgoing HTTPS.', 'Сервер не может загрузить данные с roxyapi.com. Попросите хостинг разрешить исходящие HTTPS-соединения.')];
	if ($key === '') {
		$lines[] = [false, $t('Publishable key', 'Публикуемый ключ'), $t('Open roxy.php in a text editor and paste your publishable key between the quotes on the line $ROXY_KEY.', 'Откройте roxy.php в текстовом редакторе и вставьте публикуемый ключ между кавычками в строке $ROXY_KEY.')];
	} else {
		$res = $reach ? http('GET', ROXY_API . '/location/search?q=London&limit=1', key_headers($key), null, 20) : null;
		$lines[] = [$res && $res[0] === 200, $t('Publishable key works for this site', 'Публикуемый ключ работает для этого сайта'), $t('The key was not accepted. Copy the whole key starting with pk_ from your RoxyAPI account, and add this site to its allowed origins.', 'Ключ не принят. Скопируйте ключ целиком, начиная с pk_, в аккаунте RoxyAPI и добавьте этот сайт в список Allowed origins этого ключа.')];
	}
	$writable = is_dir($dir) ? is_writable($dir) : is_writable(__DIR__);
	$lines[] = [$writable, $t('Folder for widget files is writable', 'Папка для файлов виджетов доступна для записи'), $t('Allow PHP to write to the folder that holds roxy.php (permissions 755 in your file manager).', 'Разрешите PHP запись в папку, где лежит roxy.php (права 755 в файловом менеджере).')];
	$synced = ($writable && $reach && sync_widgets($dir, !is_file("$dir/version.txt"))) || is_file("$dir/cdn/roxy-ui.js");
	$version = is_file("$dir/version.txt") ? trim(file_get_contents("$dir/version.txt")) : '';
	$lines[] = [$synced, $t('Widget files ready', 'Файлы виджетов готовы') . ($version ? " ($version)" : ''), $t('Fix the lines above, then reload this page.', 'Исправьте строки выше и обновите эту страницу.')];

	$all = !in_array(false, array_column($lines, 0), true);
	echo '<!doctype html><html lang="', $ru ? 'ru' : 'en', '"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">',
		'<title>RoxyAPI check</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;color:#18181b}',
		'li{list-style:none;margin:12px 0}b{display:inline-block;width:1.5em}.ok b{color:#15803d}.no b{color:#b91c1c}.warn b{color:#b45309}small{display:block;margin-left:1.5em;color:#52525b}</style>',
		'<h1>RoxyAPI</h1><ul>';
	foreach ($lines as $l) {
		$state = $l[0] === null ? ['warn', '!'] : ($l[0] ? ['ok', '&#10003;'] : ['no', '&#10007;']);
		echo '<li class="', $state[0], '"><b>', $state[1], '</b>', htmlspecialchars($l[1]), $l[0] ? '' : '<small>' . htmlspecialchars($l[2]) . '</small>', '</li>';
	}
	$warned = in_array(null, array_column($lines, 0), true);
	echo '</ul><p>', !$all ? $t('Fix the red lines, then reload this page.', 'Исправьте красные строки и обновите эту страницу.')
		: ($warned ? $t('Your widgets are ready. Pass the yellow line to your hosting provider.', 'Виджеты готовы к работе. Передайте жёлтую строку своему хостингу.') : $t('All green. Your widgets are ready.', 'Всё зелёное. Виджеты готовы к работе.')), '</p>';
}
