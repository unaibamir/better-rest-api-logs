<?php
/**
 * Unit tests for BetterRestApiLogs\Capture\Redactor.
 *
 * RED-bar baseline: all tests fail with Class not found until Plan 03-02
 * implements includes/capture/redactor.php. Covers PRIV-01..03, PRIV-05,
 * CAP-07 per the locked requirements in REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Capture;

use BetterRestApiLogs\Capture\Redactor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class RedactorTest extends TestCase {

	public function test_default_header_redact_list_replaces_authorization(): void {
		$redactor = new Redactor();
		$result   = $redactor->redact_headers( [ 'Authorization' => [ 'Bearer xyz' ] ], [] );

		$this->assertArrayHasKey( 'Authorization', $result );
		$this->assertSame( [ '[redacted]' ], $result['Authorization'] );
	}

	public function test_default_header_redact_list_replaces_cookie_and_set_cookie(): void {
		$redactor = new Redactor();
		$result   = $redactor->redact_headers(
			[
				'Cookie'     => [ 'session=abc' ],
				'set-cookie' => [ 'token=xyz' ],
			],
			[]
		);

		$this->assertSame( [ '[redacted]' ], $result['Cookie'] );
		$this->assertSame( [ '[redacted]' ], $result['set-cookie'] );
	}

	public function test_x_api_key_and_proxy_authorization_redacted(): void {
		$redactor = new Redactor();
		$result   = $redactor->redact_headers(
			[
				'x-api-key'           => [ 'my-api-key-value' ],
				'proxy-authorization' => [ 'Basic dXNlcjpwYXNz' ],
			],
			[]
		);

		$this->assertSame( [ '[redacted]' ], $result['x-api-key'] );
		$this->assertSame( [ '[redacted]' ], $result['proxy-authorization'] );
	}

	/**
	 * WP_REST_Request::get_headers() delivers underscored keys (x_api_key,
	 * set_cookie, proxy_authorization). The default list is hyphenated, so a
	 * plain strtolower lookup misses these and leaks secrets in cleartext.
	 */
	public function test_underscored_header_keys_still_redact(): void {
		$redactor = new Redactor();
		$result   = $redactor->redact_headers(
			[
				'x_api_key'           => [ 'APIKEY_SHOULD_BE_REDACTED' ],
				'set_cookie'          => [ 'token=xyz' ],
				'proxy_authorization' => [ 'Basic dXNlcjpwYXNz' ],
			],
			[]
		);

		$this->assertSame( [ '[redacted]' ], $result['x_api_key'] );
		$this->assertSame( [ '[redacted]' ], $result['set_cookie'] );
		$this->assertSame( [ '[redacted]' ], $result['proxy_authorization'] );
	}

	/** User-supplied hyphenated extras must match the underscored delivered key. */
	public function test_user_extra_header_matches_underscored_key(): void {
		$redactor = new Redactor();
		$result   = $redactor->redact_headers(
			[ 'x_session_token' => [ 'hunter2' ] ],
			[ 'x-session-token' ]
		);

		$this->assertSame( [ '[redacted]' ], $result['x_session_token'] );
	}

	public function test_canonical_header_key_folds_case_and_separators(): void {
		$this->assertSame( 'x_api_key', Redactor::canonical_header_key( 'X-Api-Key' ) );
		$this->assertSame( 'x_api_key', Redactor::canonical_header_key( 'x_api_key' ) );
		$this->assertSame( 'content_type', Redactor::canonical_header_key( 'Content-Type' ) );
	}

	/** PRIV-03: user extras extend, never replace, the default list. */
	public function test_user_extras_merge_with_defaults_no_opt_out(): void {
		$redactor = new Redactor();
		$result   = $redactor->redact_headers(
			[
				'Authorization'  => [ 'Bearer secret' ],
				'x-custom-token' => [ 'hunter2' ],
				'Content-Type'   => [ 'application/json' ],
			],
			[ 'x-custom-token' ]
		);

		$this->assertSame( [ '[redacted]' ], $result['Authorization'] );
		$this->assertSame( [ '[redacted]' ], $result['x-custom-token'] );
		$this->assertSame( [ 'application/json' ], $result['Content-Type'] );
	}

	public function test_unredacted_header_passes_through_untouched(): void {
		$redactor = new Redactor();
		$result   = $redactor->redact_headers(
			[ 'Content-Type' => [ 'application/json' ] ],
			[]
		);

		$this->assertSame( [ 'application/json' ], $result['Content-Type'] );
	}

	public function test_redact_json_body_recursive_walks_password_keys(): void {
		$redactor = new Redactor();
		$input    = '{"user":{"password":"x","name":"y"},"meta":{"token":"t"}}';
		$result   = $redactor->redact_json_body( $input );
		$decoded  = json_decode( $result, true );

		$this->assertIsArray( $decoded );
		$this->assertSame( '[redacted]', $decoded['user']['password'] );
		$this->assertSame( '[redacted]', $decoded['meta']['token'] );
		$this->assertSame( 'y', $decoded['user']['name'] );
	}

	public function test_redact_json_body_handles_non_string_leaf(): void {
		$redactor = new Redactor();
		$input    = '{"password":12345,"secret":true,"ssn":null}';
		$result   = $redactor->redact_json_body( $input );
		$decoded  = json_decode( $result, true );

		$this->assertIsArray( $decoded );
		$this->assertSame( '[redacted]', $decoded['password'] );
		$this->assertSame( '[redacted]', $decoded['secret'] );
		$this->assertSame( '[redacted]', $decoded['ssn'] );
	}

	public function test_redact_json_body_falls_back_to_regex_on_parse_failure(): void {
		$redactor = new Redactor();
		// Deliberately malformed JSON triggers the regex fallback path.
		$input  = '{not valid json "password":"abc"}';
		$result = $redactor->redact_json_body( $input );

		$this->assertStringContainsString( '[redacted]', $result );
		$this->assertStringContainsString( 'password', $result );
	}

	public function test_redact_json_body_extra_pattern_extends_regex(): void {
		$redactor = new Redactor();
		$input    = '{"private_data":"sensitive"}';
		$result   = $redactor->redact_json_body( $input, 'private_data' );

		$this->assertStringContainsString( '[redacted]', $result );
	}

	public function test_redact_form_body_parses_and_redacts(): void {
		$redactor = new Redactor();
		$input    = 'password=abc&name=joe';
		$result   = $redactor->redact_form_body( $input );

		// Round-trip through parse_str to be PHP-version-agnostic on urlencoding.
		parse_str( $result, $parsed );
		$this->assertSame( '[redacted]', $parsed['password'] );
		$this->assertSame( 'joe', $parsed['name'] );
	}

	public function test_anonymize_ipv4_zeros_last_octet(): void {
		$redactor = new Redactor();
		$packed   = \inet_pton( '::ffff:1.2.3.4' );
		if ( false === $packed ) {
			$this->markTestSkipped( 'inet_pton unavailable.' );
		}
		$result = $redactor->anonymize_ip( $packed );

		$this->assertNotNull( $result );
		$this->assertSame( 16, \strlen( $result ) );
		$this->assertSame( "\x00", $result[15] );
		$this->assertSame( "\x01", $result[12] );
		$this->assertSame( "\x02", $result[13] );
		$this->assertSame( "\x03", $result[14] );
	}

	public function test_anonymize_ipv6_zeros_last_80_bits(): void {
		$redactor = new Redactor();
		$packed   = \inet_pton( '2001:db8::1' );
		if ( false === $packed ) {
			$this->markTestSkipped( 'inet_pton unavailable.' );
		}
		$result = $redactor->anonymize_ip( $packed );

		$this->assertNotNull( $result );
		$this->assertSame( 16, \strlen( $result ) );
		for ( $i = 6; $i < 16; $i++ ) {
			$this->assertSame( "\x00", $result[ $i ], "Byte $i must be zeroed." );
		}
		$this->assertSame( "\x20", $result[0] );
		$this->assertSame( "\x01", $result[1] );
	}

	public function test_anonymize_ip_returns_null_for_null_input(): void {
		$redactor = new Redactor();
		$this->assertNull( $redactor->anonymize_ip( null ) );
	}

	public function test_content_type_outside_allowlist_drops_body(): void {
		$redactor = new Redactor();
		$this->assertFalse( $redactor->should_capture_body( 'image/png', [] ) );
	}

	public function test_content_type_application_json_within_allowlist(): void {
		$redactor = new Redactor();
		$this->assertTrue( $redactor->should_capture_body( 'application/json; charset=utf-8', [] ) );
	}

	/** PRIV-01: the five default headers ship as a class constant. */
	public function test_content_type_class_constant_default_header_list_populated(): void {
		$list = Redactor::DEFAULT_HEADER_REDACT_LIST;
		$this->assertContains( 'authorization', $list );
		$this->assertContains( 'cookie', $list );
		$this->assertContains( 'set-cookie', $list );
		$this->assertContains( 'x-api-key', $list );
		$this->assertContains( 'proxy-authorization', $list );
	}
}
