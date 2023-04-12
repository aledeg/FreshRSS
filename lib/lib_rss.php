<?php
if (version_compare(PHP_VERSION, FRESHRSS_MIN_PHP_VERSION, '<')) {
	die(sprintf('FreshRSS error: FreshRSS requires PHP %s+!', FRESHRSS_MIN_PHP_VERSION));
}

if (!function_exists('mb_strcut')) {
	function mb_strcut(string $str, int $start, ?int $length = null, string $encoding = 'UTF-8'): string {
		return substr($str, $start, $length) ?: '';
	}
}

if (!function_exists('str_starts_with')) {
	/** Polyfill for PHP <8.0 */
	function str_starts_with(string $haystack, string $needle): bool {
		return strncmp($haystack, $needle, strlen($needle)) === 0;
	}
}

if (!function_exists('syslog')) {
	// @phpstan-ignore-next-line
	if (COPY_SYSLOG_TO_STDERR && !defined('STDERR')) {
		define('STDERR', fopen('php://stderr', 'w'));
	}
	function syslog(int $priority, string $message): bool {
		// @phpstan-ignore-next-line
		if (COPY_SYSLOG_TO_STDERR && defined('STDERR') && STDERR) {
			return fwrite(STDERR, $message . "\n") != false;
		}
		return false;
	}
}

if (function_exists('openlog')) {
	// @phpstan-ignore-next-line
	if (COPY_SYSLOG_TO_STDERR) {
		openlog('FreshRSS', LOG_CONS | LOG_ODELAY | LOG_PID | LOG_PERROR, LOG_USER);
	} else {
		openlog('FreshRSS', LOG_CONS | LOG_ODELAY | LOG_PID, LOG_USER);
	}
}

/**
 * Build a directory path by concatenating a list of directory names.
 *
 * @param string ...$path_parts a list of directory names
 * @return string corresponding to the final pathname
 */
function join_path(...$path_parts): string {
	return join(DIRECTORY_SEPARATOR, $path_parts);
}

//<Auto-loading>
function classAutoloader(string $class): void {
	if (strpos($class, 'FreshRSS') === 0) {
		$components = explode('_', $class);
		switch (count($components)) {
			case 1:
				include(APP_PATH . '/' . $components[0] . '.php');
				return;
			case 2:
				include(APP_PATH . '/Models/' . $components[1] . '.php');
				return;
			case 3:	//Controllers, Exceptions
				include(APP_PATH . '/' . $components[2] . 's/' . $components[1] . $components[2] . '.php');
				return;
		}
	} elseif (strpos($class, 'Minz') === 0) {
		include(LIB_PATH . '/' . str_replace('_', '/', $class) . '.php');
	} elseif (strpos($class, 'SimplePie') === 0) {
		include(LIB_PATH . '/SimplePie/' . str_replace('_', '/', $class) . '.php');
	} elseif (str_starts_with($class, 'Gt\\CssXPath\\')) {
		$prefix = 'Gt\\CssXPath\\';
		$base_dir = LIB_PATH . '/phpgt/cssxpath/src/';
		$relative_class_name = substr($class, strlen($prefix));
		require $base_dir . str_replace('\\', '/', $relative_class_name) . '.php';
	} elseif (str_starts_with($class, 'marienfressinaud\\LibOpml\\')) {
		$prefix = 'marienfressinaud\\LibOpml\\';
		$base_dir = LIB_PATH . '/marienfressinaud/lib_opml/src/LibOpml/';
		$relative_class_name = substr($class, strlen($prefix));
		require $base_dir . str_replace('\\', '/', $relative_class_name) . '.php';
	} elseif (str_starts_with($class, 'PHPMailer\\PHPMailer\\')) {
		$prefix = 'PHPMailer\\PHPMailer\\';
		$base_dir = LIB_PATH . '/phpmailer/phpmailer/src/';
		$relative_class_name = substr($class, strlen($prefix));
		require $base_dir . str_replace('\\', '/', $relative_class_name) . '.php';
	}
}

spl_autoload_register('classAutoloader');
//</Auto-loading>

