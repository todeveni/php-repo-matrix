<?php

/**
 * PHP build matrix
 *
 * Builds an HTML matrix of PHP versions (and their package versions) per
 * distro and architecture for ppa:ondrej/php and deb.sury.org/php, so
 * missing or lagging builds stand out (e.g. PHP 7.4 for armhf on 24.04).
 *
 * The main matrix tracks the core/SAPI packages of each PHP branch
 * (phpX.Y, -common, -cli, -fpm, -cgi, Apache module, ...). All other
 * packages are listed below it. Clicking a main matrix cell shows the
 * latest changelog entries of that source package.
 *
 * Builds in progress are marked in the matrix, so an in-flight upload isn't
 * mistaken for a broken one: queued/running Launchpad builds for the PPA, and
 * for deb.sury.org (whose Jenkins isn't public) packaging branches with a
 * commit newer than the published build, or a newer version building on the
 * PPA.
 *
 * Package versions are also compared with the latest releases on php.net,
 * so a branch that hasn't picked up a new upstream release is flagged.
 * Other source packages (extensions, libraries) are checked for newer
 * upstream releases with their debian/watch file, like uscan does.
 *
 * Usage (CLI):
 *   php php-repo-matrix.php [--output=matrix.html|-] [--config=file.php]
 *                           [--no-cache] [--no-publish] [--include-unsupported]
 *
 * The report is written to matrix.html next to this script (--output=- for
 * stdout) and then copied to every 'publish' target with scp.
 *
 * Caching: Release files are cached and revalidated with If-Modified-Since
 * (after cache_ttl). Packages/Sources indexes are cached by the SHA256 listed
 * in Release, so they are only downloaded again when they actually change.
 * Changelogs and Launchpad lookups are keyed by package version and never
 * refetched. Pages named in debian/watch are reused for watch_ttl, then
 * revalidated the same way. --no-cache forces revalidation of Release files
 * and watched pages even within their TTL; content-addressed entries are
 * always reused.
 *
 * It can also be served by a web server, in which case HTML goes to the client.
 *
 * Configuration: the defaults below can be overridden by a PHP file returning
 * an array (php-repo-matrix.config.php next to this script, or --config=...).
 * Top-level keys are replaced as a whole.
 */

declare(strict_types=1);

$config = [
	// Supported releases, per distro family. Anything older is shown as
	// unsupported, with a pointer to Freexian's commercial PHP LTS.
	'supported' => [
		'Debian' => ['12', '13'],
		'Ubuntu' => ['22.04', '24.04', '26.04'],
	],

	// Known releases: codename => [family, version].
	'distros' => [
		'jessie'   => ['Debian', '8'],
		'stretch'  => ['Debian', '9'],
		'buster'   => ['Debian', '10'],
		'bullseye' => ['Debian', '11'],
		'bookworm' => ['Debian', '12'],
		'trixie'   => ['Debian', '13'],
		'forky'    => ['Debian', '14'],
		'trusty'   => ['Ubuntu', '14.04'],
		'xenial'   => ['Ubuntu', '16.04'],
		'bionic'   => ['Ubuntu', '18.04'],
		'focal'    => ['Ubuntu', '20.04'],
		'jammy'    => ['Ubuntu', '22.04'],
		'noble'    => ['Ubuntu', '24.04'],
		'resolute' => ['Ubuntu', '26.04'],
	],

	// APT repositories to inspect, in display order. 'changelog' is either
	// ['type' => 'launchpad', 'archive' => '~owner/+archive/ubuntu/name'] or
	// ['type' => 'pool'] (debian/changelog read from the .debian.tar.xz).
	// 'builds' (optional) is where build status comes from:
	// ['type' => 'launchpad', 'archive' => ...] for queued/running builds, or
	// ['type' => 'vcs'] to compare the gbp commit in the version with the
	// GitLab branch named in Vcs-Browser. 'proxy' => repoKey additionally
	// takes a newer version building in that repo as "probably building" here.
	'repos' => [
		'sury' => [
			'label'     => 'deb.sury.org/php',
			'url'       => 'https://packages.sury.org/php',
			'families'  => ['Debian', 'Ubuntu'],
			'component' => 'main',
			'changelog' => ['type' => 'pool'],
			'builds'    => ['type' => 'vcs', 'proxy' => 'ppa'],
		],
		'ppa' => [
			'label'     => 'ppa:ondrej/php',
			'url'       => 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu',
			'web'       => 'https://launchpad.net/~ondrej/+archive/ubuntu/php', // linked instead of 'url'
			'families'  => ['Ubuntu'],
			'component' => 'main',
			'changelog' => ['type' => 'launchpad', 'archive' => '~ondrej/+archive/ubuntu/php'],
			'builds'    => ['type' => 'launchpad', 'archive' => '~ondrej/+archive/ubuntu/php'],
			// Newer releases are only served from deb.sury.org; not publishing
			// them here is expected: they are not listed for this repo at all.
			'last_release' => '24.04',
			// Shown next to the repo wherever it is listed.
			'deprecated' => [
				'text' => 'Moving Ubuntu from PPA to packages.sury.org',
				'url'  => 'https://codeberg.org/oerdnj/deb.sury.org/issues/73',
			],
		],
	],

	// Limit to these architectures; null = whatever each Release file lists.
	'architectures' => null,

	// Architectures never reported, even when a Release file lists them.
	'exclude_architectures' => ['i386', 'riscv64', 's390x', 'ppc64el'],

	// Per-codename additions to exclude_architectures, for archs a release
	// has dropped (Ubuntu 26.04 no longer supports armhf).
	'exclude_codename_architectures' => [
		'resolute' => ['armhf'],
	],

	// Limit to these PHP branches (e.g. ['7.4', '8.3']); null = all found.
	'php_versions' => null,

	// Core and SAPI packages tracked in the main matrix (%s = PHP branch).
	'sapi_packages' => [
		'php%s',
		'php%s-common',
		'php%s-cli',
		'php%s-fpm',
		'php%s-cgi',
		'libapache2-mod-php%s',
		'libphp%s-embed',
		'php%s-litespeed',
	],

	// Changelog entries to show per source package; 0 disables changelogs.
	'changelog_entries' => 5,

	// A packaging commit still not built after this many hours is reported
	// as a possibly failed build.
	'vcs_build_hours' => 6,

	// php.net release data: 'branches' lists every branch with its support
	// state, 'active' the latest release (and its date) of supported ones,
	// 'prerelease' the current alpha/beta/RC of upcoming branches.
	// null disables the upstream check.
	'upstream' => [
		'branches'   => 'https://www.php.net/releases/branches.php',
		'active'     => 'https://www.php.net/releases/active.php',
		'prerelease' => 'https://www.php.net/pre-release-builds.php?format=json',
	],

	// Other source packages are checked for newer upstream releases with
	// their debian/watch file. The watched pages are reused this many
	// seconds, then revalidated with If-Modified-Since; null disables it.
	'watch_ttl' => 86400,
	// Also report alpha/beta/RC releases (always, when one is packaged).
	'watch_prereleases' => false,

	'cache_dir'    => sys_get_temp_dir() . '/php-repo-matrix-cache',
	// Release files (and other unversioned lookups) are reused this many
	// seconds without asking; after that they are revalidated with
	// If-Modified-Since, so 0 = always ask, but download only when changed.
	'cache_ttl'    => 0,
	// Remove cache entries unused for this many days.
	'cache_prune_days' => 30,
	'http_timeout' => 60,

	// Sent for repository downloads, the way apt identifies itself.
	'user_agent'   => 'Debian APT-HTTP/1.3 (3.0.3)',

	// scp targets for the generated report (empty = don't publish).
	'publish' => [],

	'freexian_url' => 'https://www.freexian.com/lts/php/',

	// Linked in the header and footer of the report.
	'source_url' => 'https://github.com/todeveni/php-repo-matrix',
];

// ---------------------------------------------------------------------------
// Options

$isCli = PHP_SAPI === 'cli';
$opts = $isCli ? getopt('', ['output:', 'config:', 'no-cache', 'no-publish', 'include-unsupported', 'help']) : [];

if (isset($opts['help'])) {
	fwrite(
		STDOUT,
		'Usage: php ' . basename(__FILE__)
			. " [--output=file.html|-] [--config=file.php] [--no-cache] [--no-publish] [--include-unsupported]\n"
	);
	exit(0);
}

$configFile = $opts['config'] ?? __DIR__ . '/php-repo-matrix.config.php';
if (is_file($configFile)) {
	$override = require $configFile;
	if (!is_array($override)) {
		fail("Config file $configFile must return an array");
	}
	$config = array_replace($config, $override);
} elseif (isset($opts['config'])) {
	fail("Config file $configFile not found");
}

if (isset($opts['no-cache'])) {
	$config['cache_ttl'] = 0;
	if ($config['watch_ttl'] !== null) {
		$config['watch_ttl'] = 0;
	}
}
$includeUnsupported = isset($opts['include-unsupported']);

// ---------------------------------------------------------------------------
// Helpers

function fail(string $message): never
{
	fwrite(STDERR, "Error: $message\n");
	exit(1);
}

function info(string $message): void
{
	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . "\n");
	}
}

function h(?string $s): string
{
	return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cache_path(string $key, array $config): string
{
	return $config['cache_dir'] . '/' . sha1($key);
}

/**
 * Fetches URLs in parallel, with an on-disk cache.
 *
 * Entries with a version key (or an expected SHA256) are immutable: they are
 * reused regardless of age, and touched on use so pruning keeps them.
 * Other entries are reused for cache_ttl seconds, then revalidated with
 * If-Modified-Since / If-None-Match, so unchanged files cost only a 304.
 *
 * @param string[]              $urls
 * @param array<string, string> $versionKeys url => key that changes whenever the content does
 * @param array<string, string> $sha256      url => expected SHA256 (implies a version key)
 * @param string|null           $userAgent   defaults to the configured (APT-like) user agent
 * @param int|null              $ttl         defaults to cache_ttl
 * @return array<string, ?string> url => body, null when not found / failed
 */
function fetch_all(
	array $urls,
	array $config,
	array $versionKeys = [],
	array $sha256 = [],
	?string $userAgent = null,
	?int $ttl = null
): array {
	$dir = $config['cache_dir'];
	if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
		fail("Cannot create cache dir $dir");
	}

	$results = [];
	$pending = []; // url => ['file' => ..., 'meta' => validators of the cached copy]
	foreach (array_unique($urls) as $url) {
		$version = $sha256[$url] ?? $versionKeys[$url] ?? null;
		$file = cache_path($version === null ? $url : "$url#$version", $config);
		$cached = is_file($file);
		if ($cached && ($version !== null || time() - filemtime($file) < ($ttl ?? $config['cache_ttl']))) {
			if ($version !== null) {
				touch($file);
			}
			$body = file_get_contents($file);
			$results[$url] = str_starts_with($body, "MISSING\n") ? null : $body;
			continue;
		}
		$meta = $cached && is_file("$file.meta") ? json_decode((string) file_get_contents("$file.meta"), true) : null;
		$pending[$url] = ['file' => $file, 'meta' => is_array($meta) ? $meta : []];
	}
	$fromCache = count($results);
	if (!$pending) {
		info("  $fromCache cached");
		return $results;
	}

	$mh = curl_multi_init();
	curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, 8);
	$handles = [];
	$responseHeaders = [];
	foreach ($pending as $url => $entry) {
		$headers = [];
		if (isset($entry['meta']['last_modified'])) {
			$headers[] = 'If-Modified-Since: ' . $entry['meta']['last_modified'];
		}
		if (isset($entry['meta']['etag'])) {
			$headers[] = 'If-None-Match: ' . $entry['meta']['etag'];
		}
		$responseHeaders[$url] = [];
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => $config['http_timeout'],
			CURLOPT_USERAGENT      => $userAgent ?? $config['user_agent'],
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders, $url): int {
				if (preg_match('/^(Last-Modified|ETag):\s*(.+?)\s*$/i', $line, $m)) {
					$responseHeaders[$url][strtolower($m[1]) === 'etag' ? 'etag' : 'last_modified'] = $m[2];
				}
				return strlen($line);
			},
		]);
		curl_multi_add_handle($mh, $ch);
		$handles[$url] = $ch;
	}

	do {
		$status = curl_multi_exec($mh, $running);
		if ($running) {
			curl_multi_select($mh);
		}
	} while ($running && $status === CURLM_OK);

	$unchanged = $downloaded = 0;
	foreach ($handles as $url => $ch) {
		$file = $pending[$url]['file'];
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$body = curl_multi_getcontent($ch);
		if ($code === 304 && is_file($file)) {
			touch($file);
			$results[$url] = file_get_contents($file);
			$unchanged++;
		} elseif (
			$code === 200 && is_string($body) && isset($sha256[$url])
			&& hash('sha256', $body) !== $sha256[$url]
		) {
			// Mirror updated between Release and index fetch; use it, but don't cache it.
			info("Warning: $url does not match the SHA256 in Release, not caching");
			$results[$url] = $body;
			$downloaded++;
		} elseif ($code === 200 && is_string($body)) {
			$results[$url] = $body;
			file_put_contents($file, $body);
			if ($responseHeaders[$url]) {
				file_put_contents("$file.meta", json_encode($responseHeaders[$url]));
			}
			$downloaded++;
		} elseif ($code === 404) {
			// Remember "not published" too, it is a valid answer.
			$results[$url] = null;
			file_put_contents($file, "MISSING\n");
			@unlink("$file.meta");
		} else {
			$stale = is_file($file) ? file_get_contents($file) : null;
			$results[$url] = $stale === null || str_starts_with($stale, "MISSING\n") ? null : $stale;
			info(
				"Warning: $url -> HTTP $code " . curl_error($ch)
					. ($results[$url] !== null ? ' (using cached copy)' : '')
			);
		}
		curl_multi_remove_handle($mh, $ch);
	}
	curl_multi_close($mh);

	info("  $fromCache cached, $unchanged not modified, $downloaded downloaded");
	return $results;
}

/** @return array<string, string> path => SHA256 from a Release file */
function release_sha256(string $text): array
{
	$out = [];
	if (preg_match('/^SHA256:[ \t]*\n((?: .*\n?)+)/m', $text, $m)) {
		foreach (explode("\n", trim($m[1])) as $line) {
			$parts = preg_split('/\s+/', trim($line));
			if (count($parts) === 3) {
				$out[$parts[2]] = $parts[0];
			}
		}
	}
	return $out;
}

/** Parses an APT Release file's top-level fields. */
function parse_release(string $text): array
{
	$fields = [];
	foreach (explode("\n", $text) as $line) {
		if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m)) {
			$fields[$m[1]] = $m[2];
		}
	}
	return $fields;
}

/**
 * Parses a Packages file.
 *
 * @param array<string, string> $sourceOf Filled with binary => source name.
 * @param array<string, bool>   $archAll  Filled with Architecture: all binaries.
 * @return array<string, string> binary => newest version
 */
function parse_packages(string $text, array &$sourceOf, array &$archAll): array
{
	$out = [];
	foreach (preg_split('/\n\s*\n/', $text) as $stanza) {
		if (!preg_match('/^Package:\s*(\S+)/m', $stanza, $p) || !preg_match('/^Version:\s*(\S+)/m', $stanza, $v)) {
			continue;
		}
		$sourceOf[$p[1]] = preg_match('/^Source:\s*(\S+)/m', $stanza, $s) ? $s[1] : $p[1];
		if (preg_match('/^Architecture:\s*all$/m', $stanza)) {
			$archAll[$p[1]] = true;
		}
		if (!isset($out[$p[1]]) || deb_version_compare($v[1], $out[$p[1]]) > 0) {
			$out[$p[1]] = $v[1];
		}
	}
	return $out;
}

/**
 * Parses a Sources file.
 *
 * @return array<string, array{version: string, directory: string, files: string[], vcs: ?string}>
 */
