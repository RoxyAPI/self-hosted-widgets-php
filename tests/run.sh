#!/usr/bin/env bash
# Run: bash tests/run.sh (offline). LIVE=1 ROXY_TEST_KEY=pk_... bash tests/run.sh adds the end to end checks.
# Does: serves roxy.php with the PHP built-in server and asserts every refusal, the relay and that no key is ever echoed.
# Writes: a temporary docroot, removed on exit.
set -u
cd "$(dirname "$0")/.."
PORT=${PORT:-8765}
BASE="http://127.0.0.1:$PORT"
ROOT=$(mktemp -d)
CANARY=pk_test_canary_do_not_echo
KEY=${ROXY_TEST_KEY:-}
fails=0

with_key() { sed "s|^\$ROXY_KEY = '';|\$ROXY_KEY = '$1';|" roxy.php > "$ROOT/$2"; }
with_key "$CANARY" roxy.php
cp roxy.php "$ROOT/nokey.php"
[ -n "$KEY" ] && with_key "$KEY" live.php

php -S "127.0.0.1:$PORT" -t "$ROOT" > "$ROOT/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; rm -rf "$ROOT"' EXIT
for _ in $(seq 50); do curl -s -o /dev/null "$BASE/nokey.php" && break; sleep 0.1; done

# expect NAME STATUS [curl args...]: asserts the status and that neither key appears in the body.
expect() {
	local name=$1 want=$2; shift 2
	local body status
	body=$(curl -s -w '\n%{http_code}' "$@")
	status=${body##*$'\n'}
	body=${body%$'\n'*}
	if [ "$status" != "$want" ]; then
		echo "FAIL $name: got $status, want $want"; fails=$((fails + 1))
	elif grep -qF "$CANARY" <<< "$body" || { [ -n "$KEY" ] && grep -qF "$KEY" <<< "$body"; }; then
		echo "FAIL $name: response contains the key"; fails=$((fails + 1))
	else
		echo "ok   $name"
	fi
	LAST=$body
}

SO='Sec-Fetch-Site: same-origin'
OK='{"path":"/location/search","method":"GET","query":{"q":"Paris","limit":1}}'
U="$BASE/roxy.php"

expect "plain GET is not found" 404 "$U"
curl -s -D - -o /dev/null "$U" | grep -qi "^x-content-type-options: nosniff" && echo "ok   responses carry nosniff" || { echo "FAIL responses lack nosniff"; fails=$((fails + 1)); }
expect "foreign Origin refused" 403 -X POST -H 'Origin: https://elsewhere.example' -d "$OK" "$U"
expect "cross-site fetch refused" 403 -X POST -H 'Sec-Fetch-Site: cross-site' -d "$OK" "$U"
expect "no Origin or Referer refused" 403 -X POST -d "$OK" "$U"
expect "foreign Referer on city search refused" 403 -H 'Referer: https://elsewhere.example/' "$U?q=Paris"
expect "lookalike host refused" 403 -X POST -H "Origin: http://127.0.0.1:$PORT.elsewhere.example" -d "$OK" "$U"
expect "host in path refused" 400 -X POST -H "$SO" -d '{"path":"//elsewhere.example/x","method":"GET"}' "$U"
expect "userinfo in path refused" 400 -X POST -H "$SO" -d '{"path":"@elsewhere.example/x","method":"GET"}' "$U"
expect "traversal refused" 400 -X POST -H "$SO" -d '{"path":"/../usage","method":"GET"}' "$U"
expect "encoded traversal refused" 400 -X POST -H "$SO" -d '{"path":"/%2e%2e/usage","method":"GET"}' "$U"
expect "query in path refused" 400 -X POST -H "$SO" -d '{"path":"/a?b=1","method":"GET"}' "$U"
expect "header injection refused" 400 -X POST -H "$SO" -d '{"path":"/a\r\nX-Injected: 1","method":"GET"}' "$U"
expect "missing path refused" 400 -X POST -H "$SO" -d '{"method":"GET"}' "$U"
expect "invalid JSON refused" 400 -X POST -H "$SO" -d 'not json' "$U"
expect "unknown method refused" 405 -X POST -H "$SO" -d '{"path":"/location/search","method":"DELETE"}' "$U"
head -c 70000 /dev/zero | tr '\0' a > "$ROOT/big.txt"
expect "oversized body refused" 400 -X POST -H "$SO" --data-binary "@$ROOT/big.txt" "$U"
expect "check page without the code is locked" 200 "$U?check"
grep -q "PHP" <<< "$LAST" && { echo "FAIL locked check page names PHP"; fails=$((fails + 1)); }
expect "check page with a wrong code is locked" 200 "$U?check=abcdef"
grep -q "last six characters" <<< "$LAST" || { echo "FAIL wrong code did not get the locked page"; fails=$((fails + 1)); }
expect "no key yet answers 503" 503 -X POST -H "$SO" -d "$OK" "$BASE/nokey.php"
expect "same-origin by Origin passes the guard" 503 -X POST -H "Origin: http://127.0.0.1:$PORT" -d "$OK" "$BASE/nokey.php"
expect "same-origin by Referer passes the guard" 503 -H "Referer: http://127.0.0.1:$PORT/page" "$BASE/nokey.php?q=Paris"

if [ "${LIVE:-}" = 1 ]; then
	if [ -z "$KEY" ]; then echo "FAIL LIVE=1 needs ROXY_TEST_KEY"; exit 1; fi
	L="$BASE/live.php"
	expect "check page" 200 "$L?check=${KEY: -6}"
	grep -q '&#10007;' <<< "$LAST" && { echo "FAIL check page has a red line"; fails=$((fails + 1)); }
	grep -q 'Widget files ready' <<< "$LAST" || { echo "FAIL the code did not open the full check page"; fails=$((fails + 1)); }
	expect "widget bundle served from this site" 200 -o /dev/null "$BASE/roxy/cdn/roxy-ui.js"
	expect "form schema served beside it" 200 -o /dev/null "$BASE/roxy/schemas/post--astrology-natal-chart.json"
	expect "Russian labels served" 200 -o /dev/null "$BASE/roxy/cdn/locales/ru.js"
	stray=$(find "$ROOT/roxy" -type f ! -name "*.js" ! -name "*.json" ! -name version.txt | head -3)
	[ -z "$stray" ] || { echo "FAIL widget folder holds files the server could run: $stray"; fails=$((fails + 1)); }
	rm "$ROOT/roxy/version.txt"
	expect "hand-placed widget files count as ready" 200 "$L?check=${KEY: -6}"
	grep -q '&#10007;' <<< "$LAST" && { echo "FAIL check page red with the widget files in place"; fails=$((fails + 1)); }
	[ -f "$ROOT/roxy/version.txt" ] || { echo "FAIL no version stamp after a check over hand-placed files"; fails=$((fails + 1)); }
	expect "city search relayed" 200 -H "Referer: $BASE/page" "$L?q=Berlin&limit=8"
	grep -q '"cities"' <<< "$LAST" || { echo "FAIL city search returned no cities"; fails=$((fails + 1)); }
	expect "city search relayed through the submit route" 200 -X POST -H "$SO" -d '{"path":"/location/search","method":"GET","query":{"q":"Berlin","limit":8}}' "$L"
	grep -q '"cities"' <<< "$LAST" || { echo "FAIL submit-route city search returned no cities"; fails=$((fails + 1)); }
	latest=$(curl -s "https://www.php.net/releases/index.php?json&version=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" | php -r '$f=json_decode(stream_get_contents(STDIN),true);echo $f["version"]??"";')
	behind=$(php -r 'echo version_compare(PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION.".".PHP_RELEASE_VERSION, $argv[1], "<") ? 1 : 0;' "$latest")
	expect "check page in Russian" 200 -H 'Accept-Language: ru' "$L?check=${KEY: -6}"
	if [ "$behind" = 1 ]; then
		grep -q 'class="warn"' <<< "$LAST" && grep -qF "PHP $latest" <<< "$LAST" || { echo "FAIL PHP behind $latest shows no yellow line"; fails=$((fails + 1)); }
		grep -q 'жёлтую строку' <<< "$LAST" || { echo "FAIL yellow-line summary missing"; fails=$((fails + 1)); }
	else
		grep -q 'class="warn"' <<< "$LAST" && { echo "FAIL current PHP shows a yellow line"; fails=$((fails + 1)); }
	fi
	expect "natal chart relayed in Russian" 200 -X POST -H "$SO" -d '{"path":"/astrology/natal-chart","method":"POST","query":{"lang":"ru"},"body":{"date":"1990-07-15","time":"14:30:00","latitude":52.52,"longitude":13.405,"timezone":"Europe/Berlin"}}' "$L"
	grep -q 'Солнце' <<< "$LAST" || { echo "FAIL natal chart is not in Russian"; fails=$((fails + 1)); }
	expect "API errors pass through" 400 -X POST -H "$SO" -d '{"path":"/astrology/natal-chart","method":"POST","body":{}}' "$L"
fi

[ "$fails" = 0 ] && echo "all passed" || echo "$fails failed"
exit $((fails > 0))