function idn_to_puny(string $url): string {
	if (function_exists('idn_to_ascii')) {
		$idn = parse_url($url, PHP_URL_HOST);
		if (is_string($idn) && $idn != '') {
			// https://wiki.php.net/rfc/deprecate-and-remove-intl_idna_variant_2003
			if (defined('INTL_IDNA_VARIANT_UTS46')) {
				$puny = idn_to_ascii($idn, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
			} elseif (defined('INTL_IDNA_VARIANT_2003')) {
				$puny = idn_to_ascii($idn, IDNA_DEFAULT, INTL_IDNA_VARIANT_2003);
			} else {
				$puny = idn_to_ascii($idn);
			}
			$pos = strpos($url, $idn);
			if ($puny != false && $pos !== false) {
				$url = substr_replace($url, $puny, $pos, strlen($idn));
			}
		}
	}
	return $url;
}

/**
 * @return string|false
 */
function checkUrl(string $url, bool $fixScheme = true) {
	$url = trim($url);
	if ($url == '') {
		return '';
	}
	if ($fixScheme && !preg_match('#^https?://#i', $url)) {
		$url = 'https://' . ltrim($url, '/');
	}

	$url = idn_to_puny($url);	//PHP bug #53474 IDN
	$urlRelaxed = str_replace('_', 'z', $url);	//PHP discussion #64948 Underscore

	if (filter_var($urlRelaxed, FILTER_VALIDATE_URL)) {
		return $url;
	} else {
		return false;
	}
}

/**
 * @param string $text
 * @return string
 */
function safe_ascii($text) {
	return filter_var($text, FILTER_DEFAULT, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH) ?: '';
}

if (function_exists('mb_convert_encoding')) {
	function safe_utf8(string $text): string {
		return mb_convert_encoding($text, 'UTF-8', 'UTF-8') ?: '';
	}
} elseif (function_exists('iconv')) {
	function safe_utf8(string $text): string {
		return iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: '';
	}
} else {
	function safe_utf8(string $text): string {
		return $text;
	}
}

/**
 * @param string $text
 * @param bool $extended
 * @return string
 */
function escapeToUnicodeAlternative($text, $extended = true) {
	$text = htmlspecialchars_decode($text, ENT_QUOTES);

	//Problematic characters
	$problem = array('&', '<', '>');
	//Use their fullwidth Unicode form instead:
	$replace = array('＆', '＜', '＞');

	// https://raw.githubusercontent.com/mihaip/google-reader-api/master/wiki/StreamId.wiki
	if ($extended) {
		$problem += array("'", '"', '^', '?', '\\', '/', ',', ';');
		$replace += array("’", '＂', '＾', '？', '＼', '／', '，', '；');
	}

	return trim(str_replace($problem, $replace, $text));
}

function format_number(float $n, int $precision = 0): string {
	// number_format does not seem to be Unicode-compatible
	return str_replace(' ', ' ',	// Thin non-breaking space
		number_format($n, $precision, '.', ' ')
	);
}

function format_bytes(int $bytes, int $precision = 2, string $system = 'IEC'): string {
	if ($system === 'IEC') {
		$base = 1024;
		$units = array('B', 'KiB', 'MiB', 'GiB', 'TiB');
	} elseif ($system === 'SI') {
		$base = 1000;
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
	} else {
		return format_number($bytes, $precision);
	}
	$bytes = max(intval($bytes), 0);
	$pow = $bytes === 0 ? 0 : floor(log($bytes) / log($base));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow($base, $pow);
	return format_number($bytes, $precision) . ' ' . $units[$pow];
}

function timestamptodate(int $t, bool $hour = true): string {
	$month = _t('gen.date.' . date('M', $t));
	if ($hour) {
		$date = _t('gen.date.format_date_hour', $month);
	} else {
		$date = _t('gen.date.format_date', $month);
	}

	return @date($date, $t) ?: '';
}

/**
 * Decode HTML entities but preserve XML entities.
 */
function html_only_entity_decode(?string $text): string {
	static $htmlEntitiesOnly = null;
	if ($htmlEntitiesOnly === null) {
		$htmlEntitiesOnly = array_flip(array_diff(
			get_html_translation_table(HTML_ENTITIES, ENT_NOQUOTES, 'UTF-8'),	//Decode HTML entities
			get_html_translation_table(HTML_SPECIALCHARS, ENT_NOQUOTES, 'UTF-8')	//Preserve XML entities
		));
	}
	return $text == null ? '' : strtr($text, $htmlEntitiesOnly);
}

/**
 * Remove passwords in FreshRSS logs.
 * See also ../cli/sensitive-log.sh for Web server logs.
 * @param array<string,mixed>|string $log
 * @return array<string,mixed>|string
 */
function sensitive_log($log) {
	if (is_array($log)) {
		foreach ($log as $k => $v) {
			if (in_array($k, ['api_key', 'Passwd', 'T'])) {
				$log[$k] = '██';
			} elseif (is_array($v) || is_string($v)) {
				$log[$k] = sensitive_log($v);
			} else {
				return '';
			}
		}
	} elseif (is_string($log)) {
		$log = preg_replace([
				'/\b(auth=.*?\/)[^&]+/i',
				'/\b(Passwd=)[^&]+/i',
				'/\b(Authorization)[^&]+/i',
			], '$1█', $log) ?? '';
	}
	return $log;
}

/**
 * @param array<string,mixed> $attributes
 */
function customSimplePie($attributes = array()): SimplePie {
	if (FreshRSS_Context::$system_conf === null) {
		throw new FreshRSS_Context_Exception('System configuration not initialised!');
	}
	$limits = FreshRSS_Context::$system_conf->limits;
	$simplePie = new SimplePie();
	$simplePie->set_useragent(FRESHRSS_USERAGENT);
	$simplePie->set_syslog(FreshRSS_Context::$system_conf->simplepie_syslog_enabled);
	$simplePie->set_cache_name_function('sha1');
	$simplePie->set_cache_location(CACHE_PATH);
	$simplePie->set_cache_duration($limits['cache_duration']);
	$simplePie->enable_order_by_date(false);

	$feed_timeout = empty($attributes['timeout']) ? 0 : intval($attributes['timeout']);
	$simplePie->set_timeout($feed_timeout > 0 ? $feed_timeout : $limits['timeout']);

	$curl_options = FreshRSS_Context::$system_conf->curl_options;
	if (isset($attributes['ssl_verify'])) {
		$curl_options[CURLOPT_SSL_VERIFYHOST] = $attributes['ssl_verify'] ? 2 : 0;
		$curl_options[CURLOPT_SSL_VERIFYPEER] = $attributes['ssl_verify'] ? true : false;
		if (!$attributes['ssl_verify']) {
			$curl_options[CURLOPT_SSL_CIPHER_LIST] = 'DEFAULT@SECLEVEL=1';
		}
	}
	if (!empty($attributes['curl_params']) && is_array($attributes['curl_params'])) {
		foreach ($attributes['curl_params'] as $co => $v) {
			$curl_options[$co] = $v;
		}
	}
	$simplePie->set_curl_options($curl_options);

	$simplePie->strip_comments(true);
	$simplePie->strip_htmltags(array(
		'base', 'blink', 'body', 'doctype', 'embed',
		'font', 'form', 'frame', 'frameset', 'html',
		'link', 'input', 'marquee', 'meta', 'noscript',
		'object', 'param', 'plaintext', 'script', 'style',
		'svg',	//TODO: Support SVG after sanitizing and URL rewriting of xlink:href
	));
	$simplePie->rename_attributes(array('id', 'class'));
	$simplePie->strip_attributes(array_merge($simplePie->strip_attributes, array(
		'autoplay', 'class', 'onload', 'onunload', 'onclick', 'ondblclick', 'onmousedown', 'onmouseup',
		'onmouseover', 'onmousemove', 'onmouseout', 'onfocus', 'onblur',
		'onkeypress', 'onkeydown', 'onkeyup', 'onselect', 'onchange', 'seamless', 'sizes', 'srcset')));
	$simplePie->add_attributes(array(
		'audio' => array('controls' => 'controls', 'preload' => 'none'),
		'iframe' => array('sandbox' => 'allow-scripts allow-same-origin'),
		'video' => array('controls' => 'controls', 'preload' => 'none'),
	));
	$simplePie->set_url_replacements(array(
		'a' => 'href',
		'area' => 'href',
		'audio' => 'src',
		'blockquote' => 'cite',
		'del' => 'cite',
		'form' => 'action',
		'iframe' => 'src',
		'img' => array(
			'longdesc',
			'src'
		),
		'input' => 'src',
		'ins' => 'cite',
		'q' => 'cite',
		'source' => 'src',
		'track' => 'src',
		'video' => array(
			'poster',
			'src',
		),
	));
	$https_domains = array();
	$force = @file(FRESHRSS_PATH . '/force-https.default.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (is_array($force)) {
		$https_domains = array_merge($https_domains, $force);
	}
	$force = @file(DATA_PATH . '/force-https.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (is_array($force)) {
		$https_domains = array_merge($https_domains, $force);
	}
	$simplePie->set_https_domains($https_domains);
	return $simplePie;
}

/**
 * @param string $data
 */
function sanitizeHTML($data, string $base = '', ?int $maxLength = null): string {
	if (!is_string($data) || ($maxLength !== null && $maxLength <= 0)) {
		return '';
	}
	if ($maxLength !== null) {
		$data = mb_strcut($data, 0, $maxLength, 'UTF-8');
	}
	static $simplePie = null;
	if ($simplePie == null) {
		$simplePie = customSimplePie();
		$simplePie->init();
	}
	$result = html_only_entity_decode($simplePie->sanitize->sanitize($data, SIMPLEPIE_CONSTRUCT_HTML, $base));
	if ($maxLength !== null && strlen($result) > $maxLength) {
		//Sanitizing has made the result too long so try again shorter
		$data = mb_strcut($result, 0, (2 * $maxLength) - strlen($result) - 2, 'UTF-8');
		return sanitizeHTML($data, $base, $maxLength);
	}
	return $result;
}

function cleanCache(int $hours = 720): void {
	// N.B.: GLOB_BRACE is not available on all platforms
	$files = array_merge(
		glob(CACHE_PATH . '/*.html', GLOB_NOSORT) ?: [],
		glob(CACHE_PATH . '/*.json', GLOB_NOSORT) ?: [],
		glob(CACHE_PATH . '/*.spc', GLOB_NOSORT) ?: [],
		glob(CACHE_PATH . '/*.xml', GLOB_NOSORT) ?: []);
	foreach ($files as $file) {
		if (substr($file, -10) === 'index.html') {
			continue;
		}
		$cacheMtime = @filemtime($file);
		if ($cacheMtime !== false && $cacheMtime < time() - (3600 * $hours)) {
			unlink($file);
		}
	}
}

/**
 * Set an XML preamble to enforce the HTML content type charset received by HTTP.
 * @param string $html the row downloaded HTML content
 * @param string $contentType an HTTP Content-Type such as 'text/html; charset=utf-8'
 * @return string an HTML string with XML encoding information for DOMDocument::loadHTML()
 */
function enforceHttpEncoding(string $html, string $contentType = ''): string {
	$httpCharset = preg_match('/\bcharset=([0-9a-z_-]{2,12})$/i', $contentType, $matches) === 1 ? $matches[1] : '';
	if ($httpCharset == '') {
		// No charset defined by HTTP, do nothing
		return $html;
	}
	$httpCharsetNormalized = SimplePie_Misc::encoding($httpCharset);
	if ($httpCharsetNormalized === 'windows-1252') {
		// Default charset for HTTP, do nothing
		return $html;
	}
	if (substr($html, 0, 3) === "\xEF\xBB\xBF" || // UTF-8 BOM
		substr($html, 0, 2) === "\xFF\xFE" || // UTF-16 Little Endian BOM
		substr($html, 0, 2) === "\xFE\xFF" || // UTF-16 Big Endian BOM
		substr($html, 0, 4) === "\xFF\xFE\x00\x00" || // UTF-32 Little Endian BOM
		substr($html, 0, 4) === "\x00\x00\xFE\xFF") { // UTF-32 Big Endian BOM
		// Existing byte order mark, do nothing
		return $html;
	}
	if (preg_match('/^<[?]xml[^>]+encoding\b/', substr($html, 0, 64))) {
		// Existing XML declaration, do nothing
		return $html;
	}
	return '<' . '?xml version="1.0" encoding="' . $httpCharsetNormalized . '" ?' . ">\n" . $html;
}

/**
 * @param string $type {html,json,opml,xml}
 * @param array<string,mixed> $attributes
 */
function httpGet(string $url, string $cachePath, string $type = 'html', array $attributes = []): string {
	if (FreshRSS_Context::$system_conf === null) {
		throw new FreshRSS_Context_Exception('System configuration not initialised!');
	}
	$limits = FreshRSS_Context::$system_conf->limits;
	$feed_timeout = empty($attributes['timeout']) ? 0 : intval($attributes['timeout']);

	$cacheMtime = @filemtime($cachePath);
	if ($cacheMtime !== false && $cacheMtime > time() - intval($limits['cache_duration'])) {
		$body = @file_get_contents($cachePath);
		if ($body != false) {
			syslog(LOG_DEBUG, 'FreshRSS uses cache for ' . SimplePie_Misc::url_remove_credentials($url));
			return $body;
		}
	}

	if (mt_rand(0, 30) === 1) {	// Remove old entries once in a while
		cleanCache(CLEANCACHE_HOURS);
	}

	if (FreshRSS_Context::$system_conf->simplepie_syslog_enabled) {
		syslog(LOG_INFO, 'FreshRSS GET ' . $type . ' ' . SimplePie_Misc::url_remove_credentials($url));
	}

	$accept = '*/*;q=0.8';
	switch ($type) {
		case 'json':
			$accept = 'application/json,application/javascript;q=0.9,text/javascript;q=0.8,*/*;q=0.7';
			break;
		case 'opml':
			$accept = 'text/x-opml,text/xml;q=0.9,application/xml;q=0.9,*/*;q=0.8';
			break;
		case 'xml':
			$accept = 'application/xml,application/xhtml+xml,text/xml;q=0.9,*/*;q=0.8';
			break;
		case 'html':
		default:
			$accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
			break;
	}

	// TODO: Implement HTTP 1.1 conditional GET If-Modified-Since
	$ch = curl_init();
	curl_setopt_array($ch, [
		CURLOPT_URL => $url,
		CURLOPT_HTTPHEADER => array('Accept: ' . $accept),
		CURLOPT_USERAGENT => FRESHRSS_USERAGENT,
		CURLOPT_CONNECTTIMEOUT => $feed_timeout > 0 ? $feed_timeout : $limits['timeout'],
		CURLOPT_TIMEOUT => $feed_timeout > 0 ? $feed_timeout : $limits['timeout'],
		CURLOPT_MAXREDIRS => 4,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_ENCODING => '',	//Enable all encodings
	]);

	curl_setopt_array($ch, FreshRSS_Context::$system_conf->curl_options);

	if (isset($attributes['curl_params']) && is_array($attributes['curl_params'])) {
		curl_setopt_array($ch, $attributes['curl_params']);
	}

	if (isset($attributes['ssl_verify'])) {
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $attributes['ssl_verify'] ? 2 : 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $attributes['ssl_verify'] ? true : false);
		if (!$attributes['ssl_verify']) {
			curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1');
		}
	}
	$body = curl_exec($ch);
	$c_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$c_content_type = '' . curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	$c_error = curl_error($ch);
	curl_close($ch);

	if ($c_status != 200 || $c_error != '' || $body === false) {
		Minz_Log::warning('Error fetching content: HTTP code ' . $c_status . ': ' . $c_error . ' ' . $url);
		$body = '';
		// TODO: Implement HTTP 410 Gone
	}
	if (!is_string($body)) {
		$body = '';
	} else {
		$body = enforceHttpEncoding($body, $c_content_type);
	}

	if (file_put_contents($cachePath, $body) === false) {
		Minz_Log::warning("Error saving cache $cachePath for $url");
	}

	return $body;
}