function parse_sources(string $text): array
{
	$out = [];
	foreach (preg_split('/\n\s*\n/', $text) as $stanza) {
		if (!preg_match('/^Package:\s*(\S+)/m', $stanza, $p) || !preg_match('/^Version:\s*(\S+)/m', $stanza, $v)) {
			continue;
		}
		if (isset($out[$p[1]]) && deb_version_compare($v[1], $out[$p[1]]['version']) <= 0) {
			continue;
		}
		$files = [];
		if (preg_match('/^Files:[ \t]*\n((?: .*\n?)+)/m', $stanza, $f)) {
			foreach (explode("\n", trim($f[1])) as $line) {
				$files[] = preg_split('/\s+/', trim($line))[2] ?? '';
			}
		}
		$out[$p[1]] = [
			'version'   => $v[1],
			'directory' => preg_match('/^Directory:\s*(\S+)/m', $stanza, $d) ? $d[1] : '',
			'files'     => $files,
			'vcs'       => preg_match('/^Vcs-Browser:\s*(\S+)/m', $stanza, $b) ? $b[1] : null,
		];
	}
	return $out;
}

/** Returns the first $entries entries of a debian/changelog. */
function changelog_head(string $text, int $entries): string
{
	$out = [];
	$seen = 0;
	foreach (explode("\n", $text) as $line) {
		if (preg_match('/^[a-z0-9][a-z0-9+.-]* \([^)]+\)/i', $line) && ++$seen > $entries) {
			break;
		}
		$out[] = $line;
	}
	return rtrim(implode("\n", $out));
}

/** Extracts one file (debian/changelog, debian/watch) from a .debian.tar.xz blob. */
function file_from_debian_tar(string $tarball, string $path, string $cacheDir): ?string
{
	$tmp = tempnam($cacheDir, 'tar');
	file_put_contents($tmp, $tarball);
	$proc = proc_open(['tar', '-xJOf', $tmp, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$text = null;
	if (is_resource($proc)) {
		$text = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_close($proc) !== 0) {
			$text = null;
		}
	}
	unlink($tmp);
	return $text ?: null;
}

/**
 * Parses a debian/watch file (format 3/4) into its lines.
 *
 * @return list<array{url: string, pattern: string, opts: array<string, string>}>
 */
function parse_watch(string $text): array
{
	$text = preg_replace('/^\s*#.*\n?/m', '', $text);
	$text = preg_replace('/\\\\\r?\n\s*/', '', $text);
	$lines = [];
	foreach (preg_split('/\r?\n/', $text) as $line) {
		$line = trim($line);
		if ($line === '' || preg_match('/^version\s*=/', $line)) {
			continue;
		}
		$opts = [];
		if (preg_match('/^opts\s*=\s*(?:"([^"]*)"|(\S+))\s+(.*)$/', $line, $m)) {
			foreach (preg_split('/,(?=\s*[a-z]+(?:=|,|$))/i', rtrim($m[1] !== '' ? $m[1] : $m[2], ', ')) as $opt) {
				[$k, $v] = array_pad(explode('=', trim($opt), 2), 2, '');
				if ($k !== '') {
					$opts[$k] = $v;
				}
			}
			$line = $m[3];
		}
		$fields = preg_split('/\s+/', $line);
		$url = $fields[0];
		$pattern = $fields[1] ?? null;
		if ($pattern === null) {
			// Single field: the pattern is the last path component.
			$pos = strrpos($url, '/');
			$pattern = substr($url, $pos + 1);
			$url = substr($url, 0, $pos + 1);
		}
		$lines[] = ['url' => $url, 'pattern' => $pattern, 'opts' => $opts];
	}
	return $lines;
}

/**
 * Applies a uscan mangle rule (Perl s/// expressions separated by ";").
 * Returns null for anything that isn't a plain substitution.
 */
function watch_mangle(string $rules, string $version): ?string
{
	if ($rules === 'auto') {
		$rules = 's/[\+~](debian|dfsg|ds|deb)(\.)?(\d+)?$//';
	}
	$i = 0;
	$n = strlen($rules);
	while ($i < $n) {
		if ($rules[$i] === ';' || ctype_space($rules[$i])) {
			$i++;
			continue;
		}
		if ($rules[$i] !== 's' || $i + 1 >= $n) {
			return null;
		}
		$delim = $rules[$i + 1];
		$i += 2;
		$parts = [];
		for ($p = 0; $p < 2; $p++) {
			$part = '';
			while ($i < $n && $rules[$i] !== $delim) {
				if ($rules[$i] === '\\' && $i + 1 < $n) {
					$part .= $rules[$i + 1] === $delim ? $delim : $rules[$i] . $rules[$i + 1];
					$i += 2;
					continue;
				}
				$part .= $rules[$i++];
			}
			if ($i >= $n) {
				return null;
			}
			$i++;
			$parts[] = $part;
		}
		preg_match('/^[a-z]*/', substr($rules, $i), $f);
		$i += strlen($f[0]);
		// Perl replacement: $1 / ${1} are group references, \x is a literal x.
		$replacement = preg_replace_callback('/\\\\(.)|\$\{?(\d+)\}?|\$|\\\\/', static fn ($m) => match (true) {
			($m[1] ?? '') !== '' => $m[1] === '$' ? '\$' : ($m[1] === '\\' ? '\\\\' : $m[1]),
			($m[2] ?? '') !== '' => '${' . $m[2] . '}',
			default              => '\\' . $m[0],
		}, $parts[1]);
		$result = @preg_replace(
			"\x01$parts[0]\x01" . str_replace('g', '', $f[0]),
			$replacement,
			$version,
			str_contains($f[0], 'g') ? -1 : 1
		);
		if ($result === null) {
			return null;
		}
		$version = $result;
	}
	return $version;
}

/** Resolves a link found on $base (relative, root-relative or absolute). */
function resolve_url(string $base, string $href): string
{
	if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
		return $href;
	}
	$u = parse_url($base);
	$root = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
	if (str_starts_with($href, '//')) {
		return ($u['scheme'] ?? 'https') . ':' . $href;
	}
	$path = str_starts_with($href, '/') ? $href : preg_replace('#/[^/]*$#', '/', $u['path'] ?? '/') . $href;
	$out = [];
	foreach (explode('/', $path) as $seg) {
		if ($seg === '..') {
			array_pop($out);
		} elseif ($seg !== '.') {
			$out[] = $seg;
		}
	}
	return $root . implode('/', $out);
}

/**
 * Upstream versions on a watched page, like uscan: in html mode the pattern
 * must match a whole link (as written, absolute, or relative to the page);
 * in plain mode it is searched for anywhere in the page.
 *
 * @param string[]|null $links links to use instead of parsing $body
 * @return string[]
 */
