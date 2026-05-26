<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Raw upstream fields from one wp-rest-api-log CPT post, unredacted and unmapped.
 *
 * The Reader produces one SourceEntry per upstream post; the Mapper consumes it.
 * Pure PHP — no $wpdb, no apply_filters, no get_option.
 */
final class SourceEntry {

	/** @var int Upstream post ID — becomes migration_source_id in brl_logs. */
	public int $source_id = 0;

	/** @var string REST route from post_title. */
	public string $route = '';

	/** @var string Response body from post_content (pretty-JSON, already unslashed by Reader). */
	public string $response_body = '';

	/** @var string Raw request body from _request_body meta (already unslashed by Reader). */
	public string $request_body = '';

	/** @var string Request timestamp from post_date (MySQL datetime, local tz). */
	public string $created_at = '';

	/** @var string HTTP method from wp-rest-api-log-method term name (e.g. 'GET'). */
	public string $method = '';

	/** @var int HTTP status code from wp-rest-api-log-status term name (e.g. 200, 404). */
	public int $status = 0;

	/** @var string Client IP from _ip-address meta. */
	public string $ip_address = '';

	/** @var string User identifier from _request_user meta (numeric string or display name). */
	public string $user = '';

	/** @var int Duration in milliseconds from _milliseconds meta. */
	public int $duration_ms = 0;

	/** @var array<string,string> Aggregated request headers from request_headers|{Name} meta rows. */
	public array $request_headers = [];

	/** @var array<string,string> Aggregated response headers from response_headers|{Name} meta rows. */
	public array $response_headers = [];
}