/**
 * Validate an email address, supports internationalized addresses.
 *
 * @param string $email The address to validate
 * @return bool true if email is valid, else false
 */
function validateEmailAddress(string $email): bool {
	$mailer = new PHPMailer\PHPMailer\PHPMailer();
	$mailer->CharSet = 'utf-8';
	$punyemail = $mailer->punyencodeAddress($email);
	return PHPMailer\PHPMailer\PHPMailer::validateAddress($punyemail, 'html5');
}

/**
 * Add support of image lazy loading
 * Move content from src attribute to data-original
 * @param string $content is the text we want to parse
 */
function lazyimg(string $content): string {
	return preg_replace([
			'/<((?:img|iframe)[^>]+?)src="([^"]+)"([^>]*)>/i',
			"/<((?:img|iframe)[^>]+?)src='([^']+)'([^>]*)>/i",
		], [
			'<$1src="' . Minz_Url::display('/themes/icons/grey.gif') . '" data-original="$2"$3>',
			"<$1src='" . Minz_Url::display('/themes/icons/grey.gif') . "' data-original='$2'$3>",
		],
		$content
	) ?? '';
}

function uTimeString(): string {
	$t = @gettimeofday();
	return $t['sec'] . str_pad('' . $t['usec'], 6, '0', STR_PAD_LEFT);
}

