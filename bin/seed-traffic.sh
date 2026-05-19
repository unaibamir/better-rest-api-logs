#!/usr/bin/env bash
#
# seed-traffic.sh — Generate a varied WP REST API workload for log testing.
#
# Logs in via wp-login.php (cookie auth), fetches an X-WP-Nonce from
# /wp-admin/admin-ajax.php?action=rest-nonce, then fires a battery of
# requests across HTTP methods, body content types, and expected status
# codes so logging plugins (Better REST API Logs, wp-rest-api-log, etc.)
# have realistic traffic to chew on.
#
# Usage:
#   bin/seed-traffic.sh --base-url https://site.test --user admin --pass secret
#
# Flags:
#   --base-url URL    Site URL (no trailing slash). Required.
#   --user NAME       WP username for the authenticated battery. Default: admin.
#   --pass PASSWORD   WP password. Required unless --skip-auth.
#   --rounds N        Repeat the whole battery N times. Default 1.
#   --skip-auth       Only fire anonymous calls (no login, no nonce).
#   --skip-anon       Skip the anonymous battery (auth-only).
#   --verbose         Print response bodies for each call.
#   --insecure        Pass curl -k (self-signed certs / local DDEV).
#   --help            Show this help.
#
# Exit codes:
#   0 — battery finished (individual call failures are reported but tolerated).
#   1 — argument or precondition error.
#   2 — login failed; no further calls were attempted.

set -euo pipefail

# ───────────────────────── defaults ─────────────────────────
BASE_URL=""
USERNAME="admin"
PASSWORD=""
ROUNDS=1
SKIP_AUTH=false
SKIP_ANON=false
VERBOSE=false
INSECURE=false

# ───────────────────────── argv ─────────────────────────
print_help() {
	sed -n '2,30p' "$0"
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--base-url)   BASE_URL="${2:-}";  shift 2 ;;
		--user)       USERNAME="${2:-}";  shift 2 ;;
		--pass)       PASSWORD="${2:-}";  shift 2 ;;
		--rounds)     ROUNDS="${2:-1}";   shift 2 ;;
		--skip-auth)  SKIP_AUTH=true;     shift ;;
		--skip-anon)  SKIP_ANON=true;     shift ;;
		--verbose)    VERBOSE=true;       shift ;;
		--insecure)   INSECURE=true;      shift ;;
		-h|--help)    print_help; exit 0 ;;
		*)
			printf 'unknown argument: %s\n' "$1" >&2
			print_help >&2
			exit 1
			;;
	esac
done

if [[ -z "$BASE_URL" ]]; then
	echo "error: --base-url is required" >&2
	exit 1
fi
if [[ "$SKIP_AUTH" == false && -z "$PASSWORD" ]]; then
	echo "error: --pass is required (or pass --skip-auth)" >&2
	exit 1
fi
if ! [[ "$ROUNDS" =~ ^[0-9]+$ ]] || [[ "$ROUNDS" -lt 1 ]]; then
	echo "error: --rounds must be a positive integer" >&2
	exit 1
fi

BASE_URL="${BASE_URL%/}"  # strip trailing slash
COOKIE_JAR="$(mktemp -t brl-seed.XXXXXX)"
trap 'rm -f "$COOKIE_JAR"' EXIT