function watch_versions(string $body, string $baseUrl, string $pattern, bool $plain, ?array $links = null): array
{
	$candidates = [];
	if ($plain) {
		$candidates[] = $body;
	} else {
		if ($links === null) {
			preg_match_all('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $body, $m, PREG_SET_ORDER);
			$links = array_map(static fn ($l) => html_entity_decode(($l[1] ?? '') !== '' ? $l[1] : ($l[2] ?? '')), $m);
		}
		$baseDir = preg_replace('#/[^/]*$#', '/', parse_url($baseUrl, PHP_URL_PATH) ?? '/');
		foreach ($links as $href) {
			$abs = resolve_url($baseUrl, $href);
			$path = preg_replace('#^[a-z]+://[^/]+#i', '', $abs);
			$candidates[] = [
				$href,
				$abs,
				$path,
				str_starts_with($path, $baseDir) ? substr($path, strlen($baseDir)) : $href,
			];
		}
	}
	$regex = "\x01" . ($plain ? $pattern : "^(?:$pattern)$") . "\x01";
	$versions = [];
	foreach ($candidates as $forms) {
		foreach ((array) $forms as $form) {
			if (!@preg_match_all($regex, $form, $matches, PREG_SET_ORDER)) {
				continue;
			}
			foreach ($matches as $match) {
				$groups = array_filter(array_slice($match, 1), static fn ($g) => $g !== '');
				if ($groups) {
					$versions[implode('.', $groups)] = true;
				}
			}
			if (!$plain) {
				break;
			}
		}
	}
	return array_keys($versions);
}

/** dpkg character ordering used by verrevcmp(). */
function deb_order(string $c): int
{
	if ($c === '~') {
		return -1;
	}
	if (ctype_digit($c)) {
		return 0;
	}
	if (ctype_alpha($c)) {
		return ord($c);
	}
	return ord($c) + 256;
}

/** Port of dpkg's verrevcmp(). */
function deb_verrevcmp(string $a, string $b): int
{
	$i = $j = 0;
	$la = strlen($a);
	$lb = strlen($b);
	while ($i < $la || $j < $lb) {
		$firstDiff = 0;
		while (($i < $la && !ctype_digit($a[$i])) || ($j < $lb && !ctype_digit($b[$j]))) {
			$ac = $i < $la ? deb_order($a[$i]) : 0;
			$bc = $j < $lb ? deb_order($b[$j]) : 0;
			if ($ac !== $bc) {
				return $ac - $bc;
			}
			$i++;
			$j++;
		}
		while ($i < $la && $a[$i] === '0') {
			$i++;
		}
		while ($j < $lb && $b[$j] === '0') {
			$j++;
		}
		while ($i < $la && ctype_digit($a[$i]) && $j < $lb && ctype_digit($b[$j])) {
			if (!$firstDiff) {
				$firstDiff = ord($a[$i]) - ord($b[$j]);
			}
			$i++;
			$j++;
		}
		if ($i < $la && ctype_digit($a[$i])) {
			return 1;
		}
		if ($j < $lb && ctype_digit($b[$j])) {
			return -1;
		}
		if ($firstDiff) {
			return $firstDiff;
		}
	}
	return 0;
}

/** @return array{int, string, string} epoch, upstream, revision */
function deb_split(string $version): array
{
	$epoch = 0;
	if (preg_match('/^(\d+):(.*)$/', $version, $m)) {
		$epoch = (int) $m[1];
		$version = $m[2];
	}
	$pos = strrpos($version, '-');
	return $pos === false ? [$epoch, $version, ''] : [$epoch, substr($version, 0, $pos), substr($version, $pos + 1)];
}

/** Compares Debian versions like dpkg --compare-versions. */
// php.net version in Debian form: 8.6.0RC4 => 8.6.0~rc4, so it sorts before 8.6.0.
function upstream_to_deb(string $version): string
{
	return preg_replace_callback(
		'/(alpha|beta|RC)(\d+)$/i',
		static fn ($m) => '~' . strtolower($m[1]) . $m[2],
		$version
	);
}

function deb_version_compare(string $a, string $b): int
{
	[$ea, $ua, $ra] = deb_split($a);
	[$eb, $ub, $rb] = deb_split($b);
	return ($ea <=> $eb) ?: (deb_verrevcmp($ua, $ub) <=> 0) ?: (deb_verrevcmp($ra, $rb) <=> 0);
}

/**
 * Distro-independent part of a version, comparable across distros and repos.
 * Drops the epoch and everything from the first distro/build marker on:
 *   1:7.4.33-31+ubuntu24.04.1+deb.sury.org+1           -> 7.4.33-31
 *   1:7.4.33-31+0~20261021.121+debian13~1.gbpe9c428    -> 7.4.33-31
 *   5.22+0~20260903.42+debian11~1.gbpf43e56 (native)  -> 5.22
 *   2.3.3-13+deb12u1, 1.0-1~bpo12+1                    -> 2.3.3-13, 1.0-1
 */
function base_version(string $version): string
{
	$version = preg_replace('/^\d+:/', '', $version);
	return preg_replace(
		'/(?:\+(?:0~\d|debian\d|ubuntu\d|deb\d|deb\.sury\.org)|~(?:bpo|deb|ubuntu)\d).*$/',
		'',
		$version
	);
}

function max_version(array $versions): ?string
{
	$max = null;
	foreach ($versions as $v) {
		if ($v !== null && ($max === null || deb_version_compare($v, $max) > 0)) {
			$max = $v;
		}
	}
	return $max;
}

/** "8.3.1 on bookworm, trixie; 8.3.0 on jammy" from version => [codename, ...]. */
function versions_on(array $byVersion): string
{
	$parts = [];
	foreach ($byVersion as $version => $codenames) {
		$parts[] = "$version on " . implode(', ', $codenames);
	}
	return implode('; ', $parts);
}

/** Cell states, most severe first. */
const STATE_RANK = ['missing' => 5, 'stale' => 4, 'upstream' => 3, 'behind' => 2, 'ok' => 1, 'absent' => 0];

function worse(string $a, string $b): string
{
	return STATE_RANK[$a] >= STATE_RANK[$b] ? $a : $b;
}

function is_supported(string $codename, array $config): bool
{
	[$family, $version] = $config['distros'][$codename];
	return in_array($version, $config['supported'][$family] ?? [], true);
}

function distro_label(string $codename, array $config): string
{
	[$family, $version] = $config['distros'][$codename];
	return "$family $version ($codename)";
}

/** Launchpad build states that mean "not done yet". */
const BUILD_ACTIVE = ['Needs building', 'Currently building', 'Gathering build output', 'Uploading build'];

// ---------------------------------------------------------------------------
// Which distros to scan

foreach ($config['supported'] as $family => $versions) {
	foreach ($versions as $version) {
		if (!in_array([$family, $version], $config['distros'], true)) {
			fail("Supported release $family $version has no codename in 'distros'");
		}
	}
}

// Supported releases with codenames, and whether any older ones are known.
$supportedLabels = []; // family => ["12 (bookworm)", ...]
$hasOlderReleases = false;
foreach ($config['supported'] as $family => $versions) {
	usort($versions, 'version_compare');
	$codenames = [];
	foreach ($config['distros'] as $codename => [$f, $version]) {
		if ($f === $family) {
			$codenames[$version] = $codename;
			$hasOlderReleases = $hasOlderReleases || ($versions && version_compare($version, $versions[0], '<'));
		}
	}
	$supportedLabels[$family] = array_map(static fn ($v) => "$v ($codenames[$v])", $versions);
}

$scan = []; // repoKey => [codename, ...]
foreach ($config['repos'] as $repoKey => $repo) {
	foreach ($config['distros'] as $codename => [$family]) {
		if (in_array($family, $repo['families'], true) && ($includeUnsupported || is_supported($codename, $config))) {
			$scan[$repoKey][] = $codename;
		}
	}
}

// ---------------------------------------------------------------------------
// Fetch Release files, then Packages and Sources files

$distUrl = static fn (string $repoKey, string $codename): string
	=> rtrim($config['repos'][$repoKey]['url'], '/') . "/dists/$codename";

$urls = [];
foreach ($scan as $repoKey => $codenames) {
	foreach ($codenames as $codename) {
		$urls[] = $distUrl($repoKey, $codename) . '/Release';
	}
}
info('Fetching ' . count($urls) . ' Release files...');
$releases = fetch_all($urls, $config);

$dists = []; // repoKey => codename => ['published', 'date', 'archs', 'failed']
$indexUrls = [];
$indexSha256 = [];
foreach ($scan as $repoKey => $codenames) {
	$component = $config['repos'][$repoKey]['component'];
	foreach ($codenames as $codename) {
		$body = $releases[$distUrl($repoKey, $codename) . '/Release'] ?? null;
		if ($body === null) {
			// Releases past the repo's last_release are expected to be missing; leave them out entirely.
			$lastRelease = $config['repos'][$repoKey]['last_release'] ?? null;
			if ($lastRelease === null || version_compare($config['distros'][$codename][1], $lastRelease, '<=')) {
				$dists[$repoKey][$codename] = ['published' => false, 'date' => null, 'archs' => [], 'failed' => []];
			}
			continue;
		}
		$fields = parse_release($body);
		$archs = preg_split('/\s+/', trim($fields['Architectures'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
		$archs = array_diff(
			$archs,
			['all'],
			$config['exclude_architectures'],
			$config['exclude_codename_architectures'][$codename] ?? []
		);
		$archs = array_values($archs);
		if ($config['architectures'] !== null) {
			$archs = array_values(array_intersect($archs, $config['architectures']));
		}
		$dists[$repoKey][$codename] = [
			'published' => true,
			'date'      => $fields['Date'] ?? null,
			'archs'     => $archs,
			'failed'    => [],
		];
		$hashes = release_sha256($body);
		$paths = array_map(static fn ($arch) => "$component/binary-$arch/Packages.gz", $archs);
		$paths[] = "$component/source/Sources.gz";
		foreach ($paths as $path) {
			$url = $distUrl($repoKey, $codename) . "/$path";
			$indexUrls[] = $url;
			if (isset($hashes[$path])) {
				$indexSha256[$url] = $hashes[$path];
			}
		}
	}
}

info('Fetching ' . count($indexUrls) . ' Packages/Sources files...');
$indexFiles = fetch_all($indexUrls, $config, [], $indexSha256);

$pkgs = [];     // repo => codename => arch => [binary => version]
$sources = [];  // repo => codename => [source => [...]]
$sourceOf = []; // binary => source
$archAll = [];  // binary => true for Architecture: all
foreach ($dists as $repoKey => $byCodename) {
	$component = $config['repos'][$repoKey]['component'];
	foreach ($byCodename as $codename => $dist) {
		if (!$dist['published']) {
			continue;
		}
		foreach ($dist['archs'] as $arch) {
			$gz = $indexFiles[$distUrl($repoKey, $codename) . "/$component/binary-$arch/Packages.gz"] ?? null;
			$text = $gz === null ? false : @gzdecode($gz);
			if ($text === false) {
				$dists[$repoKey][$codename]['failed'][] = $arch;
			}
			$pkgs[$repoKey][$codename][$arch] = $text === false ? [] : parse_packages($text, $sourceOf, $archAll);
		}
		$gz = $indexFiles[$distUrl($repoKey, $codename) . "/$component/source/Sources.gz"] ?? null;
		$text = $gz === null ? false : @gzdecode($gz);
		$sources[$repoKey][$codename] = $text === false ? [] : parse_sources($text);
	}
}

// Architectures that are listed in Release but only carry arch:all packages.
foreach ($pkgs as $repoKey => $byCodename) {
	foreach ($byCodename as $codename => $byArch) {
		$dists[$repoKey][$codename]['unbuilt'] = [];
		foreach ($byArch as $arch => $list) {
			if ($list && !array_diff_key($list, $archAll)) {
				$dists[$repoKey][$codename]['unbuilt'][] = $arch;
			}
		}
	}
}

// PHP branches = source packages named phpX.Y.
$branches = [];
foreach ($sourceOf as $source) {
	if (preg_match('/^php(\d+\.\d+)$/', $source, $m)) {
		$branches[$m[1]] = true;
	}
}
$branches = array_map('strval', $config['php_versions'] ?? array_keys($branches));
usort($branches, static fn ($a, $b) => version_compare($b, $a));

$sapiNames = static fn (string $branch): array
	=> array_map(static fn ($p) => sprintf($p, $branch), $config['sapi_packages']);

$sapiSet = [];
foreach ($branches as $branch) {
	foreach ($sapiNames($branch) as $name) {
		$sapiSet[$name] = $branch;
	}
}

// Which branch a non-SAPI package belongs to (php8.3-redis, php8.3-intl, ...).
$branchOf = static function (string $name) use ($sourceOf): ?string {
	if (
		preg_match('/^php(\d+\.\d+)$/', $sourceOf[$name] ?? '', $m)
		|| preg_match('/php(\d+\.\d+)(?:-|$)/', $name, $m)
	) {
		return $m[1];
	}
	return null;
};

// ---------------------------------------------------------------------------
// Changelogs (for the phpX.Y source packages)

$changelogs = []; // "repo|codename|branch" => ['version', 'text', 'url']
if ($config['changelog_entries'] > 0) {
	$lpQueries = [];
	$tarballs = [];
	foreach ($sources as $repoKey => $byCodename) {
		$cl = $config['repos'][$repoKey]['changelog'] ?? null;
		foreach ($byCodename as $codename => $srcs) {
			foreach ($branches as $branch) {
				$src = $srcs["php$branch"] ?? null;
				if ($src === null || $cl === null) {
					continue;
				}
				$key = "$repoKey|$codename|$branch";
				$changelogs[$key] = ['version' => $src['version'], 'text' => null, 'url' => null];
				if ($cl['type'] === 'launchpad') {
					$lpQueries[$key] = 'https://api.launchpad.net/devel/' . $cl['archive'] . '?' . http_build_query([
						'ws.op'          => 'getPublishedSources',
						'source_name'    => "php$branch",
						'exact_match'    => 'true',
						'status'         => 'Published',
						'distro_series'  => "https://api.launchpad.net/devel/ubuntu/$codename",
					]);
				} elseif ($cl['type'] === 'pool') {
					foreach ($src['files'] as $file) {
						if (str_ends_with($file, '.debian.tar.xz')) {
							$tarballs[$key] = rtrim($config['repos'][$repoKey]['url'], '/') . '/' . $src['directory']
								. '/' . rawurlencode($file);
						}
					}
					if ($src['vcs'] !== null) {
						$changelogs[$key]['url'] = str_replace('/-/tree/', '/-/blob/', $src['vcs'])
							. '/debian/changelog';
					}
				}
			}
		}
	}

	info('Fetching ' . (count($lpQueries) + count($tarballs)) . ' changelog sources...');
	// The publication of a given source version doesn't change.
	$lpResults = fetch_all(
		$lpQueries,
		$config,
		array_map(static fn ($key) => $changelogs[$key]['version'], array_flip($lpQueries)),
		[],
		'php-build-matrix/1.0'
	);
	$lpChangelogUrls = [];
	foreach ($lpQueries as $key => $url) {
		$json = json_decode((string) ($lpResults[$url] ?? ''), true);
		foreach ($json['entries'] ?? [] as $entry) {
			if ($entry['source_package_version'] === $changelogs[$key]['version']) {
				$web = str_replace('https://api.launchpad.net/devel/', 'https://launchpad.net/', $entry['self_link']);
				$lpChangelogUrls[$key] = $web . '/+files/changelog';
				$changelogs[$key]['url'] = $lpChangelogUrls[$key];
			}
		}
	}

	$lpTexts = fetch_all($lpChangelogUrls, $config, array_fill_keys($lpChangelogUrls, 'v'), [], 'php-build-matrix/1.0');
	foreach ($lpChangelogUrls as $key => $url) {
		if (isset($lpTexts[$url])) {
			$changelogs[$key]['text'] = changelog_head($lpTexts[$url], $config['changelog_entries']);
		}
	}
	$tarTexts = fetch_all($tarballs, $config, array_fill_keys($tarballs, 'v'));
	foreach ($tarballs as $key => $url) {
		$text = isset($tarTexts[$url])
			? file_from_debian_tar($tarTexts[$url], 'debian/changelog', $config['cache_dir'])
			: null;
		if ($text !== null) {
			$changelogs[$key]['text'] = changelog_head($text, $config['changelog_entries']);
		}
	}
}

// ---------------------------------------------------------------------------
// Builds in progress

// repo => codename => source => arch => ['kind', 'version', 'state', 'since', 'url', 'note']
// kind: launchpad (queued/running there), vcs (branch has an unbuilt commit),
// proxy (another repo is building a newer version).
$building = [];
$buildPages = []; // url => repoKey
foreach ($config['repos'] as $repoKey => $repo) {
	if (($repo['builds']['type'] ?? null) === 'launchpad' && isset($scan[$repoKey])) {
		foreach (BUILD_ACTIVE as $state) {
			$buildPages['https://api.launchpad.net/devel/' . $repo['builds']['archive'] . '?' . http_build_query([
				'ws.op'       => 'getBuildRecords',
				'build_state' => $state,
				'ws.size'     => 300,
			])] = $repoKey;
		}
	}
}
if ($buildPages) {
	info('Fetching builds in progress...');
}
while ($buildPages) {
	$results = fetch_all(array_keys($buildPages), $config, [], [], 'php-build-matrix/1.0');
	$next = [];
	foreach ($buildPages as $url => $repoKey) {
		$json = json_decode((string) ($results[$url] ?? ''), true);
		foreach ($json['entries'] ?? [] as $b) {
			$codename = basename((string) $b['distro_series_link']);
			$source = $b['source_package_name'];
			$prev = $building[$repoKey][$codename][$source][$b['arch_tag']] ?? null;
			if (
				isset($dists[$repoKey][$codename])
				&& ($prev === null || deb_version_compare($b['source_package_version'], $prev['version']) > 0)
			) {
				$since = $b['date_started'] ?? $b['datecreated'] ?? null;
				$building[$repoKey][$codename][$source][$b['arch_tag']] = [
					'kind'    => 'launchpad',
					'version' => $b['source_package_version'],
					'state'   => $b['buildstate'],
					'since'   => $since,
					'url'     => $b['web_link'],
					'note'    => "Building {$b['source_package_version']} ({$b['buildstate']}"
						. ($since ? ' since ' . gmdate('Y-m-d H:i', strtotime($since)) . ' UTC' : '') . ')',
				];
			}
		}
		if (isset($json['next_collection_link'])) {
			$next[$json['next_collection_link']] = $repoKey;
		}
	}
	$buildPages = $next;
}

$buildArchs = static fn (string $repoKey, string $codename): array
	=> array_values(array_diff($dists[$repoKey][$codename]['archs'], $dists[$repoKey][$codename]['unbuilt'] ?? []));

// Jenkins (git-buildpackage) versions end in the commit they were built from,
// e.g. ...+debian12~1.gbp7636b9. When the GitLab branch named in Vcs-Browser
// has moved past it, a build is pending (or failed).
$branchApi = []; // api url => [[repoKey, codename, source, built commit, branch, web url], ...]
foreach ($config['repos'] as $repoKey => $repo) {
	if (($repo['builds']['type'] ?? null) !== 'vcs') {
		continue;
	}
	foreach ($sources[$repoKey] ?? [] as $codename => $srcs) {
		foreach ($srcs as $source => $src) {
			if (
				preg_match('#^(https://[^/]+)/(.+?)/-/tree/(.+)$#', (string) $src['vcs'], $m)
				&& preg_match('/\.gbp([0-9a-f]+)$/', $src['version'], $g)
			) {
				$api = "$m[1]/api/v4/projects/" . rawurlencode($m[2]) . '/repository/branches/' . rawurlencode($m[3]);
				$branchApi[$api][] = [$repoKey, $codename, $source, $g[1], $m[3], $src['vcs']];
			}
		}
	}
}
$vcsChecked = []; // "repo|codename|source" => true
$unbuiltCommits = []; // [repoKey, codename, source, branch, commit, date]
if ($branchApi) {
	info('Fetching ' . count($branchApi) . ' packaging branch heads...');
	$heads = fetch_all(array_keys($branchApi), $config, [], [], 'php-build-matrix/1.0');
	foreach ($branchApi as $api => $uses) {
		$commit = json_decode((string) ($heads[$api] ?? ''), true)['commit'] ?? null;
		if (!isset($commit['id'], $commit['committed_date'])) {
			continue;
		}
		foreach ($uses as [$repoKey, $codename, $source, $built, $branch, $web]) {
			$vcsChecked["$repoKey|$codename|$source"] = true;
			if (str_starts_with($commit['id'], $built)) {
				continue;
			}
			$short = substr($commit['id'], 0, 8);
			$date = gmdate('Y-m-d H:i', strtotime($commit['committed_date']));
			$unbuiltCommits[] = [$repoKey, $codename, $source, $branch, $short, $commit['committed_date']];
			foreach ($buildArchs($repoKey, $codename) as $arch) {
				$building[$repoKey][$codename][$source][$arch] = [
					'kind'    => 'vcs',
					'version' => "$branch @ $short",
					'state'   => 'Not built yet',
					'since'   => $commit['committed_date'],
					'url'     => $commit['web_url'] ?? $web,
					'note'    => "Not built yet: $branch is at $short ($date UTC, " . ($commit['title'] ?? '')
						. "), published build is from $built",
				];
			}
		}
	}
}

// A repo without build status of its own: when another repo is building a
// newer version of a source, a build here is probably underway too.
foreach ($config['repos'] as $repoKey => $repo) {
	$proxy = $repo['builds']['proxy'] ?? null;
	$newest = []; // source => newest build of it in the proxy repo
	foreach ($building[$proxy] ?? [] as $bySource) {
		foreach ($bySource as $source => $byArch) {
			foreach ($byArch as $b) {
				if ($b['kind'] !== 'launchpad') {
					continue;
				}
				$newestVersion = isset($newest[$source]) ? base_version($newest[$source]['version']) : null;
				if ($newestVersion === null || deb_version_compare(base_version($b['version']), $newestVersion) > 0) {
					$newest[$source] = $b;
				}
			}
		}
	}
	foreach ($newest as $source => $b) {
		$newer = base_version($b['version']);
		foreach ($sources[$repoKey] ?? [] as $codename => $srcs) {
			if (
				!isset($srcs[$source])
				|| isset($vcsChecked["$repoKey|$codename|$source"])
				|| isset($building[$repoKey][$codename][$source])
				|| deb_version_compare(base_version($srcs[$source]['version']), $newer) >= 0
			) {
				continue;
			}
			foreach ($buildArchs($repoKey, $codename) as $arch) {
				$building[$repoKey][$codename][$source][$arch] = [
					'kind'    => 'proxy',
					'version' => $newer,
					'state'   => 'Probably building',
					'since'   => $b['since'],
					'url'     => $b['url'],
					'note'    => "Probably building: {$config['repos'][$proxy]['label']} is building $newer",
				];
			}
		}
	}
}

// ---------------------------------------------------------------------------
// Upstream releases (php.net)

$upstream = []; // branch => ['latest', 'state', 'date' (timestamp|null), 'security_end']
if ($config['upstream'] !== null) {
	info('Fetching php.net release data...');
	$upstreamData = fetch_all(array_values($config['upstream']), $config, [], [], 'php-build-matrix/1.0');
	foreach (json_decode((string) ($upstreamData[$config['upstream']['branches']] ?? ''), true) ?: [] as $b) {
		if (isset($b['branch'], $b['latest'])) {
			$upstream[$b['branch']] = [
				'latest'       => $b['latest'],
				'state'        => $b['state'] ?? null,
				'date'         => null,
				'security_end' => $b['security_support_end'] ?? null,
			];
		}
	}
	// active.php is cached for minutes rather than days, so it wins for supported branches.
	foreach (json_decode((string) ($upstreamData[$config['upstream']['active']] ?? ''), true) ?: [] as $byBranch) {
		foreach ((array) $byBranch as $branch => $r) {
			if (
				isset($r['version'])
				&& (!isset($upstream[$branch]) || version_compare($r['version'], $upstream[$branch]['latest'], '>='))
			) {
				$upstream[$branch]['latest'] = $r['version'];
				$upstream[$branch]['date'] = isset($r['date']) ? (strtotime($r['date'] . ' UTC') ?: null) : null;
			}
		}
	}
	// Pre-releases (e.g. 8.6.0RC4) of branches without a GA release yet.
	foreach (json_decode((string) ($upstreamData[$config['upstream']['prerelease'] ?? ''] ?? ''), true) ?: [] as $r) {
		$version = $r['release']['version'] ?? null;
		if (
			($r['release']['enabled'] ?? true) && $version !== null && preg_match('/^(\d+\.\d+)\./', $version, $m)
			&& (!isset($upstream[$m[1]]['latest']) || version_compare($version, $upstream[$m[1]]['latest'], '>'))
		) {
			$upstream[$m[1]]['latest'] = $version;
			$upstream[$m[1]]['date'] = isset($r['release']['date'])
				? (strtotime($r['release']['date'] . ' UTC') ?: null)
				: null;
		}
	}
	if (!$upstream) {
		info('Warning: no usable php.net release data, upstream check skipped');
	}
}

// ---------------------------------------------------------------------------
// Upstream releases of other source packages (debian/watch, like uscan)

$watch = []; // "repo|codename|source" => ['packaged', 'latest', 'url', 'error']
if ($config['watch_ttl'] !== null) {
	$tarballs = []; // key => .debian.tar.xz URL
	foreach ($sources as $repoKey => $byCodename) {
		foreach ($byCodename as $codename => $srcs) {
			if (!is_supported($codename, $config)) {
				continue;
			}
			foreach ($srcs as $source => $src) {
				// phpX.Y is compared with php.net; native packages have no upstream.
				if (preg_match('/^php\d+\.\d+$/', $source)) {
					continue;
				}
				foreach ($src['files'] as $file) {
					if (str_ends_with($file, '.debian.tar.xz')) {
						$tarballs["$repoKey|$codename|$source"] = rtrim($config['repos'][$repoKey]['url'], '/') . '/'
							. $src['directory'] . '/' . rawurlencode($file);
					}
				}
			}
		}
	}

	// The extracted debian/watch is cached, so a tarball is fetched once per version.
	$watchTexts = []; // key => debian/watch, null when there is none
	$newTarballs = [];
	foreach ($tarballs as $key => $url) {
		$file = cache_path("$url#debian/watch", $config);
		if (is_file($file)) {
			touch($file);
			$text = file_get_contents($file);
			$watchTexts[$key] = str_starts_with($text, "MISSING\n") ? null : $text;
		} else {
			$newTarballs[$key] = $url;
		}
	}
	info(
		'Fetching ' . count($newTarballs) . ' new source packages for debian/watch ('
			. count($watchTexts) . ' known)...'
	);
	$tarBodies = fetch_all($newTarballs, $config, array_fill_keys($newTarballs, 'v'));
	foreach ($newTarballs as $key => $url) {
		if (!isset($tarBodies[$url])) {
			$watch[$key] = [
				'packaged' => null,
				'latest'   => null,
				'url'      => null,
				'error'    => 'source package not available',
			];
			continue;
		}
		$text = file_from_debian_tar($tarBodies[$url], 'debian/watch', $config['cache_dir']);
		file_put_contents(cache_path("$url#debian/watch", $config), $text ?? "MISSING\n");
		$watchTexts[$key] = $text;
	}
	unset($tarBodies);

	// PECL package pages carry no Last-Modified; their REST release list does.
	$peclName = static fn (string $url): ?string
		=> preg_match('#^https?://pecl\.php\.net/package/([^/?\#]+)/?$#i', $url, $m) ? $m[1] : null;
	$specs = []; // key => watch lines, each with the URL to fetch
	$pageUrls = [];
	foreach ($watchTexts as $key => $text) {
		[$repoKey, $codename, $source] = explode('|', $key);
		$w = [
			'packaged' => deb_split($sources[$repoKey][$codename][$source]['version'])[1],
			'latest'   => null,
			'url'      => null,
			'error'    => null,
		];
		$lines = $text === null ? [] : parse_watch($text);
		if ($text === null) {
			$w['error'] = 'no debian/watch';
		} elseif (!$lines) {
			$w['error'] = 'empty debian/watch';
		}
		foreach ($lines as $i => $line) {
			$scheme = strtolower((string) parse_url($line['url'], PHP_URL_SCHEME));
			$mode = $line['opts']['mode'] ?? 'LWP';
			$w['error'] = match (true) {
				$mode !== 'LWP'                                => "mode=$mode not supported",
				!in_array($scheme, ['http', 'https'], true)    => "$scheme:// not supported",
				str_contains($line['url'], '(')                => 'regex in the URL not supported',
				default                                        => null,
			};
			if ($w['error'] !== null) {
				break;
			}
			$name = $peclName($line['url']);
			$lines[$i]['pecl'] = $name;
			$lines[$i]['fetch'] = $name !== null
				? 'https://pecl.php.net/rest/r/' . strtolower($name) . '/allreleases.xml'
				: $line['url'];
			$pageUrls[] = $lines[$i]['fetch'];
		}
		if ($w['error'] === null && isset($lines[0]['opts']['dversionmangle'])) {
			$w['packaged'] = watch_mangle($lines[0]['opts']['dversionmangle'], $w['packaged']);
			if ($w['packaged'] === null) {
				$w['error'] = 'dversionmangle not supported';
			}
		}
		$w['url'] = $lines[0]['url'] ?? null;
		$watch[$key] = $w;
		if ($w['error'] === null) {
			$specs[$key] = $lines;
		}
	}

	info('Fetching ' . count(array_unique($pageUrls)) . ' upstream pages named in debian/watch...');
	$pages = fetch_all($pageUrls, $config, [], [], 'php-build-matrix/1.0', $config['watch_ttl']);
	foreach ($specs as $key => $lines) {
		$source = explode('|', $key)[2];
		$latest = null;
		$latestPre = null;
		$error = null;
		// Pre-releases only count when asked for, when one is packaged, or
		// when nothing else matches (a series that never left beta).
		$prereleases = $config['watch_prereleases'] || str_contains($watch[$key]['packaged'], '~');
		foreach ($lines as $line) {
			$body = $pages[$line['fetch']] ?? null;
			if ($body === null) {
				$error = "could not fetch {$line['fetch']}";
				continue;
			}
			$links = null;
			$unstable = []; // PECL version => true unless stable
			if ($line['pecl'] !== null) {
				// Tarballs are named in lower case (apcu-5.1.28.tgz for APCu), the pattern may use either.
				preg_match_all('#<v>([^<]+)</v><s>([^<]+)</s>#', $body, $m, PREG_SET_ORDER);
				$links = [];
				foreach ($m as [, $v, $stability]) {
					array_push($links, "/get/{$line['pecl']}-$v.tgz", '/get/' . strtolower($line['pecl']) . "-$v.tgz");
					$unstable[$v] = $stability !== 'stable';
				}
			}
			$pattern = strtr($line['pattern'], [
				'@PACKAGE@'       => preg_quote($source),
				'@ANY_VERSION@'   => '[-_]?(\d[\-+\.:\~\da-zA-Z]*)',
				'@ARCHIVE_EXT@'   => '(?i:\.(?:tar\.xz|tar\.bz2|tar\.gz|tar\.zstd?|zip|tgz|tbz|txz))',
				'@SIGNATURE_EXT@' => '(?i:\.(?:tar\.xz|tar\.bz2|tar\.gz|tar\.zstd?|zip|tgz|tbz|txz))'
					. '(?:\.(?:asc|pgp|gpg|sig|sign))',
			]);
			$plain = ($line['opts']['searchmode'] ?? 'html') === 'plain';
			$found = watch_versions($body, $line['url'], $pattern, $plain, $links);
			if (!$found) {
				$error = 'no release matches the debian/watch pattern';
			}
			foreach ($found as $v) {
				$pre = $unstable[$v] ?? false;
				if (isset($line['opts']['uversionmangle'])) {
					$v = watch_mangle($line['opts']['uversionmangle'], $v);
					if ($v === null) {
						$error = 'uversionmangle not supported';
						break;
					}
				}
				if (!$prereleases && ($pre || str_contains($v, '~'))) {
					$latestPre = $latestPre === null || deb_verrevcmp($v, $latestPre) > 0 ? $v : $latestPre;
				} elseif ($latest === null || deb_verrevcmp($v, $latest) > 0) {
					$latest = $v;
				}
			}
		}
		$latest ??= $latestPre;
		$watch[$key]['latest'] = $latest;
		$watch[$key]['error'] = $latest === null ? $error : null;
	}
}

// ---------------------------------------------------------------------------
// Analysis

// Newest distro-independent version of every package across supported
// distros of all repos; SAPI packages are also tracked per branch.
$latestBase = [];
$latestBranch = [];
foreach ($pkgs as $repoKey => $byCodename) {
	foreach ($byCodename as $codename => $byArch) {
		if (!is_supported($codename, $config)) {
			continue;
		}
		foreach ($byArch as $versions) {
			foreach ($versions as $name => $v) {
				$b = base_version($v);
				$latestBase[$name] = max_version([$latestBase[$name] ?? null, $b]);
				if (isset($sapiSet[$name])) {
					$latestBranch[$sapiSet[$name]] = max_version([$latestBranch[$sapiSet[$name]] ?? null, $b]);
				}
			}
		}
	}
}

$issues = ['main' => [], 'other' => [], 'build' => []];
// $tags (repo / distro / arch / branch) drive the filters in the report.
// $links (label => url) are appended to the text.
$addIssue = static function (
	string $group,
	string $level,
	string $text,
	array $tags,
	array $items = [],
	array $links = []
) use (&$issues): void {
	$issues[$group][] = ['level' => $level, 'text' => $text, 'tags' => $tags, 'items' => $items, 'links' => $links];
};

// Todo list, one row per thing to do for a source package (like the Debian
// Maintainer Dashboard). $source '' = the whole distribution.
const TODO_TYPES = [
	'unpublished'    => 'distribution not published',
	'missing build'  => 'not built for an architecture, or binaries older than the source',
	'outdated build' => 'older on some architectures',
	'new upstream'   => 'newer upstream release (php.net or debian/watch)',
	'vcs'            => 'packaging commit not built',
	'behind'         => 'newer version available for other distros',
	'repo sync'      => 'available in the other repository only',
	'uscan error'    => 'upstream releases could not be checked',
];
$todos = [];
// Binary package list for a todo row: without -dbgsym (unless that's all), at most 6 names.
$todoNames = static function (array $names): string {
	$plain = array_values(array_filter($names, static fn ($n) => !str_ends_with($n, '-dbgsym'))) ?: $names;
	return implode(', ', array_slice($plain, 0, 6)) . (count($plain) > 6 ? ' … (+' . (count($plain) - 6) . ')' : '');
};
$addTodo = static function (
	string $type,
	string $repoKey,
	string $source,
	string $text,
	array $tags = [],
	array $links = []
) use (&$todos): void {
	$todos[] = [
		'type'   => $type,
		'repo'   => $repoKey,
		'source' => $source,
		'text'   => $text,
		'tags'   => $tags + ['repo' => $repoKey],
		'links'  => $links,
	];
};

foreach ($building as $repoKey => $byCodename) {
	$repoLabel = $config['repos'][$repoKey]['label'];
	foreach ($byCodename as $codename => $bySource) {
		foreach ($bySource as $source => $byArch) {
			ksort($byArch);
			$versions = array_unique(array_column($byArch, 'version'));
			$byLink = []; // "url state" => [arch, ...]
			foreach ($byArch as $arch => $b) {
				$byLink[$b['url'] . ' ' . $b['state']][] = $arch;
			}
			$links = [];
			foreach ($byLink as $key => $archs) {
				[$url, $state] = explode(' ', $key, 2);
				$links[implode(', ', $archs) . ': ' . strtolower($state)] = $url;
			}
			$addIssue(
				'build',
				'build',
				distro_label($codename, $config) . " · $repoLabel: $source " . implode(', ', $versions),
				[
					'repo'   => $repoKey,
					'distro' => $codename,
					'arch'   => array_keys($byArch),
					'branch' => array_filter([$branchOf($source)]),
				],
				[],
				$links,
			);
		}
	}
}

// Main matrix: cells[repo][codename][branch][arch]
$cells = [];
$upstreamLag = []; // repo => branch => codename => packaged upstream version
foreach ($dists as $repoKey => $byCodename) {
	$repoLabel = $config['repos'][$repoKey]['label'];
	foreach ($byCodename as $codename => $dist) {
		$where = distro_label($codename, $config) . " · $repoLabel";
		$supported = is_supported($codename, $config);

		if (!$dist['published']) {
			if ($supported) {
				$addIssue(
					'main',
					'error',
					distro_label($codename, $config) . ": no packages in $repoLabel (distribution not published)",
					['repo' => $repoKey, 'distro' => $codename]
				);
				$addTodo('unpublished', $repoKey, '', 'distribution not published', ['distro' => $codename]);
			}
			continue;
		}
		foreach ($dist['failed'] as $arch) {
			$addIssue(
				'main',
				'error',
				"$where: could not fetch/decode Packages for $arch",
				['repo' => $repoKey, 'distro' => $codename, 'arch' => [$arch]]
			);
		}
		if ($supported && $dist['unbuilt']) {
			$addIssue(
				'main',
				'warn',
				"$where: " . implode(', ', $dist['unbuilt'])
					. ' listed in Release, but nothing is built for it (only arch:all packages)',
				['repo' => $repoKey, 'distro' => $codename, 'arch' => $dist['unbuilt']]
			);
			$addTodo(
				'missing build',
				$repoKey,
				'',
				'nothing built for ' . implode(', ', $dist['unbuilt']) . ' (only arch:all packages)',
				['distro' => $codename, 'arch' => $dist['unbuilt']]
			);
		}
		$notBuilt = []; // arch => [branch, ...]

		foreach ($branches as $branch) {
			$names = $sapiNames($branch);
			$present = [];
			$union = [];
			foreach ($dist['archs'] as $arch) {
				$present[$arch] = array_intersect_key($pkgs[$repoKey][$codename][$arch], array_flip($names));
				$union += $present[$arch];
			}
			if (!$union) {
				foreach ($dist['archs'] as $arch) {
					$cells[$repoKey][$codename][$branch][$arch] = [
						'version' => null,
						'state'   => 'absent',
						'notes'   => [],
					];
				}
				continue;
			}

			$distroMax = max_version(array_merge(...array_values(array_map('array_values', $present))));
			$sourceVersion = $sources[$repoKey][$codename]["php$branch"]['version'] ?? null;
			$problems = ['partial' => [], 'stale' => []];

			foreach ($dist['archs'] as $arch) {
				$own = $present[$arch];
				$cell = ['version' => max_version($own), 'state' => 'ok', 'notes' => []];
				if (in_array($arch, $dist['unbuilt'], true)) {
					$cell = ['version' => null, 'state' => 'absent', 'notes' => ["Nothing is built for $arch"]];
				} elseif (!array_diff_key($own, $archAll)) {
					$cell['state'] = 'missing';
					$cell['version'] = null;
					$cell['notes'][] = "Not built for $arch (other architectures have $distroMax)";
					$notBuilt[$arch][] = $branch;
				} else {
					$lacking = array_keys(array_diff_key($union, $own));
					if ($lacking) {
						$cell['state'] = 'missing';
						$cell['notes'][] = 'Missing: ' . implode(', ', $lacking);
						$problems['partial'][$arch] = $lacking;
					}
					$older = array_keys(array_filter($own, static fn ($v) => deb_version_compare($v, $distroMax) < 0));
					if ($older) {
						$cell['state'] = worse($cell['state'], 'stale');
						$cell['notes'][] = "Older than $distroMax: " . implode(', ', $older);
						$problems['stale'][] = $arch;
					}
					if ($supported && deb_version_compare(base_version($cell['version']), $latestBranch[$branch]) < 0) {
						$cell['state'] = worse($cell['state'], 'behind');
						$cell['notes'][] = "{$latestBranch[$branch]} is available for other distros";
					}
					$up = $upstream[$branch] ?? null;
					if (
						$supported && $up !== null
						&& deb_version_compare(deb_split($cell['version'])[1], upstream_to_deb($up['latest'])) < 0
					) {
						$cell['state'] = worse($cell['state'], 'upstream');
						$cell['notes'][] = "php.net released {$up['latest']}"
							. ($up['date'] ? ' on ' . gmdate('Y-m-d', $up['date']) : '');
						$upstreamLag[$repoKey][$branch][$codename] = deb_split($cell['version'])[1];
					}
				}
				$cells[$repoKey][$codename][$branch][$arch] = $cell;
			}

			if (!$supported) {
				continue;
			}
			$inProgress = $building[$repoKey][$codename]["php$branch"] ?? [];
			$buildNote = static fn (array $archs): string
				=> array_intersect_key($inProgress, array_flip($archs)) ? ' · build in progress' : '';
			$prefix = "$where · PHP $branch";
			$tags = ['repo' => $repoKey, 'distro' => $codename, 'branch' => [$branch]];
			foreach ($problems['partial'] as $arch => $lacking) {
				$addIssue(
					'main',
					'error',
					"$prefix: $arch is missing " . implode(', ', $lacking) . $buildNote([$arch]),
					$tags + ['arch' => [$arch]]
				);
				$addTodo(
					'missing build',
					$repoKey,
					"php$branch",
					"$arch is missing " . $todoNames($lacking) . $buildNote([$arch]),
					$tags + ['arch' => [$arch]]
				);
			}
			if ($problems['stale']) {
				$staleArchs = array_values(array_unique($problems['stale']));
				$addIssue(
					'main',
					'error',
					"$prefix: outdated on " . implode(', ', $staleArchs) . " (newest here is $distroMax)"
						. $buildNote($staleArchs),
					$tags + ['arch' => $staleArchs]
				);
				$addTodo(
					'outdated build',
					$repoKey,
					"php$branch",
					'outdated on ' . implode(', ', $staleArchs) . ' (newest here is ' . base_version($distroMax) . ')'
						. $buildNote($staleArchs),
					$tags + ['arch' => $staleArchs]
				);
			}
			if ($sourceVersion !== null && deb_version_compare($distroMax, $sourceVersion) < 0) {
				$addIssue(
					'main',
					'error',
					"$prefix: source $sourceVersion is published, but binaries are only at $distroMax"
						. $buildNote($dist['archs']),
					$tags
				);
				$addTodo(
					'missing build',
					$repoKey,
					"php$branch",
					'source ' . base_version($sourceVersion) . ' is published, but binaries are only at '
						. base_version($distroMax) . $buildNote($dist['archs']),
					$tags
				);
			}
			if (deb_version_compare(base_version($distroMax), $latestBranch[$branch]) < 0) {
				$addIssue(
					'main',
					'warn',
					"$prefix: newest is " . base_version($distroMax)
						. ", but {$latestBranch[$branch]} is available for other distros",
					$tags
				);
				$addTodo(
					'behind',
					$repoKey,
					"php$branch",
					'newest is ' . base_version($distroMax) . ", other distros have {$latestBranch[$branch]}",
					$tags
				);
			}
		}
		if ($supported) {
			foreach ($notBuilt as $arch => $list) {
				$addIssue(
					'main',
					'error',
					"$where: PHP " . implode(', ', $list) . " not built for $arch (available on other architectures)",
					['repo' => $repoKey, 'distro' => $codename, 'arch' => [$arch], 'branch' => $list]
				);
				foreach ($list as $branch) {
					$addTodo(
						'missing build',
						$repoKey,
						"php$branch",
						"not built for $arch (available on other architectures)",
						['distro' => $codename, 'arch' => [$arch], 'branch' => [$branch]]
					);
				}
			}
		}
	}
}

// One issue per repo and branch for a missed php.net release.
foreach ($upstreamLag as $repoKey => $byBranch) {
	foreach ($byBranch as $branch => $byCodename) {
		$up = $upstream[$branch];
		$byVersion = [];
		foreach ($byCodename as $codename => $v) {
			$byVersion[$v][] = $codename;
		}
		$anyBuilding = array_filter(
			array_keys($byCodename),
			static fn ($c) => isset($building[$repoKey][$c]["php$branch"])
		);
		$text = "php.net released {$up['latest']}" . ($up['date'] ? ' on ' . gmdate('Y-m-d', $up['date']) : '')
			. ', packaged is ' . versions_on($byVersion) . ($anyBuilding ? ' · build in progress' : '');
		$addIssue(
			'main',
			'error',
			"{$config['repos'][$repoKey]['label']} · PHP $branch: $text",
			['repo' => $repoKey, 'distro' => array_keys($byCodename), 'branch' => [$branch]]
		);
		$addTodo('new upstream', $repoKey, "php$branch", $text, [
			'distro' => array_keys($byCodename),
			'branch' => [$branch],
		]);
	}
}

foreach ($unbuiltCommits as [$repoKey, $codename, $source, $branch, $short, $date]) {
	if (is_supported($codename, $config) && time() - strtotime($date) > $config['vcs_build_hours'] * 3600) {
		$text = "$branch is at $short (" . gmdate('Y-m-d H:i', strtotime($date))
			. ' UTC), still not built — failed build?';
		$tags = ['distro' => $codename, 'branch' => array_filter([$branchOf($source)])];
		$addIssue(
			'main',
			'warn',
			distro_label($codename, $config) . " · {$config['repos'][$repoKey]['label']} · $source: $text",
			['repo' => $repoKey] + $tags
		);
		$addTodo('vcs', $repoKey, $source, $text, $tags);
	}
}

// Mark cells with a build in progress.
foreach ($building as $repoKey => $byCodename) {
	foreach ($byCodename as $codename => $bySource) {
		foreach ($bySource as $source => $byArch) {
			if (!preg_match('/^php(\d+\.\d+)$/', $source, $m)) {
				continue;
			}
			foreach ($byArch as $arch => $b) {
				if (isset($cells[$repoKey][$codename][$m[1]][$arch])) {
					$cells[$repoKey][$codename][$m[1]][$arch]['building'] = $b;
					$cells[$repoKey][$codename][$m[1]][$arch]['notes'][] = $b['note'];
				}
			}
		}
	}
}

// Same distro in several repos: a branch should be in all of them.
foreach ($config['distros'] as $codename => $_) {
	$inRepos = [];
	foreach ($dists as $repoKey => $byCodename) {
		if (($byCodename[$codename]['published'] ?? false) && is_supported($codename, $config)) {
			$inRepos[] = $repoKey;
		}
	}
	if (count($inRepos) < 2) {
		continue;
	}
	foreach ($branches as $branch) {
		$has = array_filter($inRepos, static function ($repoKey) use ($cells, $codename, $branch) {
			foreach ($cells[$repoKey][$codename][$branch] ?? [] as $cell) {
				if ($cell['state'] !== 'absent') {
					return true;
				}
			}
			return false;
		});
		if ($has && count($has) < count($inRepos)) {
			$labels = static fn ($keys)
				=> implode(', ', array_map(static fn ($k) => $config['repos'][$k]['label'], $keys));
			$addIssue(
				'main',
				'warn',
				distro_label($codename, $config) . " · PHP $branch: in " . $labels($has)
					. ' but not in ' . $labels(array_diff($inRepos, $has)),
				['repo' => $inRepos, 'distro' => $codename, 'branch' => [$branch]]
			);
			foreach (array_diff($inRepos, $has) as $repoKey) {
				$addTodo(
					'repo sync',
					$repoKey,
					"php$branch",
					'available in ' . $labels($has) . ' only',
					['distro' => $codename, 'branch' => [$branch]]
				);
			}
		}
	}
}

// Other packages: one cell per distro. others[repo][group][name][codename]
$others = [];
$otherProblems = []; // "repo|codename|kind|archs" => [name, ...]
$otherBehind = [];   // repo => codename => source => [name, ...]
foreach ($pkgs as $repoKey => $byCodename) {
	$repoLabel = $config['repos'][$repoKey]['label'];
	$names = [];
	foreach ($byCodename as $byArch) {
		foreach ($byArch as $versions) {
			$names += $versions;
		}
	}
	$names = array_keys(array_diff_key($names, $sapiSet));
	sort($names, SORT_NATURAL);

	foreach ($names as $name) {
		$group = $branchOf($name) ?? 'other';
		if ($group !== 'other' && !in_array($group, $branches, true)) {
			continue;
		}
		foreach ($byCodename as $codename => $byArch) {
			$versions = [];
			foreach ($byArch as $arch => $list) {
				if (!in_array($arch, $dists[$repoKey][$codename]['unbuilt'], true)) {
					$versions[$arch] = $list[$name] ?? null;
				}
			}
			$max = max_version($versions);
			$cell = ['version' => $max, 'state' => 'absent', 'notes' => []];
			$inProgress = array_intersect_key(
				$building[$repoKey][$codename][$sourceOf[$name] ?? $name] ?? [],
				$versions
			);
			// A build doesn't tell which binaries it will add, so only mark existing ones.
			if ($inProgress && $max !== null) {
				$cell['building'] = reset($inProgress);
				$cell['notes'][] = $cell['building']['note'] . ' on ' . implode(', ', array_keys($inProgress));
			}
			if ($max !== null) {
				$cell['state'] = 'ok';
				$missing = array_keys(array_filter($versions, static fn ($v) => $v === null));
				$isStale = static fn ($v) => $v !== null && deb_version_compare($v, $max) < 0;
				$stale = array_keys(array_filter($versions, $isStale));
				if ($missing) {
					$cell['state'] = 'missing';
					$cell['notes'][] = 'Not built for ' . implode(', ', $missing);
				}
				if ($stale) {
					$cell['state'] = worse($cell['state'], 'stale');
					$cell['notes'][] = 'Older on ' . implode(', ', $stale);
				}
				if (is_supported($codename, $config)) {
					if ($missing) {
						$otherProblems["$repoKey|$codename|not built for|" . implode(', ', $missing)][] = $name;
					}
					if ($stale) {
						$otherProblems["$repoKey|$codename|older on|" . implode(', ', $stale)][] = $name;
					}
					if (deb_version_compare(base_version($max), $latestBase[$name]) < 0) {
						$cell['state'] = worse($cell['state'], 'behind');
						$cell['notes'][] = "{$latestBase[$name]} is available for other distros";
						$otherBehind[$repoKey][$codename][$sourceOf[$name] ?? $name][] = $name;
					}
					$w = $watch["$repoKey|$codename|" . ($sourceOf[$name] ?? $name)] ?? null;
					if ($w !== null && $w['latest'] !== null && deb_verrevcmp($w['packaged'], $w['latest']) < 0) {
						$cell['state'] = worse($cell['state'], 'upstream');
						$cell['notes'][] = "Upstream has {$w['latest']} (debian/watch)";
					}
				}
			}
			$others[$repoKey][$group][$name][$codename] = $cell;
		}
	}
}

foreach ($otherProblems as $key => $names) {
	[$repoKey, $codename, $kind, $archs] = explode('|', $key);
	$branchTags = array_values(array_unique(array_filter(array_map($branchOf, $names))));
	$addIssue(
		'other',
		'error',
		distro_label($codename, $config) . " · {$config['repos'][$repoKey]['label']}: "
			. count($names) . ' package' . (count($names) === 1 ? '' : 's') . " $kind $archs",
		['repo' => $repoKey, 'distro' => $codename, 'arch' => explode(', ', $archs), 'branch' => $branchTags],
		$names,
	);
	$bySource = [];
	foreach ($names as $name) {
		$bySource[$sourceOf[$name] ?? $name][] = $name;
	}
	foreach ($bySource as $source => $list) {
		$addTodo(
			$kind === 'not built for' ? 'missing build' : 'outdated build',
			$repoKey,
			$source,
			"$kind $archs: " . $todoNames($list),
			[
				'distro' => $codename,
				'arch'   => explode(', ', $archs),
				'branch' => array_values(array_unique(array_filter(array_map($branchOf, $list)))),
			]
		);
	}
}
foreach ($otherBehind as $repoKey => $byCodename) {
	foreach ($byCodename as $codename => $bySource) {
		foreach ($bySource as $source => $names) {
			$addTodo(
				'behind',
				$repoKey,
				$source,
				count($names) . ' binar' . (count($names) === 1 ? 'y' : 'ies')
					. " older than on other distros, e.g. $names[0] ({$latestBase[$names[0]]} elsewhere)",
				[
					'distro' => $codename,
					'branch' => array_values(array_unique(array_filter(array_map($branchOf, $names)))),
				]
			);
		}
	}
}

// One issue per repo and source for a newer upstream release, and one per
// repo listing the sources that couldn't be checked.
$watchLag = [];    // repo => source => ['latest', 'url', 'have' => [packaged => [codename, ...]]]
$watchFailed = []; // repo => source => reason
foreach ($watch as $key => $w) {
	[$repoKey, $codename, $source] = explode('|', $key);
	if ($w['error'] !== null) {
		$watchFailed[$repoKey][$source] = $w['error'];
	} elseif ($w['latest'] !== null && deb_verrevcmp($w['packaged'], $w['latest']) < 0) {
		$watchLag[$repoKey][$source]['latest'] = $w['latest'];
		$watchLag[$repoKey][$source]['url'] = $w['url'];
		$watchLag[$repoKey][$source]['have'][$w['packaged']][] = $codename;
	}
}
foreach ($watchLag as $repoKey => $bySource) {
	ksort($bySource, SORT_NATURAL);
	foreach ($bySource as $source => $lag) {
		$have = versions_on($lag['have']);
		$addIssue(
			'other',
			'warn',
			"{$config['repos'][$repoKey]['label']} · $source: upstream has {$lag['latest']}, packaged is $have",
			[
				'repo'   => $repoKey,
				'distro' => array_merge(...array_values($lag['have'])),
				'branch' => array_filter([$branchOf($source)]),
			],
			[],
			['upstream' => $lag['url']]
		);
		$addTodo(
			'new upstream',
			$repoKey,
			$source,
			"upstream has {$lag['latest']}, packaged is $have",
			['distro' => array_merge(...array_values($lag['have']))],
			['upstream' => $lag['url']]
		);
	}
}
foreach ($watchFailed as $repoKey => $bySource) {
	ksort($bySource, SORT_NATURAL);
	$addIssue(
		'other',
		'warn',
		"{$config['repos'][$repoKey]['label']}: " . count($bySource) . ' source' . (count($bySource) === 1 ? '' : 's')
			. ' not checked for upstream releases',
		['repo' => $repoKey],
		array_map(static fn ($s, $r) => "$s ($r)", array_keys($bySource), $bySource)
	);
	foreach ($bySource as $source => $reason) {
		$addTodo('uscan error', $repoKey, $source, $reason);
	}
}

// ---------------------------------------------------------------------------
// Report (see build_view() and the templates at the end of the file)

$html = render_page(build_view($config, [
	'supportedLabels'  => $supportedLabels,
	'hasOlderReleases' => $hasOlderReleases,
	'dists'            => $dists,
	'sources'          => $sources,
	'branches'         => $branches,
	'changelogs'       => $changelogs,
	'upstream'         => $upstream,
	'watch'            => $watch,
	'issues'           => $issues,
	'cells'            => $cells,
	'others'           => $others,
	'todos'            => $todos,
]));

// Drop cache entries nobody has used for a while.
foreach (glob($config['cache_dir'] . '/*') ?: [] as $file) {
	if (is_file($file) && time() - filemtime($file) > $config['cache_prune_days'] * 86400) {
		unlink($file);
	}
}

$output = $opts['output'] ?? ($isCli ? __DIR__ . '/matrix.html' : '-');
if ($output === '-') {
	echo $html;
	exit(0);
}

if (file_put_contents($output, $html) === false) {
	fail("Cannot write $output");
}
info(
	"Wrote $output (" . count($issues['main']) . ' main issues, '
		. count($issues['other']) . ' issue groups in other packages)'
);

if (!isset($opts['no-publish'])) {
	foreach ($config['publish'] as $target) {
		// BatchMode: fail instead of prompting when run from cron.
		$proc = proc_open(['scp', '-q', '-o', 'BatchMode=yes', $output, $target], [], $pipes);
		if (!is_resource($proc) || proc_close($proc) !== 0) {
			fail("scp to $target failed");
		}
		info("Published to $target");
	}
}

// ---------------------------------------------------------------------------
// View

/**
 * Prepares everything the templates print, so they only loop, branch on
 * flags and escape.
 *
 * @param array<string, mixed> $report results of the analysis
 * @return array<string, mixed>
 */
function build_view(array $config, array $report): array
{
	[
		'supportedLabels'  => $supportedLabels,
		'hasOlderReleases' => $hasOlderReleases,
		'dists'            => $dists,
		'sources'          => $sources,
		'branches'         => $branches,
		'changelogs'       => $changelogs,
		'upstream'         => $upstream,
		'watch'            => $watch,
		'issues'           => $issues,
		'cells'            => $cells,
		'others'           => $others,
		'todos'            => $todos,
	] = $report;

	$supported = []; // "Debian 12 (bookworm), 13 (trixie)", ...
	foreach ($supportedLabels as $family => $labels) {
		$supported[] = $family . ' ' . implode(', ', $labels);
	}
	$issueCount = static fn (string $level): int
		=> count(array_filter($issues['main'], static fn ($i) => $i['level'] === $level));

	$view = [
		'generated'     => gmdate('Y-m-d H:i'),
		// null when no repository reports build status
		'buildCount'    => array_filter(array_column($config['repos'], 'builds')) ? count($issues['build']) : null,
		'repoLabels'    => array_column($config['repos'], 'label'),
		'tracked'       => implode(' ', array_map(static fn ($p) => sprintf($p, 'X.Y'), $config['sapi_packages'])),
		'supported'     => implode(' · ', $supported),
		'olderReleases' => $hasOlderReleases,
		'freexianUrl'   => $config['freexian_url'],
		'sourceUrl'     => $config['source_url'],
		'sourceLabel'   => preg_replace('#^https?://#', '', $config['source_url']),
		'ppaLabel'      => $config['repos']['ppa']['label'] ?? null,
		'issues'        => $issues,
		'issueCounts'   => ['error' => $issueCount('error'), 'warn' => $issueCount('warn')],
		'hasUpstream'   => (bool) $upstream,
		'hasChangelogs' => (bool) $changelogs,
		'hasWatch'      => (bool) $watch,
		'repos'         => [], // repoKey => ['label', 'url', 'deprecated']
		'distros'       => [], // codename => ['label', 'supported']
		'branches'      => [], // branch => ['title', 'state' => ['class', 'label'] for non-stable branches]
		'filters'       => [],
		'matrices'      => [], // repoKey => ['distros' => codename => [...], 'rows' => branch => [...]]
		'others'        => [], // repoKey => ['distros' => codename => published, 'groups' => [...]]
		'todoTypes'     => [], // type => ['description', 'count']
		'todos'         => [],
		'changelogs'    => [], // "repo|codename|branch" => ['title', 'version', 'text', 'url']
	];

	foreach ($config['repos'] as $repoKey => $repo) {
		$view['repos'][$repoKey] = [
			'label'      => $repo['label'],
			'url'        => $repo['web'] ?? $repo['url'],
			'deprecated' => $repo['deprecated'] ?? null,
		];
	}
	foreach ($config['distros'] as $codename => $_) {
		$view['distros'][$codename] = [
			'label'     => distro_label($codename, $config),
			'supported' => is_supported($codename, $config),
		];
	}
	foreach ($branches as $branch) {
		$st = $upstream[$branch]['state'] ?? null;
		$view['branches'][$branch] = [
			'title' => isset($upstream[$branch]['security_end'])
				? 'php.net security support until ' . substr($upstream[$branch]['security_end'], 0, 10)
				: null,
			'state' => $st !== null && $st !== 'stable'
				? ['class' => $st, 'label' => $st === 'eol' ? 'EOL' : $st]
				: null,
		];
	}

	// Filter dimensions: every scanned distro, architecture and branch.
	$filterDistros = [];
	$filterArchs = [];
	foreach ($dists as $byCodename) {
		foreach ($byCodename as $codename => $dist) {
			$filterDistros[$codename] = true;
			foreach ($dist['archs'] as $arch) {
				$filterArchs[$arch] = true;
			}
		}
	}
	$filterArchs = array_keys($filterArchs);
	sort($filterArchs);
	$view['filters'] = [
		'distro' => array_values(array_intersect(array_keys($config['distros']), array_keys($filterDistros))),
		'arch'   => $filterArchs,
		'branch' => $branches,
	];

	// Main matrix per repository.
	foreach ($dists as $repoKey => $byCodename) {
		$matrix = ['distros' => [], 'rows' => []];
		foreach ($byCodename as $codename => $dist) {
			$matrix['distros'][$codename] = [
				'published' => $dist['published'],
				'note'      => (is_supported($codename, $config) ? '' : 'unsupported · ') . ($dist['published']
					? ($dist['date'] ? 'updated ' . gmdate('Y-m-d H:i', strtotime($dist['date'])) : '')
					: 'not published in this repository'),
				'archs'     => [], // arch => true when only arch:all packages are built
			];
			foreach ($dist['archs'] as $arch) {
				$matrix['distros'][$codename]['archs'][$arch] = in_array($arch, $dist['unbuilt'] ?? [], true);
			}
		}
		foreach ($branches as $branch) {
			$row = ['bad' => false, 'cells' => []]; // cells: codename => arch => cell
			foreach ($cells[$repoKey] ?? [] as $codename => $byBranch) {
				foreach ($byBranch[$branch] ?? [] as $cell) {
					$row['bad'] = $row['bad']
						|| (is_supported($codename, $config) && STATE_RANK[$cell['state']] >= STATE_RANK['behind']);
				}
			}
			foreach ($byCodename as $codename => $dist) {
				$clKey = isset($changelogs["$repoKey|$codename|$branch"]) ? "$repoKey|$codename|$branch" : null;
				foreach ($dist['archs'] as $arch) {
					$cell = $cells[$repoKey][$codename][$branch][$arch];
					$cell['changelog'] = $cell['version'] !== null ? $clKey : null;
					$row['cells'][$codename][$arch] = $cell;
				}
			}
			$matrix['rows'][$branch] = $row;
		}
		$view['matrices'][$repoKey] = $matrix;
	}

	// Other packages: one group per PHP branch, then the rest.
	$anyAtLeast = static fn (array $byCodename, string $state): bool
		=> (bool) array_filter($byCodename, static fn ($c) => STATE_RANK[$c['state']] >= STATE_RANK[$state]);
	foreach ($others as $repoKey => $groups) {
		$other = ['distros' => array_map(static fn ($d) => $d['published'], $dists[$repoKey]), 'groups' => []];
		$order = array_values(array_filter($branches, static fn ($b) => isset($groups[$b])));
		if (isset($groups['other'])) {
			$order[] = 'other';
		}
		foreach ($order as $group) {
			$rows = [];
			$bad = 0;
			foreach ($groups[$group] as $name => $byCodename) {
				$bad += $anyAtLeast($byCodename, 'stale') ? 1 : 0;
				$rows[$name] = [
					'bad'   => $anyAtLeast($byCodename, 'behind'),
					'cells' => $byCodename,
				];
			}
			$other['groups'][] = [
				'branch' => $group === 'other' ? null : $group,
				'label'  => $group === 'other' ? 'not tied to a PHP branch' : "PHP $group",
				'bad'    => $bad,
				'rows'   => $rows,
			];
		}
		$view['others'][$repoKey] = $other;
	}

	// One todo row per type, repository, source and distros: the core/SAPI
	// and the "other packages" checks both report phpX.Y.
	$merged = [];
	foreach ($todos as $t) {
		$key = implode('|', [$t['type'], $t['repo'], $t['source'], implode(' ', (array) ($t['tags']['distro'] ?? []))]);
		if (!isset($merged[$key])) {
			$merged[$key] = $t;
			continue;
		}
		$merged[$key]['text'] .= '; ' . $t['text'];
		$merged[$key]['links'] += $t['links'];
		foreach (['arch', 'branch'] as $dim) {
			if (isset($t['tags'][$dim]) || isset($merged[$key]['tags'][$dim])) {
				$values = array_merge((array) ($merged[$key]['tags'][$dim] ?? []), (array) ($t['tags'][$dim] ?? []));
				$merged[$key]['tags'][$dim] = array_values(array_unique($values));
			}
		}
	}
	$todos = array_values($merged);

	// Todo rows: by type, then source, then repository. The id is stable while
	// the text is, so a hidden row comes back when something changes.
	$typeOrder = array_flip(array_keys(TODO_TYPES));
	$repoOrder = array_flip(array_keys($config['repos']));
	usort($todos, static fn ($a, $b) => $typeOrder[$a['type']] <=> $typeOrder[$b['type']]
		?: strnatcmp($a['source'], $b['source']) ?: $repoOrder[$a['repo']] <=> $repoOrder[$b['repo']]);
	$todoTypes = array_count_values(array_column($todos, 'type'));
	foreach (TODO_TYPES as $type => $desc) {
		if (isset($todoTypes[$type])) {
			$view['todoTypes'][$type] = ['description' => $desc, 'count' => $todoTypes[$type]];
		}
	}
	$sourceUrl = static function (string $repoKey, string $source) use ($config, $sources): ?string {
		$repo = $config['repos'][$repoKey];
		if (str_contains($repo['web'] ?? '', 'launchpad.net')) {
			return $repo['web'] . '/+packages?'
				. http_build_query(['field.name_filter' => $source, 'field.status_filter' => 'published']);
		}
		foreach ($sources[$repoKey] ?? [] as $srcs) {
			if (isset($srcs[$source]['vcs'])) {
				return $srcs[$source]['vcs'];
			}
		}
		return null;
	};
	foreach ($todos as $t) {
		$distros = implode(' ', (array) ($t['tags']['distro'] ?? []));
		$view['todos'][] = [
			'id'      => substr(sha1("{$t['type']}|{$t['repo']}|{$t['source']}|{$t['text']}|$distros"), 0, 12),
			'type'    => $t['type'],
			'class'   => 't-' . str_replace(' ', '-', $t['type']),
			'source'  => $t['source'],
			'url'     => $t['source'] !== '' ? $sourceUrl($t['repo'], $t['source']) : null,
			'repo'    => $config['repos'][$t['repo']]['label'],
			'distros' => implode(', ', (array) ($t['tags']['distro'] ?? [])),
			'text'    => $t['text'],
			'links'   => $t['links'],
			'tags'    => $t['tags'],
		];
	}

	foreach ($changelogs as $key => $cl) {
		[$repoKey, $codename, $branch] = explode('|', $key);
		$view['changelogs'][$key] = [
			'title'   => "PHP $branch · " . distro_label($codename, $config)
				. ' · ' . $config['repos'][$repoKey]['label'],
			'version' => $cl['version'],
			'text'    => $cl['text'],
			'url'     => $cl['url'],
		];
	}

	return $view;
}

// ---------------------------------------------------------------------------
// Templates: markup only, from the data prepared by build_view().

/** Filter attributes (data-repo, data-distro, ...) for a row or item. */
function data_attrs(array $tags): string
{
	$out = '';
	foreach (['repo', 'distro', 'arch', 'branch'] as $dim) {
		if (isset($tags[$dim])) {
			$out .= " data-$dim=\"" . h(implode(' ', (array) $tags[$dim])) . '"';
		}
	}
	return $out;
}

function render_cell(array $cell, string $attrs = '', string $extraClass = '', ?string $clKey = null): string
{
	$v = $cell['version'];
	$title = $v ?? 'not available';
	if ($cell['notes']) {
		$title .= "\n" . implode("\n", $cell['notes']);
	}
	$label = match (true) {
		$v !== null                  => base_version($v),
		isset($cell['building'])     => 'building',
		$cell['state'] === 'missing' => 'missing',
		default                      => '·',
	};
	if (isset($cell['building'])) {
		$extraClass .= match (true) {
			$cell['building']['kind'] === 'proxy'          => ' building guess',
			in_array($cell['building']['state'], ['Needs building', 'Not built yet'], true) => ' building queued',
			default                                        => ' building',
		};
	}
	$attrs .= ' class="s-' . $cell['state'] . $extraClass . '" title="' . h($title) . '"';
	if ($clKey !== null) {
		$attrs .= ' data-cl="' . h($clKey) . '" tabindex="0"';
	}
	return "<td$attrs>" . h($label) . ($cell['notes'] ? '<i>!</i>' : '') . '</td>';
}

function render_issues(array $list): string
{
	$out = '<ul class="issues">';
	foreach ($list as $issue) {
		$out .= '<li class="i-' . h($issue['level']) . '"' . data_attrs($issue['tags']) . '>';
		// "distro · repo · PHP x.y: message" => context line above the message.
		$parts = explode(': ', $issue['text'], 2);
		$text = count($parts) === 2
			? '<span class="ctx">' . h($parts[0]) . '</span>' . h($parts[1])
			: h($issue['text']);
		if ($issue['items']) {
			$out .= '<details><summary>' . $text . '</summary>'
				. '<p>' . h(implode(', ', $issue['items'])) . '</p></details>';
		} else {
			$out .= $text;
		}
		foreach ($issue['links'] as $label => $url) {
			$out .= ' <a href="' . h($url) . '" target="_blank" rel="noopener">' . h($label) . '</a>';
		}
		$out .= '</li>';
	}
	return $out . '</ul>';
}

function deprecated_tag(?array $deprecated): string
{
	return $deprecated !== null
		? ' <a class="deprecated" href="' . h($deprecated['url']) . '"'
			. ' title="' . h($deprecated['text']) . '">deprecated</a>'
		: '';
}

// The templates below are HTML with inline PHP; the PHP layout rules don't
// apply to them.
// phpcs:disable

function render_page(array $view): string
{
	ob_start();
	?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP build matrix</title>
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link href="https://fonts.bunny.net/css?family=ibm-plex-mono:400,500,600|ibm-plex-sans-condensed:400,600,700&amp;display=swap" rel="stylesheet">
<style>
<?php render_styles(); ?>
</style>
</head>
<body>
<main>
<header class="masthead">
	<h1>php<span>/</span>build-matrix</h1>
	<div class="stamp">generated <?= h($view['generated']) ?> UTC<?php if ($view['buildCount'] !== null): ?><br><?php if ($view['buildCount']): ?><a href="#building"><?= $view['buildCount'] ?> source<?= $view['buildCount'] === 1 ? '' : 's' ?> building</a><?php else: ?>no builds in progress<?php endif; ?><?php endif; ?><br><a href="<?= h($view['sourceUrl']) ?>"><?= h($view['sourceLabel']) ?></a></div>
	<p>Core and SAPI package versions per PHP branch, distribution and architecture in
		<?= h(implode(' and ', $view['repoLabels'])) ?>.<br>
		Tracked: <code><?= h($view['tracked']) ?></code></p>
</header>

<div class="support">
	<div><b>Supported</b><?= h($view['supported']) ?></div>
	<?php foreach ($view['repos'] as $repoKey => $repo): if ($repo['deprecated'] !== null): ?>
	<div class="unsup" data-repo="<?= h($repoKey) ?>"><b>Deprecated</b><?= h($repo['label']) ?> · <a href="<?= h($repo['deprecated']['url']) ?>"><?= h($repo['deprecated']['text']) ?></a></div>
	<?php endif; endforeach; ?>
	<?php if ($view['olderReleases']): ?>
	<div class="unsup"><b>Older releases</b>Commercial support by <a href="<?= h($view['freexianUrl']) ?>">Freexian PHP LTS</a></div>
	<?php endif; ?>
</div>

<nav class="filters" aria-label="Filters">
	<div class="fgroup" data-dim="distro"><span title="Click to select all / none">Distro</span>
		<?php foreach ($view['filters']['distro'] as $codename): ?>
		<button type="button" class="chip" data-value="<?= h($codename) ?>" aria-pressed="true" title="<?= h($view['distros'][$codename]['label']) ?>"><?= h($codename) ?></button>
		<?php endforeach; ?>
	</div>
	<div class="fgroup" data-dim="arch"><span title="Click to select all / none">Arch</span>
		<?php foreach ($view['filters']['arch'] as $arch): ?>
		<button type="button" class="chip" data-value="<?= h($arch) ?>" aria-pressed="true"><?= h($arch) ?></button>
		<?php endforeach; ?>
	</div>
	<div class="fgroup" data-dim="branch"><span title="Click to select all / none">PHP</span>
		<?php foreach ($view['filters']['branch'] as $branch): ?>
		<button type="button" class="chip" data-value="<?= h($branch) ?>" aria-pressed="true"><?= h($branch) ?></button>
		<?php endforeach; ?>
	</div>
	<div class="fgroup">
		<button type="button" class="chip toggle" id="only-bad" aria-pressed="false">problems only</button>
		<?php if ($view['ppaLabel'] !== null): ?>
		<button type="button" class="chip toggle" id="hide-ppa" aria-pressed="false" title="Hide <?= h($view['ppaLabel']) ?> everywhere: matrices, issues and todos">hide PPA</button>
		<?php endif; ?>
		<button type="button" class="chip" id="reset" title="Show everything: all repositories, distros, architectures and PHP branches">reset</button>
	</div>
	<span class="hint">click to toggle · shift-click for only this one · click a group name for all/none</span>
</nav>

<?php if ($view['issues']['build']): ?>
<h2 id="building">Building now <span class="count">queued or running on Launchpad, unbuilt packaging commits, or (dashed) probably building because the PPA is; affected cells are outlined</span></h2>
<?= render_issues($view['issues']['build']) ?>
<?php endif; ?>

<h2>Issues <span class="count" id="issue-count"><?= $view['issueCounts']['error'] ?> errors · <?= $view['issueCounts']['warn'] ?> warnings</span></h2>
<?php if ($view['issues']['main']): ?>
	<?= render_issues($view['issues']['main']) ?>
<?php else: ?>
	<p>No problems in the core/SAPI packages.</p>
<?php endif; ?>

<ul class="legend">
	<li><b style="background:var(--ok-bg)"></b>latest</li>
	<?php if ($view['hasUpstream']): ?><li><b style="background:var(--behind-bg);box-shadow:inset 3px 0 0 var(--bad-fg)"></b>behind php.net release</li><?php endif; ?>
	<li><b style="background:var(--behind-bg)"></b>behind other distros</li>
	<li><b style="background:var(--bad-bg)"></b>missing package(s) or older than other architectures</li>
	<li><b class="unpub" style="background:repeating-linear-gradient(135deg, transparent 0 5px, var(--rule) 5px 7px)"></b>distribution not published</li>
	<?php if ($view['issues']['build']): ?><li><b class="building" style="background-color:var(--ok-bg);box-shadow:inset 0 0 0 2px var(--mark)"></b>build in progress</li><?php endif; ?>
	<li><span class="mono">!</span> hover for details<?= $view['hasChangelogs'] ? ' · click a version for its changelog' : '' ?></li>
</ul>

<?php render_matrices($view); ?>

<?php render_others($view); ?>

<?php render_todos($view); ?>

<hr>
<footer>
	generated <?= h($view['generated']) ?> UTC
	· <a href="<?= h($view['sourceUrl']) ?>">source on GitHub</a>
	· Toni Viemerö &lt;<a href="mailto:toni.viemero@iki.fi">toni.viemero@iki.fi</a>&gt;
</footer>
</main>

<dialog id="changelog">
	<header>
		<div><h3 id="cl-title"></h3><div class="ver" id="cl-version"></div></div>
		<button type="button" autofocus onclick="this.closest('dialog').close()">close</button>
	</header>
	<pre id="cl-text"></pre>
	<footer><a id="cl-url" target="_blank" rel="noopener">full changelog ↗</a></footer>
</dialog>

<script>
<?php render_script($view['changelogs']); ?>
</script>
</body>
</html>
<?php
	return (string) ob_get_clean();
}

/** Main matrix: one table per repository, PHP branches × distro architectures. */
function render_matrices(array $view): void
{
	?>
<?php foreach ($view['matrices'] as $repoKey => $matrix): $repo = $view['repos'][$repoKey]; ?>
<section class="repo" data-repo="<?= h($repoKey) ?>" data-distro="<?= h(implode(' ', array_keys($matrix['distros']))) ?>">
<h2><?= h($repo['label']) ?> <a href="<?= h($repo['url']) ?>"><?= h($repo['url']) ?></a><?= deprecated_tag($repo['deprecated']) ?></h2>
<div class="scroll">
<table class="matrix">
	<thead>
		<tr>
			<th class="row" rowspan="2">PHP</th>
			<?php foreach ($matrix['distros'] as $codename => $dist): $distro = $view['distros'][$codename]; ?>
			<th class="dhead<?= $distro['supported'] ? '' : ' dim' ?>" data-distro="<?= h($codename) ?>" colspan="<?= max(1, count($dist['archs'])) ?>">
				<?= h($distro['label']) ?>
				<small><?= h($dist['note']) ?></small>
			</th>
			<?php endforeach; ?>
		</tr>
		<tr>
			<?php foreach ($matrix['distros'] as $codename => $dist): ?>
				<?php if (!$dist['published']): ?><th class="arch" data-distro="<?= h($codename) ?>">—</th><?php endif; ?>
				<?php foreach ($dist['archs'] as $arch => $unbuilt): ?>
				<th class="arch<?= $unbuilt ? ' dim' : '' ?>" data-distro="<?= h($codename) ?>" data-arch="<?= h($arch) ?>"<?= $unbuilt ? ' title="Listed in Release, but only arch:all packages"' : '' ?>><?= h($arch) ?><?= $unbuilt ? '<small>not built</small>' : '' ?></th>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
	<?php foreach ($matrix['rows'] as $branch => $row): $info = $view['branches'][$branch]; ?>
		<tr data-branch="<?= h($branch) ?>"<?= $row['bad'] ? ' data-bad' : '' ?>>
			<th class="row"<?= $info['title'] !== null ? ' title="' . h($info['title']) . '"' : '' ?>><?= h($branch) ?><?php
				if ($info['state'] !== null): ?><small class="ustate u-<?= h($info['state']['class']) ?>"><?= h($info['state']['label']) ?></small><?php endif; ?></th>
			<?php foreach ($matrix['distros'] as $codename => $dist):
				$dim = $view['distros'][$codename]['supported'] ? '' : ' dim';
				if (!$dist['published']): ?><td class="unpub<?= $dim ?>" data-distro="<?= h($codename) ?>">not published</td><?php endif;
				foreach (array_keys($dist['archs']) as $arch):
					$cell = $row['cells'][$codename][$arch];
					echo render_cell($cell, data_attrs(['distro' => $codename, 'arch' => $arch]), $dim, $cell['changelog']);
				endforeach;
			endforeach; ?>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
</section>
<?php endforeach; ?>
<?php
}

/** All other packages: one cell per distro, grouped by PHP branch. */
function render_others(array $view): void
{
	?>
<h2 id="other">All other packages <span class="count">one cell per distro · newest version across architectures<?= $view['hasWatch'] ? ' · left bar: newer upstream release (debian/watch)' : '' ?></span></h2>
<details class="other-issues">
	<summary>Problems in other packages (<?= count($view['issues']['other']) ?> groups)</summary>
	<?= render_issues($view['issues']['other']) ?>
</details>
<div class="pkgtools">
	<input type="search" id="pkg-filter" placeholder="filter packages: redis, intl, libgd…">
	<button type="button" id="expand">expand all</button>
</div>

<?php foreach ($view['others'] as $repoKey => $other): $repo = $view['repos'][$repoKey]; ?>
<section class="repo" data-repo="<?= h($repoKey) ?>" data-distro="<?= h(implode(' ', array_keys($other['distros']))) ?>">
<h3><?= h($repo['label']) ?><?= deprecated_tag($repo['deprecated']) ?></h3>
<?php foreach ($other['groups'] as $group): ?>
<details class="pkgs"<?= $group['branch'] === null ? '' : ' data-branch="' . h($group['branch']) . '"' ?>>
	<summary><?= h($group['label']) ?><span><?= count($group['rows']) ?> packages</span><?php if ($group['bad']): ?><span class="bad"><?= $group['bad'] ?> with problems</span><?php endif; ?></summary>
	<div class="scroll">
	<table>
		<thead><tr>
			<th class="row">package</th>
			<?php foreach (array_keys($other['distros']) as $codename): $distro = $view['distros'][$codename]; ?>
			<th class="<?= $distro['supported'] ? '' : 'dim' ?>" data-distro="<?= h($codename) ?>"><?= h($distro['label']) ?></th>
			<?php endforeach; ?>
		</tr></thead>
		<tbody>
		<?php foreach ($group['rows'] as $name => $row): ?>
			<tr data-name="<?= h((string) $name) ?>"<?= $row['bad'] ? ' data-bad' : '' ?>>
				<th class="row"><?= h((string) $name) ?></th>
				<?php foreach ($other['distros'] as $codename => $published):
					$dim = $view['distros'][$codename]['supported'] ? '' : ' dim';
					if ($published):
						echo render_cell($row['cells'][$codename], ' data-distro="' . h($codename) . '"', $dim);
					else: ?><td class="unpub<?= $dim ?>" data-distro="<?= h($codename) ?>">not published</td><?php endif;
				endforeach; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
</details>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php
}

/** Todo list, one row per thing to do for a source package. */
function render_todos(array $view): void
{
	?>
<h2 id="todo">Todo list <span class="count" id="todo-count"><?= count($view['todos']) ?> items</span></h2>
<div class="pkgtools">
	<div class="fgroup" id="todo-types">
		<?php foreach ($view['todoTypes'] as $type => $todoType): ?>
		<button type="button" class="chip" data-type="<?= h($type) ?>" aria-pressed="true" title="<?= h($todoType['description']) ?>"><?= h($type) ?> <?= $todoType['count'] ?></button>
		<?php endforeach; ?>
	</div>
	<button type="button" id="todo-show-hidden" aria-pressed="false">show hidden (<span id="todo-hidden-count">0</span>)</button>
</div>
<?php if ($view['todos']): ?>
<div class="scroll">
<table class="todo">
	<thead><tr><th>type</th><th>source</th><th>repository</th><th>distros</th><th>description</th><th></th></tr></thead>
	<tbody>
	<?php foreach ($view['todos'] as $todo): ?>
		<tr class="<?= h($todo['class']) ?>" data-id="<?= $todo['id'] ?>" data-type="<?= h($todo['type']) ?>"<?= data_attrs($todo['tags']) ?>>
			<td class="type"><?= h($todo['type']) ?></td>
			<td class="src"><?php if ($todo['source'] === ''): ?>—<?php elseif ($todo['url'] !== null): ?><a href="<?= h($todo['url']) ?>" target="_blank" rel="noopener"><?= h($todo['source']) ?></a><?php else: ?><?= h($todo['source']) ?><?php endif; ?></td>
			<td class="repo"><?= h($todo['repo']) ?></td>
			<td class="dist"><?= h($todo['distros']) ?></td>
			<td><?= h($todo['text']) ?><?php foreach ($todo['links'] as $label => $link): ?> <a href="<?= h($link) ?>" target="_blank" rel="noopener"><?= h($label) ?></a><?php endforeach; ?></td>
			<td><button type="button" class="todo-hide">hide</button></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<?php else: ?>
<p>Nothing to do.</p>
<?php endif; ?>
<?php
}

function render_styles(): void
{
	?>
:root {
	--paper: #f3efe6; --ink: #1f1a14; --muted: #786d5e; --rule: #d6ccba; --rule-strong: #1f1a14; --band: #e8e0d0;
	--ok-bg: #dfe5c8; --ok-fg: #3d561c;
	--behind-bg: #f1dca6; --behind-fg: #6b4700;
	--bad-bg: #f1c4b6; --bad-fg: #8a1c08;
	--none: #b9ae9c; --mark: #c2410c; --chip-off: #a99d8a;
	--mono: "IBM Plex Mono", ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
	--sans: "IBM Plex Sans Condensed", "Arial Narrow", system-ui, sans-serif;
}
@media (prefers-color-scheme: dark) {
	:root {
		--paper: #191612; --ink: #ebe3d4; --muted: #9a8f7d; --rule: #362f27; --rule-strong: #ebe3d4; --band: #221e19;
		--ok-bg: #28301a; --ok-fg: #b6cf86;
		--behind-bg: #3b2e12; --behind-fg: #e9c26c;
		--bad-bg: #47201a; --bad-fg: #ff9c84;
		--none: #4d453b; --mark: #f08a4b; --chip-off: #5a5145;
	}
}
* { box-sizing: border-box; }
html { background: var(--paper); }
body { margin: 0; padding: 0 16px 64px; background: var(--paper); color: var(--ink); font: 15px/1.45 var(--sans); }
main { max-width: 1760px; margin: 0 auto; }
a { color: var(--mark); text-underline-offset: 2px; }
code, .mono { font-family: var(--mono); font-size: 12px; }
hr { border: 0; border-top: 1px solid var(--rule); margin: 48px 0 12px; }
footer { font: 12px var(--mono); color: var(--muted); }

.masthead { display: grid; grid-template-columns: 1fr auto; gap: 4px 24px; align-items: end;
	border-bottom: 3px solid var(--rule-strong); padding: 28px 0 12px; }
.masthead h1 { margin: 0; font: 600 30px/1 var(--mono); letter-spacing: -.02em; }
.masthead h1 span { color: var(--mark); }
.masthead .stamp { font: 12px var(--mono); color: var(--muted); text-align: right; }
.masthead p { grid-column: 1 / -1; margin: 6px 0 0; color: var(--muted); }

.support { margin: 14px 0 0; padding: 0 0 14px; border-bottom: 1px solid var(--rule); display: flex; flex-wrap: wrap; gap: 4px 28px; }
.support b { font-weight: 700; text-transform: uppercase; letter-spacing: .06em; font-size: 12px; margin-right: 6px; }
.support .unsup { color: var(--bad-fg); }
.deprecated { margin-left: 10px; padding: 1px 6px; font: 600 11px var(--mono); letter-spacing: .04em; text-transform: uppercase; background: var(--bad-bg); color: var(--bad-fg); text-decoration: none; vertical-align: middle; }
h2 a.deprecated { font: 600 11px var(--mono); letter-spacing: .04em; text-transform: uppercase; }

/* Filter bar */
.filters { position: sticky; top: 0; z-index: 5; background: var(--paper); border-bottom: 1px solid var(--rule);
	padding: 10px 0; display: flex; flex-wrap: wrap; gap: 8px 22px; align-items: center; }
.fgroup { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.fgroup > span { font: 700 11px var(--sans); text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-right: 4px; cursor: pointer; }
.chip { font: 500 12px var(--mono); padding: 2px 7px; border: 1px solid var(--ink); background: var(--ink); color: var(--paper);
	cursor: pointer; border-radius: 0; }
.chip[aria-pressed="false"] { background: transparent; color: var(--chip-off); border-color: var(--chip-off); text-decoration: line-through; }
.chip.toggle[aria-pressed="false"] { text-decoration: none; }
#reset { background: transparent; color: var(--ink); }
.filters .hint { font-size: 12px; color: var(--muted); }

h2 { font: 700 13px var(--sans); text-transform: uppercase; letter-spacing: .1em; margin: 40px 0 10px;
	padding-top: 8px; border-top: 3px solid var(--rule-strong); display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; }
h2 .count { font: 500 12px var(--mono); letter-spacing: 0; text-transform: none; color: var(--muted); }
h2 a { font: 400 12px var(--mono); letter-spacing: 0; text-transform: none; }
h3 { font: 600 16px var(--mono); margin: 28px 0 6px; }

.issues { list-style: none; margin: 0; padding: 0; font: 13px/1.5 var(--mono); columns: 2 640px; column-gap: 32px; }
.issues li { break-inside: avoid; padding: 5px 0 5px 14px; position: relative; overflow-wrap: anywhere; }
.issues li + li { border-top: 1px solid var(--rule); }
.issues .ctx { display: block; font: 600 11px/1.4 var(--sans); letter-spacing: .02em; color: var(--muted); }
.issues summary .ctx { display: inline; }
.issues summary .ctx::after { content: ""; display: block; }
.issues li::before { content: ""; position: absolute; left: 0; top: 10px; width: 8px; height: 8px; }
.issues .i-error::before { background: var(--bad-fg); }
.issues .i-warn::before { background: var(--behind-fg); border-radius: 50%; }
.issues .i-build::before { width: 6px; height: 6px; border: 2px solid var(--mark); border-right-color: transparent; border-radius: 50%; animation: spin 1.2s linear infinite; }
.issues .i-build a { font-size: 12px; margin-left: 4px; }
.issues details summary { cursor: pointer; }
.issues details p { margin: 2px 0 6px; color: var(--muted); font-size: 12px; }

.legend { display: flex; flex-wrap: wrap; gap: 4px 18px; margin: 14px 0 0; padding: 0; list-style: none; font-size: 13px; color: var(--muted); }
.legend b { display: inline-block; width: 22px; height: 12px; vertical-align: -1px; margin-right: 6px; }

/* Matrices */
.scroll { overflow-x: auto; }
table { border-collapse: collapse; font-variant-numeric: tabular-nums; }
th, td { padding: 3px 7px; text-align: center; white-space: nowrap; border: 1px solid var(--paper); }
thead th { font: 600 13px var(--sans); vertical-align: bottom; border-bottom: 2px solid var(--rule-strong); }
thead tr:first-child th { text-align: left; border-bottom: 1px solid var(--rule); }
thead small { display: block; font: 400 11px var(--mono); color: var(--muted); }
thead .arch { font: 500 11px var(--mono); color: var(--muted); }
th.row { position: sticky; left: 0; z-index: 1; background: var(--paper); text-align: left; font: 600 14px var(--mono); padding-right: 14px; }
th.dim, td.dim { opacity: .5; }
td { font: 12px var(--mono); }
td.s-ok { background: var(--ok-bg); color: var(--ok-fg); }
td.s-behind { background: var(--behind-bg); color: var(--behind-fg); }
td.s-stale, td.s-missing { background: var(--bad-bg); color: var(--bad-fg); font-weight: 600; }
td.s-upstream { background: var(--behind-bg); color: var(--bad-fg); font-weight: 600; box-shadow: inset 3px 0 0 var(--bad-fg); }
th.row small.ustate { display: inline; margin-left: 6px; font: 500 10px var(--mono); text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
th.row small.u-eol { color: var(--bad-fg); }
td.s-absent, td.unpub { color: var(--none); }
td.unpub { background: repeating-linear-gradient(135deg, transparent 0 5px, var(--band) 5px 7px); font-style: italic; }
td i { font-style: normal; font-size: 10px; vertical-align: super; margin-left: 1px; }
td.building { box-shadow: inset 0 0 0 2px var(--mark); background-image: repeating-linear-gradient(135deg, transparent 0 6px, color-mix(in srgb, var(--mark) 18%, transparent) 6px 10px);
	background-size: 28.28px 28.28px; animation: crawl 1.5s linear infinite; }
td.building.queued { box-shadow: inset 0 0 0 1px var(--mark); animation: none; }
td.building.guess { box-shadow: none; outline: 1px dashed var(--mark); outline-offset: -2px; background-image: none; animation: none; }
@keyframes crawl { to { background-position: 28.28px 0; } }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { td.building, .issues .i-build::before { animation: none; } }
td[data-cl] { cursor: pointer; }
td[data-cl]:hover, td[data-cl]:focus-visible { outline: 2px solid var(--ink); outline-offset: -2px; }
tbody tr:hover th.row { color: var(--mark); }
[hidden] { display: none !important; }

/* Other packages */
.pkgtools { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; margin: 6px 0 8px; font-size: 13px; }
.pkgtools input[type=search] { font: 13px var(--mono); padding: 4px 8px; border: 1px solid var(--ink); background: transparent; color: var(--ink); min-width: 260px; border-radius: 0; }
.pkgtools button { font: 500 12px var(--mono); padding: 3px 8px; border: 1px solid var(--ink); background: transparent; color: var(--ink); cursor: pointer; border-radius: 0; }
details.pkgs { border-top: 1px solid var(--rule); }
details.pkgs > summary { cursor: pointer; padding: 6px 0; font: 600 14px var(--mono); }
details.pkgs > summary span { font-weight: 400; color: var(--muted); font-size: 12px; margin-left: 8px; }
details.pkgs > summary .bad { color: var(--bad-fg); }
details.pkgs tbody th.row { font: 12px var(--mono); }
.other-issues > summary { cursor: pointer; font: 600 13px var(--mono); margin: 4px 0 8px; }

/* Todo list */
table.todo { width: 100%; }
table.todo th, table.todo td { text-align: left; white-space: normal; border: 0; border-bottom: 1px solid var(--rule); padding: 4px 10px 4px 0; vertical-align: top; }
table.todo thead th { border-bottom: 2px solid var(--rule-strong); }
table.todo td { font: 12px/1.45 var(--mono); }
table.todo td.type { white-space: nowrap; font: 600 11px/1.6 var(--sans); text-transform: uppercase; letter-spacing: .04em; }
table.todo td.src, table.todo td.repo, table.todo td.dist { white-space: nowrap; }
table.todo td.dist { color: var(--muted); }
table.todo tr.t-missing-build td.type, table.todo tr.t-outdated-build td.type, table.todo tr.t-unpublished td.type, table.todo tr.t-new-upstream td.type { color: var(--bad-fg); }
table.todo tr.t-behind td.type, table.todo tr.t-vcs td.type, table.todo tr.t-repo-sync td.type, table.todo tr.t-uscan-error td.type { color: var(--behind-fg); }
table.todo tr.is-hidden { opacity: .45; }
table.todo button { font: 500 11px var(--mono); padding: 0 6px; border: 1px solid var(--chip-off); background: transparent; color: var(--muted); cursor: pointer; border-radius: 0; }
#todo-types .chip { font-size: 11px; }
#todo-show-hidden[aria-pressed="true"] { background: var(--ink); color: var(--paper); }

dialog { width: min(920px, calc(100vw - 32px)); max-height: calc(100vh - 48px); border: 2px solid var(--ink);
	background: var(--paper); color: var(--ink); padding: 0; border-radius: 0; }
dialog::backdrop { background: rgba(25, 20, 14, .55); }
dialog header { display: flex; justify-content: space-between; gap: 12px; align-items: start; padding: 12px 16px; border-bottom: 1px solid var(--rule); }
dialog h3 { margin: 0; font-size: 15px; }
dialog .ver { font: 12px var(--mono); color: var(--muted); }
dialog pre { margin: 0; padding: 12px 16px; overflow: auto; max-height: calc(100vh - 210px); font: 12px/1.5 var(--mono); white-space: pre-wrap; }
dialog footer { padding: 8px 16px 12px; font: 12px var(--mono); border-top: 1px solid var(--rule); }
dialog button { font: 500 12px var(--mono); border: 1px solid var(--ink); background: transparent; color: var(--ink); padding: 2px 10px; cursor: pointer; }
<?php
}

function render_script(array $changelogs): void
{
	?>
const CHANGELOGS = <?= json_encode($changelogs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

// Changelog dialog
const dialog = document.getElementById('changelog');
function openChangelog(td) {
	const cl = CHANGELOGS[td.dataset.cl];
	if (!cl) return;
	document.getElementById('cl-title').textContent = cl.title;
	document.getElementById('cl-version').textContent = 'source ' + cl.version;
	document.getElementById('cl-text').textContent = cl.text || 'Changelog not available.';
	const link = document.getElementById('cl-url');
	link.hidden = !cl.url;
	if (cl.url) link.href = cl.url;
	dialog.showModal();
}
document.addEventListener('click', e => {
	const td = e.target.closest('td[data-cl]');
	if (td) openChangelog(td);
});
document.addEventListener('keydown', e => {
	const td = e.target.closest && e.target.closest('td[data-cl]');
	if (td && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openChangelog(td); }
});

// Distro / arch / PHP filters, persisted in the URL hash
const DIMS = ['distro', 'arch', 'branch'];
const off = { distro: new Set(), arch: new Set(), branch: new Set() };
const onlyBad = document.getElementById('only-bad');
const hidePpa = document.getElementById('hide-ppa');
const pkgFilter = document.getElementById('pkg-filter');

function allowed(el, dim) {
	const v = el.dataset[dim];
	if (v === undefined || v === '') return true;
	return v.split(' ').some(x => !off[dim].has(x));
}
// Unlike the other dimensions, anything involving a hidden repository is hidden.
const repoShown = el => !el.dataset.repo || !(hidePpa?.getAttribute('aria-pressed') === 'true' && el.dataset.repo.split(' ').includes('ppa'));
const visible = el => repoShown(el) && DIMS.every(d => allowed(el, d));

function apply() {
	document.querySelectorAll('.fgroup[data-dim] .chip').forEach(c =>
		c.setAttribute('aria-pressed', String(!off[c.closest('.fgroup').dataset.dim].has(c.dataset.value))));

	// Repositories with none of the selected distros
	document.querySelectorAll('section.repo').forEach(s => s.hidden = !allowed(s, 'distro') || !repoShown(s));
	document.querySelectorAll('.support [data-repo]').forEach(el => el.hidden = !repoShown(el));

	// Main matrices: hide columns, fix distro header spans, hide rows
	document.querySelectorAll('table.matrix').forEach(t => {
		t.querySelectorAll('[data-distro]:not(.dhead)').forEach(el => el.hidden = !visible(el));
		t.querySelectorAll('th.dhead').forEach(th => {
			const n = t.querySelectorAll(`thead th.arch[data-distro="${th.dataset.distro}"]:not([hidden])`).length;
			th.hidden = n === 0;
			if (n) th.colSpan = n;
		});
		t.querySelectorAll('tbody tr').forEach(tr => tr.hidden = !visible(tr) || (onlyBad.getAttribute('aria-pressed') === 'true' && !tr.hasAttribute('data-bad')));
	});

	// Issues
	let errors = 0, warns = 0;
	document.querySelectorAll('.issues li').forEach(li => {
		li.hidden = !visible(li);
		if (!li.hidden && li.closest('.other-issues') === null) {
			if (li.classList.contains('i-error')) errors++;
			else if (li.classList.contains('i-warn')) warns++;
		}
	});
	document.getElementById('issue-count').textContent = `${errors} errors · ${warns} warnings`;

	// Other packages
	const q = pkgFilter.value.trim().toLowerCase();
	const bad = onlyBad.getAttribute('aria-pressed') === 'true';
	document.querySelectorAll('details.pkgs').forEach(d => {
		d.querySelectorAll('[data-distro]').forEach(el => el.hidden = !visible(el));
		let shown = 0;
		d.querySelectorAll('tbody tr').forEach(tr => {
			const ok = (!q || tr.dataset.name.includes(q)) && (!bad || tr.hasAttribute('data-bad'));
			tr.hidden = !ok;
			shown += ok;
		});
		d.hidden = !visible(d) || shown === 0;
		if (q) d.open = shown > 0;
	});

	applyTodo();

	const parts = DIMS.filter(d => off[d].size).map(d => d + '=' + [...off[d]].join(','));
	if (bad) parts.push('bad');
	if (hidePpa?.getAttribute('aria-pressed') === 'true') parts.push('noppa');
	history.replaceState(null, '', parts.length ? '#' + parts.join('&') : location.pathname + location.search);
}

// Todo list: type chips, and rows hidden per viewer (localStorage)
const todoTypesOff = new Set();
const showHidden = document.getElementById('todo-show-hidden');
let todoHidden = new Set();
try { todoHidden = new Set(JSON.parse(localStorage.getItem('todo-hidden') || '[]')); } catch (e) {}
function saveTodoHidden() {
	// Forget ids that no longer exist, so the list doesn't grow forever.
	const ids = new Set([...document.querySelectorAll('table.todo tbody tr')].map(tr => tr.dataset.id));
	todoHidden = new Set([...todoHidden].filter(id => ids.has(id)));
	try { localStorage.setItem('todo-hidden', JSON.stringify([...todoHidden])); } catch (e) {}
}
function applyTodo() {
	document.querySelectorAll('#todo-types .chip').forEach(c => c.setAttribute('aria-pressed', String(!todoTypesOff.has(c.dataset.type))));
	const all = showHidden.getAttribute('aria-pressed') === 'true';
	let shown = 0, hidden = 0;
	document.querySelectorAll('table.todo tbody tr').forEach(tr => {
		const isHidden = todoHidden.has(tr.dataset.id);
		hidden += isHidden;
		tr.classList.toggle('is-hidden', isHidden);
		tr.querySelector('.todo-hide').textContent = isHidden ? 'unhide' : 'hide';
		tr.hidden = !visible(tr) || todoTypesOff.has(tr.dataset.type) || (isHidden && !all);
		shown += !tr.hidden;
	});
	document.getElementById('todo-count').textContent = `${shown} items`;
	document.getElementById('todo-hidden-count').textContent = hidden;
}
document.getElementById('todo-types').addEventListener('click', e => {
	const chip = e.target.closest('.chip');
	if (!chip) return;
	const t = chip.dataset.type;
	if (e.shiftKey) {
		document.querySelectorAll('#todo-types .chip').forEach(c => c.dataset.type === t ? todoTypesOff.delete(t) : todoTypesOff.add(c.dataset.type));
	} else if (todoTypesOff.has(t)) {
		todoTypesOff.delete(t);
	} else {
		todoTypesOff.add(t);
	}
	applyTodo();
});
showHidden.addEventListener('click', () => {
	showHidden.setAttribute('aria-pressed', String(showHidden.getAttribute('aria-pressed') !== 'true'));
	applyTodo();
});
document.addEventListener('click', e => {
	const btn = e.target.closest('.todo-hide');
	if (!btn) return;
	const id = btn.closest('tr').dataset.id;
	todoHidden.has(id) ? todoHidden.delete(id) : todoHidden.add(id);
	saveTodoHidden();
	applyTodo();
});

document.querySelectorAll('.fgroup[data-dim]').forEach(g => {
	const dim = g.dataset.dim;
	const values = [...g.querySelectorAll('.chip')].map(c => c.dataset.value);
	g.addEventListener('click', e => {
		const chip = e.target.closest('.chip');
		if (chip) {
			const v = chip.dataset.value;
			if (e.shiftKey) {
				off[dim] = new Set(values.filter(x => x !== v));
			} else if (off[dim].has(v)) {
				off[dim].delete(v);
			} else {
				off[dim].add(v);
			}
		} else if (e.target.matches('span')) {
			off[dim] = off[dim].size ? new Set() : new Set(values);
		} else {
			return;
		}
		apply();
	});
});
onlyBad.addEventListener('click', () => {
	onlyBad.setAttribute('aria-pressed', String(onlyBad.getAttribute('aria-pressed') !== 'true'));
	apply();
});
hidePpa?.addEventListener('click', () => {
	hidePpa.setAttribute('aria-pressed', String(hidePpa.getAttribute('aria-pressed') !== 'true'));
	apply();
});
pkgFilter.addEventListener('input', apply);
document.getElementById('reset').addEventListener('click', () => {
	DIMS.forEach(d => off[d].clear());
	todoTypesOff.clear();
	onlyBad.setAttribute('aria-pressed', 'false');
	hidePpa?.setAttribute('aria-pressed', 'false');
	pkgFilter.value = '';
	apply();
});
document.getElementById('expand').addEventListener('click', e => {
	const all = [...document.querySelectorAll('details.pkgs:not([hidden])')];
	const open = !all.every(d => d.open);
	all.forEach(d => d.open = open);
	e.target.textContent = open ? 'collapse all' : 'expand all';
});

for (const part of location.hash.slice(1).split('&')) {
	const [k, v] = part.split('=');
	if (DIMS.includes(k) && v) off[k] = new Set(decodeURIComponent(v).split(','));
	if (k === 'bad') onlyBad.setAttribute('aria-pressed', 'true');
	if (k === 'noppa') hidePpa?.setAttribute('aria-pressed', 'true');
}
apply();
<?php
}