function invalidateHttpCache(string $username = ''): bool {
	if (!FreshRSS_user_Controller::checkUsername($username)) {
		Minz_Session::_param('touch', uTimeString());
		$username = Minz_User::name() ?? Minz_User::INTERNAL_USER;
	}
	$ok = @touch(DATA_PATH . '/users/' . $username . '/' . LOG_FILENAME);
	//if (!$ok) {
		//TODO: Display notification error on front-end
	//}
	return $ok;
}

/**
 * @return array<string>
 */
function listUsers(): array {
	$final_list = array();
	$base_path = join_path(DATA_PATH, 'users');
	$dir_list = array_values(array_diff(
		scandir($base_path) ?: [],
		['..', '.', Minz_User::INTERNAL_USER]
	));
	foreach ($dir_list as $file) {
		if ($file[0] !== '.' && is_dir(join_path($base_path, $file)) && file_exists(join_path($base_path, $file, 'config.php'))) {
			$final_list[] = $file;
		}
	}
	return $final_list;
}


/**
 * Return if the maximum number of registrations has been reached.
 * Note a max_registrations of 0 means there is no limit.
 *
 * @return boolean true if number of users >= max registrations, false else.
 */
function max_registrations_reached(): bool {
	if (FreshRSS_Context::$system_conf === null) {
		throw new FreshRSS_Context_Exception('System configuration not initialised!');
	}
	$limit_registrations = FreshRSS_Context::$system_conf->limits['max_registrations'];
	$number_accounts = count(listUsers());

	return $limit_registrations > 0 && $number_accounts >= $limit_registrations;
}