CURL_BASE=(--silent --show-error --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR")
[[ "$INSECURE" == true ]] && CURL_BASE+=(--insecure)

# Counters for the summary line.
TOTAL=0
PASSED=0
FAILED=0
NONCE=""
LAST_CODE=""  # Status code from the last call() — read after the call returns.

# ───────────────────────── helpers ─────────────────────────

# call METHOD URL EXPECTED_STATUS [curl_extra_args...]
#
# Fires a single request, compares the HTTP status against EXPECTED_STATUS
# (use 0 to skip the check), prints one summary line, and increments the
# counters. EXPECTED_STATUS may be a comma-separated list of acceptable codes
# (e.g. "200,404") for endpoints that legitimately vary by site state.
call() {
	local method="$1"; shift
	local url="$1";    shift
	local expect="$1"; shift

	TOTAL=$((TOTAL + 1))

	local body_file http_code
	body_file="$(mktemp -t brl-seed-body.XXXXXX)"

	http_code=$(
		curl "${CURL_BASE[@]}" \
			-o "$body_file" \
			-w '%{http_code}' \
			-X "$method" \
			"$@" \
			"$url" \
			|| echo 000
	)

	local marker="✓"
	if [[ "$expect" != "0" ]]; then
		if ! grep -qE "(^|,)${http_code}(,|$)" <<<"$expect"; then
			marker="✗"
			FAILED=$((FAILED + 1))
		else
			PASSED=$((PASSED + 1))
		fi
	else
		PASSED=$((PASSED + 1))
	fi

	printf '  %s %-7s %-45s → %3s (expected %s)\n' \
		"$marker" "$method" "${url#"$BASE_URL"}" "$http_code" "$expect"

	if [[ "$VERBOSE" == true ]]; then
		local preview
		preview=$(head -c 240 "$body_file" | tr '\n' ' ' | tr -s ' ')
		printf '        body: %s\n' "$preview"
	fi

	rm -f "$body_file"
	LAST_CODE="$http_code"
}

login() {
	echo "── Logging in as ${USERNAME} ──"

	# Set the WP testcookie marker so wp-login accepts the form post.
	curl "${CURL_BASE[@]}" \
		--cookie 'wordpress_test_cookie=WP+Cookie+check' \
		-o /dev/null \
		"${BASE_URL}/wp-login.php"

	local code
	code=$(
		curl "${CURL_BASE[@]}" \
			--cookie 'wordpress_test_cookie=WP+Cookie+check' \
			-o /dev/null \
			-w '%{http_code}' \
			-X POST \
			--data-urlencode "log=${USERNAME}" \
			--data-urlencode "pwd=${PASSWORD}" \
			--data-urlencode 'wp-submit=Log In' \
			--data-urlencode "redirect_to=${BASE_URL}/wp-admin/" \
			--data-urlencode 'testcookie=1' \
			"${BASE_URL}/wp-login.php"
	)

	# wp-login.php answers 302 on success (redirect to wp-admin) or 200
	# with the form re-rendered on failure. We also accept 200 because some
	# stacks (DDEV-router with curl --location) follow the 302 to the
	# dashboard before we see a code.
	if [[ "$code" != "302" && "$code" != "200" ]]; then
		echo "✗ Login failed (HTTP ${code})." >&2
		return 1
	fi

	# Confirm the cookie jar got the wordpress_logged_in_* cookie.
	if ! grep -q 'wordpress_logged_in_' "$COOKIE_JAR"; then
		echo "✗ No wordpress_logged_in_* cookie set — credentials probably wrong." >&2
		return 1
	fi

	NONCE=$(
		curl "${CURL_BASE[@]}" \
			"${BASE_URL}/wp-admin/admin-ajax.php?action=rest-nonce"
	)
	if [[ -z "$NONCE" || ${#NONCE} -lt 8 ]]; then
		echo "✗ Could not fetch X-WP-Nonce." >&2
		return 1
	fi

	echo "✓ Authenticated. Nonce: ${NONCE:0:6}…"
}

anon_battery() {
	echo "── Anonymous battery ──"

	# Discovery + namespace metadata.
	call GET     "${BASE_URL}/wp-json"                          200
	call GET     "${BASE_URL}/wp-json/wp/v2"                    200

	# Standard collection reads — public content.
	call GET     "${BASE_URL}/wp-json/wp/v2/posts"              200
	call GET     "${BASE_URL}/wp-json/wp/v2/posts?per_page=3"   200
	call GET     "${BASE_URL}/wp-json/wp/v2/pages"              200
	call GET     "${BASE_URL}/wp-json/wp/v2/categories"         200
	call GET     "${BASE_URL}/wp-json/wp/v2/tags"               200
	call GET     "${BASE_URL}/wp-json/wp/v2/types"              200
	call GET     "${BASE_URL}/wp-json/wp/v2/taxonomies"         200
	call GET     "${BASE_URL}/wp-json/wp/v2/comments"           200
	call GET     "${BASE_URL}/wp-json/wp/v2/search?search=hello" 200

	# Search endpoint with a query string the detail view should surface.
	call GET     "${BASE_URL}/wp-json/wp/v2/posts?search=lorem&orderby=date&order=desc" 200

	# Authenticated-only resources — anonymous gets 401 (settings), but
	# /users returns 200 with a public author list when posts exist.
	call GET     "${BASE_URL}/wp-json/wp/v2/users"              200,401
	call GET     "${BASE_URL}/wp-json/wp/v2/settings"           401

	# Single-item by id — 404 on a guaranteed-missing id.
	call GET     "${BASE_URL}/wp-json/wp/v2/posts/999999"       404
	call GET     "${BASE_URL}/wp-json/wp/v2/pages/999999"       404

	# Unknown route under our namespace — 404.
	call GET     "${BASE_URL}/wp-json/wp/v2/no-such-endpoint"   404

	# Method probes.
	call HEAD    "${BASE_URL}/wp-json/wp/v2/posts"              200 -I
	call OPTIONS "${BASE_URL}/wp-json/wp/v2/posts"              200

	# Write attempts as anonymous — 401.
	call POST    "${BASE_URL}/wp-json/wp/v2/posts"              401 \
		-H 'Content-Type: application/json' \
		--data '{"title":"anon write","status":"draft"}'
}

auth_battery() {
	echo "── Authenticated battery ──"

	local nonce_hdr=(-H "X-WP-Nonce: ${NONCE}")

	# Identity + settings.
	call GET     "${BASE_URL}/wp-json/wp/v2/users/me"  200 "${nonce_hdr[@]}"
	call GET     "${BASE_URL}/wp-json/wp/v2/settings"  200 "${nonce_hdr[@]}"

	# Create a category to attach to the post below.
	local cat_id
	cat_id=$(
		curl "${CURL_BASE[@]}" "${nonce_hdr[@]}" \
			-X POST \
			-H 'Content-Type: application/json' \
			--data "{\"name\":\"seed-$(date +%s)\"}" \
			"${BASE_URL}/wp-json/wp/v2/categories" \
			| sed -nE 's/.*"id":([0-9]+).*/\1/p' \
			| head -n1
	)
	if [[ -n "${cat_id:-}" ]]; then
		TOTAL=$((TOTAL + 1)); PASSED=$((PASSED + 1))
		printf '  ✓ %-7s %-45s → %3s (expected %s)\n' POST '/wp/v2/categories (setup)' 201 201
	fi

	# Create a post via JSON. WP returns 201 with the row.
	local post_id
	post_id=$(
		curl "${CURL_BASE[@]}" "${nonce_hdr[@]}" \
			-X POST \
			-H 'Content-Type: application/json' \
			--data "{
				\"title\":\"seed post $(date +%s)\",
				\"status\":\"draft\",
				\"content\":\"Lorem ipsum from seed-traffic.sh — $(date -u +%FT%TZ).\",
				\"categories\":[${cat_id:-1}]
			}" \
			"${BASE_URL}/wp-json/wp/v2/posts" \
			| sed -nE 's/.*"id":([0-9]+).*/\1/p' \
			| head -n1
	)
	if [[ -n "${post_id:-}" ]]; then
		TOTAL=$((TOTAL + 1)); PASSED=$((PASSED + 1))
		printf '  ✓ %-7s %-45s → %3s (expected %s)\n' POST '/wp/v2/posts (JSON create)' 201 201
	fi

	# Bad-body create: missing required content_type → 400 from REST.
	call POST    "${BASE_URL}/wp-json/wp/v2/posts" 400,415 \
		"${nonce_hdr[@]}" \
		-H 'Content-Type: application/xml' \
		--data '<post><title>nope</title></post>'

	# Form-urlencoded body — WP accepts this for many endpoints.
	call POST    "${BASE_URL}/wp-json/wp/v2/posts" 201,400 \
		"${nonce_hdr[@]}" \
		--data-urlencode "title=form-encoded $(date +%s)" \
		--data-urlencode 'status=draft' \
		--data-urlencode 'content=urlencoded body from seed-traffic.sh'

	# text/plain body — usually rejected (400) but it exercises the content-type path.
	call POST    "${BASE_URL}/wp-json/wp/v2/posts" 400,415 \
		"${nonce_hdr[@]}" \
		-H 'Content-Type: text/plain' \
		--data 'this should not work'

	# Multipart upload — a tiny txt file via wp/v2/media.
	local tmp_file
	tmp_file="$(mktemp -t brl-seed-upload.XXXXXX).txt"
	printf 'seed-traffic %s\n' "$(date -u +%FT%TZ)" >"$tmp_file"
	call POST    "${BASE_URL}/wp-json/wp/v2/media" 201,400 \
		"${nonce_hdr[@]}" \
		-F "file=@${tmp_file};type=text/plain" \
		-F 'title=seed upload'
	rm -f "$tmp_file"

	if [[ -n "${post_id:-}" ]]; then
		# Update the draft we just made — PUT replaces.
		call PUT     "${BASE_URL}/wp-json/wp/v2/posts/${post_id}" 200 \
			"${nonce_hdr[@]}" \
			-H 'Content-Type: application/json' \
			--data '{"title":"seed post (updated)","status":"draft"}'

		# Partial update via POST (WP accepts POST for updates too).
		call POST    "${BASE_URL}/wp-json/wp/v2/posts/${post_id}" 200 \
			"${nonce_hdr[@]}" \
			-H 'Content-Type: application/json' \
			--data '{"excerpt":"seeded excerpt"}'

		# Force-delete so the row goes away.
		call DELETE  "${BASE_URL}/wp-json/wp/v2/posts/${post_id}?force=true" 200 \
			"${nonce_hdr[@]}"
	fi

	# Wrong method on a known route — REST returns 404 (not 405) because the
	# route registers a different handler per method and the missing one
	# never matches. We accept either.
	call DELETE  "${BASE_URL}/wp-json/wp/v2/types" 404,405 "${nonce_hdr[@]}"

	# Bad nonce → 403.
	call POST    "${BASE_URL}/wp-json/wp/v2/posts" 401,403 \
		-H 'X-WP-Nonce: not-a-real-nonce' \
		-H 'Content-Type: application/json' \
		--data '{"title":"forged"}'

	# Plugin's own REST namespace — exercises the controllers under test.
	call GET     "${BASE_URL}/wp-json/better-rest-api-logs/v1/logs?per_page=5" 200,401 "${nonce_hdr[@]}"
	call GET     "${BASE_URL}/wp-json/better-rest-api-logs/v1/stats"           200,401 "${nonce_hdr[@]}"
}

# ───────────────────────── main ─────────────────────────

echo "Target: ${BASE_URL}"
echo

if [[ "$SKIP_AUTH" == false ]]; then
	login || exit 2
	echo
fi

for ((r = 1; r <= ROUNDS; r++)); do
	if [[ "$ROUNDS" -gt 1 ]]; then
		echo "═══ Round ${r}/${ROUNDS} ═══"
	fi

	[[ "$SKIP_ANON" == false ]] && anon_battery && echo
	[[ "$SKIP_AUTH" == false ]] && auth_battery && echo
done

echo "── Summary ──"
echo "  Total:  ${TOTAL}"
echo "  Passed: ${PASSED}"
echo "  Failed: ${FAILED}"

# Don't exit non-zero on mismatched status codes — some of them legitimately
# depend on site fixture state. The caller asked us to seed traffic, not
# enforce contracts.
exit 0