/**
 * Register and return the configuration for a given user.
 *
 * Note this function has been created to generate temporary configuration
 * objects. If you need a long-time configuration, please don't use this function.
 *
 * @param string $username the name of the user of which we want the configuration.
 * @return FreshRSS_UserConfiguration|null object, or null if the configuration cannot be loaded.
 */
function get_user_configuration(string $username) {
	if (!FreshRSS_user_Controller::checkUsername($username)) {
		return null;
	}
	$namespace = 'user_' . $username;
	try {
		Minz_Configuration::register($namespace,
			USERS_PATH . '/' . $username . '/config.php',
			FRESHRSS_PATH . '/config-user.default.php');
	} catch (Minz_ConfigurationNamespaceException $e) {
		// namespace already exists, do nothing.
		Minz_Log::warning($e->getMessage(), ADMIN_LOG);
	} catch (Minz_FileNotExistException $e) {
		Minz_Log::warning($e->getMessage(), ADMIN_LOG);
		return null;
	}

	/**
	 * @var FreshRSS_UserConfiguration $user_conf
	 */
	$user_conf = Minz_Configuration::get($namespace);
	return $user_conf;
}

/**
 * Converts an IP (v4 or v6) to a binary representation using inet_pton
 *
 * @param string $ip the IP to convert
 * @return string a binary representation of the specified IP
 */
function ipToBits(string $ip): string {
	$binaryip = '';
	foreach (str_split(inet_pton($ip) ?: '') as $char) {
		$binaryip .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
	}
	return $binaryip;
}

/**
 * Check if an ip belongs to the provided range (in CIDR format)
 *
 * @param string $ip the IP that we want to verify (ex: 192.168.16.1)
 * @param string $range the range to check against (ex: 192.168.16.0/24)
 * @return boolean true if the IP is in the range, otherwise false
 */
function checkCIDR(string $ip, string $range): bool {
	$binary_ip = ipToBits($ip);
	list($subnet, $mask_bits) = explode('/', $range);
	$mask_bits = intval($mask_bits);
	$binary_subnet = ipToBits($subnet);

	$ip_net_bits = substr($binary_ip, 0, $mask_bits);
	$subnet_bits = substr($binary_subnet, 0, $mask_bits);

	return $ip_net_bits === $subnet_bits;
}

/**
 * Check if the client is allowed to send unsafe headers
 * This uses the REMOTE_ADDR header to determine the sender's IP
 * and the configuration option "trusted_sources" to get an array of the authorized ranges
 *
 * @return boolean, true if the sender's IP is in one of the ranges defined in the configuration, else false
 */
function checkTrustedIP(): bool {
	if (FreshRSS_Context::$system_conf === null) {
		throw new FreshRSS_Context_Exception('System configuration not initialised!');
	}
	if (!empty($_SERVER['REMOTE_ADDR'])) {
		foreach (FreshRSS_Context::$system_conf->trusted_sources as $cidr) {
			if (checkCIDR($_SERVER['REMOTE_ADDR'], $cidr)) {
				return true;
			}
		}
	}
	return false;
}

function httpAuthUser(): string {
	if (!empty($_SERVER['REMOTE_USER'])) {
		return $_SERVER['REMOTE_USER'];
	} elseif (!empty($_SERVER['HTTP_REMOTE_USER']) && checkTrustedIP()) {
		return $_SERVER['HTTP_REMOTE_USER'];
	} elseif (!empty($_SERVER['REDIRECT_REMOTE_USER'])) {
		return $_SERVER['REDIRECT_REMOTE_USER'];
	} elseif (!empty($_SERVER['HTTP_X_WEBAUTH_USER']) && checkTrustedIP()) {
		return $_SERVER['HTTP_X_WEBAUTH_USER'];
	}
	return '';
}

function cryptAvailable(): bool {
	try {
		$hash = '$2y$04$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
		return $hash === @crypt('password', $hash);
	} catch (Exception $e) {
		Minz_Log::warning($e->getMessage());
	}
	return false;
}


/**
 * Check PHP and its extensions are well-installed.
 *
 * @return array<string,bool> of tested values.
 */
function check_install_php(): array {
	$pdo_mysql = extension_loaded('pdo_mysql');
	$pdo_pgsql = extension_loaded('pdo_pgsql');
	$pdo_sqlite = extension_loaded('pdo_sqlite');
	return array(
		'php' => version_compare(PHP_VERSION, FRESHRSS_MIN_PHP_VERSION) >= 0,
		'curl' => extension_loaded('curl'),
		'pdo' => $pdo_mysql || $pdo_sqlite || $pdo_pgsql,
		'pcre' => extension_loaded('pcre'),
		'ctype' => extension_loaded('ctype'),
		'fileinfo' => extension_loaded('fileinfo'),
		'dom' => class_exists('DOMDocument'),
		'json' => extension_loaded('json'),
		'mbstring' => extension_loaded('mbstring'),
		'zip' => extension_loaded('zip'),
	);
}


/**
 * Check different data files and directories exist.
 *
 * @return array<string,bool> of tested values.
 */
function check_install_files(): array {
	return array(
		// @phpstan-ignore-next-line
		'data' => DATA_PATH && touch(DATA_PATH . '/index.html'),	// is_writable() is not reliable for a folder on NFS
		// @phpstan-ignore-next-line
		'cache' => CACHE_PATH && touch(CACHE_PATH . '/index.html'),
		// @phpstan-ignore-next-line
		'users' => USERS_PATH && touch(USERS_PATH . '/index.html'),
		'favicons' => touch(DATA_PATH . '/favicons/index.html'),
		'tokens' => touch(DATA_PATH . '/tokens/index.html'),
	);
}


/**
 * Check database is well-installed.
 *
 * @return array<string,bool> of tested values.
 */
function check_install_database(): array {
	$status = array(
		'connection' => true,
		'tables' => false,
		'categories' => false,
		'feeds' => false,
		'entries' => false,
		'entrytmp' => false,
		'tag' => false,
		'entrytag' => false,
	);

	try {
		$dbDAO = FreshRSS_Factory::createDatabaseDAO();

		$status['tables'] = $dbDAO->tablesAreCorrect();
		$status['categories'] = $dbDAO->categoryIsCorrect();
		$status['feeds'] = $dbDAO->feedIsCorrect();
		$status['entries'] = $dbDAO->entryIsCorrect();
		$status['entrytmp'] = $dbDAO->entrytmpIsCorrect();
		$status['tag'] = $dbDAO->tagIsCorrect();
		$status['entrytag'] = $dbDAO->entrytagIsCorrect();
	} catch(Minz_PDOConnectionException $e) {
		$status['connection'] = false;
	}

	return $status;
}

/**
 * Remove a directory recursively.
 * From http://php.net/rmdir#110489
 */
function recursive_unlink(string $dir): bool {
	if (!is_dir($dir)) {
		return true;
	}

	$files = array_diff(scandir($dir) ?: [], ['.', '..']);
	foreach ($files as $filename) {
		$filename = $dir . '/' . $filename;
		if (is_dir($filename)) {
			@chmod($filename, 0777);
			recursive_unlink($filename);
		} else {
			unlink($filename);
		}
	}

	return rmdir($dir);
}

/**
 * Remove queries where $get is appearing.
 * @param string $get the get attribute which should be removed.
 * @param array<int,array<string,string>> $queries an array of queries.
 * @return array<int,array<string,string>> without queries where $get is appearing.
 */
function remove_query_by_get(string $get, array $queries): array {
	$final_queries = array();
	foreach ($queries as $key => $query) {
		if (empty($query['get']) || $query['get'] !== $get) {
			$final_queries[$key] = $query;
		}
	}
	return $final_queries;
}

function _i(string $icon, int $type = FreshRSS_Themes::ICON_DEFAULT): string {
	return FreshRSS_Themes::icon($icon, $type);
}


const SHORTCUT_KEYS = [
			'0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
			'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
			'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
			'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
			'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'Backspace', 'Delete',
			'End', 'Enter', 'Escape', 'Home', 'Insert', 'PageDown', 'PageUp', 'Space', 'Tab',
		];

/**
 * @param array<string> $shortcuts
 * @return array<string>
 */
function getNonStandardShortcuts(array $shortcuts): array {
	$standard = strtolower(implode(' ', SHORTCUT_KEYS));

	$nonStandard = array_filter($shortcuts, function ($shortcut) use ($standard) {
		$shortcut = trim($shortcut);
		return $shortcut !== '' & stripos($standard, $shortcut) === false;
	});

	return $nonStandard;
}

function errorMessageInfo(string $errorTitle, string $error = ''): string {
	$errorTitle = htmlspecialchars($errorTitle, ENT_NOQUOTES, 'UTF-8');

	$message = '';
	$details = '';
	// Prevent empty tags by checking if error isn not empty first
	if ($error) {
		$error = htmlspecialchars($error, ENT_NOQUOTES, 'UTF-8') . "\n";

		// First line is the main message, other lines are the details
		list($message, $details) = explode("\n", $error, 2);

		$message = "<h2>{$message}</h2>";
		$details = "<pre>{$details}</pre>";
	}

	header("Content-Security-Policy: default-src 'self'");

	return <<<MSG
	<!DOCTYPE html><html><header><title>HTTP 500: {$errorTitle}</title></header><body>
	<h1>HTTP 500: {$errorTitle}</h1>
	{$message}
	{$details}
	<hr />
	<small>For help see the documentation: <a href="https://freshrss.github.io/FreshRSS/en/admins/logs_and_errors.html" target="_blank">
	https://freshrss.github.io/FreshRSS/en/admins/logs_and_errors.html</a></small>
	</body></html>
MSG;
}
