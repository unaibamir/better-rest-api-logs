# Better REST API Logs — Hook Reference

Every `brl_*` action and filter the plugin fires, with parameters and usage examples.

> **Generated** from `includes/hooks.php` by `composer hooks:gen`.
> Do not edit by hand — changes will be overwritten on the next generation.

---

## Table of Contents

- [Filter: `brl_admin_required_capability`](#brl-admin-required-capability)
- [Action: `brl_after_insert_entry`](#brl-after-insert-entry)
- [Action: `brl_body_spill_failed`](#brl-body-spill-failed)
- [Action: `brl_circuit_armed`](#brl-circuit-armed)
- [Action: `brl_circuit_resumed`](#brl-circuit-resumed)
- [Filter: `brl_export_filename`](#brl-export-filename)
- [Filter: `brl_export_query_args`](#brl-export-query-args)
- [Filter: `brl_list_columns`](#brl-list-columns)
- [Action: `brl_log_deleted`](#brl-log-deleted)
- [Action: `brl_logs_truncated`](#brl-logs-truncated)
- [Filter: `brl_post_redact_body`](#brl-post-redact-body)
- [Filter: `brl_post_redact_headers`](#brl-post-redact-headers)
- [Filter: `brl_pre_insert_entry`](#brl-pre-insert-entry)
- [Filter: `brl_pre_redact_body`](#brl-pre-redact-body)
- [Filter: `brl_pre_redact_headers`](#brl-pre-redact-headers)
- [Action: `brl_purge_completed`](#brl-purge-completed)
- [Filter: `brl_query_args`](#brl-query-args)
- [Filter: `brl_rest_response`](#brl-rest-response)
- [Filter: `brl_should_capture`](#brl-should-capture)
- [Filter: `brl_use_fastcgi_finish_request`](#brl-use-fastcgi-finish-request)

---

## brl_admin_required_capability

**Type:** Filter  
**Since:** 1.0.0

Swaps the default `manage_options` cap for a custom role across every cap
check in the admin UI, REST controllers, and WP-CLI verbs. The $context arg
lets you distinguish surface areas when different roles need different access.

**Parameters:**

- `string $cap     Default 'manage_options'.`
- `string $context One of 'admin' | 'rest' | 'cli'.`

**Example:**

```php
add_filter(
    'brl_admin_required_capability',
    static function ( $cap, $ctx ) {
        // Let editors view logs via the REST API without giving admin access.
        return 'rest' === $ctx ? 'edit_posts' : $cap;
    },
    10,
    2
);
```

---

## brl_after_insert_entry

**Type:** Action  
**Since:** 1.0.0

Fires once per successfully inserted log row, right after the INSERT commits.
Use it to relay log data to an external queue, trigger a webhook, or update
a counter table. The $entry_data array is the same shape as brl_pre_insert_entry.

**Parameters:**

- `int                 $log_id     Primary key of the new wp_brl_logs row.`
- `array<string,mixed> $entry_data Column values that were written.`

**Example:**

```php
add_action(
    'brl_after_insert_entry',
    static function ( $log_id, $entry_data ) {
        // Enqueue async relay to a webhook.
        wp_schedule_single_event( time(), 'my_relay_hook', [ $log_id ] );
    },
    10,
    2
);
```

---

## brl_body_spill_failed

**Type:** Action  
**Since:** 1.0.0

Fires when a body-spill secondary INSERT fails after the parent wp_brl_logs
row already landed. The log row exists but its request/response bodies are
missing from wp_brl_log_bodies. Use this hook to alert or clean up.

**Parameters:**

- `int $log_id Primary key of the wp_brl_logs row whose body insert failed.`

**Example:**

```php
add_action(
    'brl_body_spill_failed',
    static function ( $log_id ) {
        error_log( "brl: body spill failed for log #{$log_id}" );
    }
);
```

---

## brl_circuit_armed

**Type:** Action  
**Since:** 1.0.0

Fires when the circuit breaker trips open after too many consecutive INSERT
failures. Use it to send an admin alert or write to a fallback log file.
The breaker stays open until the first successful write after the cooldown.

**Parameters:**

- `string $reason         Human-readable failure description.`
- `int    $opens_until_ts Unix timestamp after which the breaker will attempt to close.`

**Example:**

```php
add_action(
    'brl_circuit_armed',
    static function ( $reason, $opens_until_ts ) {
        wp_mail(
            get_option( 'admin_email' ),
            'REST log storage paused',
            "Circuit breaker opened: {$reason}. Retries after " . date( 'c', $opens_until_ts )
        );
    },
    10,
    2
);
```

---

## brl_circuit_resumed

**Type:** Action  
**Since:** 1.0.0

Fires on the first successful INSERT after the circuit breaker was open. By
this point log rows are flowing again. Pair with brl_circuit_armed to send a
recovery notification.

**Parameters:**

- `int $resumed_at_ts Unix timestamp of the recovery moment.`

**Example:**

```php
add_action(
    'brl_circuit_resumed',
    static function ( $resumed_at_ts ) {
        wp_mail(
            get_option( 'admin_email' ),
            'REST log storage recovered',
            'Log writes resumed at ' . date( 'c', $resumed_at_ts )
        );
    }
);
```

---

## brl_export_filename

**Type:** Filter  
**Since:** 1.0.0

Override the generated download filename before headers are sent. Receives
the default name (e.g. `brl-export-2026-05-26.csv`) and the requested
format so you can adjust the name by format or add a site-specific prefix.
Return a non-string to keep the default.

**Parameters:**

- `string $name   Default filename including extension.`
- `string $format Export format: 'csv' or 'ndjson'.`

**Example:**

```php
add_filter(
    'brl_export_filename',
    static function ( $name, $format ) {
        // Prefix every download with the site slug.
        $slug = sanitize_title( get_bloginfo( 'name' ) );
        return "{$slug}-{$name}";
    },
    10,
    2
);
```

---

## brl_export_query_args

**Type:** Filter  
**Since:** 1.0.0

Mutate the resolved QueryArgs object before the export cursor walk begins.
Fires from all three export surfaces (admin bulk action, REST one-shot URL,
and WP-CLI). Use $surface to apply surface-specific restrictions, or
override the effective filter set regardless of what the caller requested.

**Parameters:**

- `\BetterRestApiLogs\DB\Query\QueryArgs $args    Validated filter set for this export.`
- `string                                $surface One of 'admin' | 'rest' | 'cli'.`

**Example:**

```php
add_filter(
    'brl_export_query_args',
    static function ( $args, $surface ) {
        // REST exports may only see the current user's own requests.
        if ( 'rest' === $surface ) {
            $args->user_id = get_current_user_id();
        }
        return $args;
    },
    10,
    2
);
```

---

## brl_list_columns

**Type:** Filter  
**Since:** 1.0.0

Lets extensions add, remove, or reorder columns on the admin list table.
The returned array is passed straight to WP_List_Table::get_columns(); keep
the 'cb' key if you want bulk-action checkboxes to remain.

**Parameters:**

- `array<string,string> $columns Column slug => label map (WP_List_Table format).`

**Example:**

```php
add_filter(
    'brl_list_columns',
    static function ( $cols ) {
        $cols['my_plugin_col'] = 'My Plugin';
        return $cols;
    }
);
```

---

## brl_log_deleted

**Type:** Action  
**Since:** 1.0.0

Fires after a successful delete from any surface (admin UI, REST API, WP-CLI).

**Parameters:**

- `int    $log_id  The deleted row's primary key.`
- `string $surface One of 'admin' | 'rest' | 'cli'.`

**Example:**

```php
add_action(
    'brl_log_deleted',
    static function ( $log_id, $surface ) {
        // Invalidate a related cache keyed on the log ID.
        wp_cache_delete( "brl_detail_{$log_id}" );
    },
    10,
    2
);
```

---

## brl_logs_truncated

**Type:** Action  
**Since:** 1.0.0

Fires after a truncate-all operation commits its DELETE — either from the
Settings → Retention tab "Truncate all logs" confirmation flow or from the
`wp better-logs truncate` CLI verb. Not fired when an individual log row is
deleted (use brl_log_deleted for that).

**Parameters:**

- `int $count Number of log rows deleted by the truncate operation.`

**Example:**

```php
add_action(
    'brl_logs_truncated',
    static function ( $count ) {
        // Notify Slack when a large bulk truncate clears the table.
        if ( $count > 1000 ) {
            my_plugin_notify_slack( "Truncated {$count} REST log rows." );
        }
    }
);
```

---

## brl_post_redact_body

**Type:** Filter  
**Since:** 1.0.0

Fires after the built-in body redactor has run. Both the redacted body and the
pre-redaction body are available, so you can audit what changed or apply a
second pass. Returning a non-string falls back to an empty string.

**Parameters:**

- `string              $redacted_body  Body after built-in redaction.`
- `string              $original_body  Body before redaction (post brl_pre_redact_body).`
- `array<string,mixed> $context        { route: string, direction: 'request'|'response' }`

**Example:**

```php
add_filter(
    'brl_post_redact_body',
    static function ( $redacted, $original, $context ) {
        // Truncate to 500 chars after redaction for a specific noisy route.
        if ( '/my-plugin/v1/bulkimport' === $context['route'] ) {
            return substr( $redacted, 0, 500 );
        }
        return $redacted;
    },
    10,
    3
);
```

---

## brl_post_redact_headers

**Type:** Filter  
**Since:** 1.0.0

Fires after the built-in header redactor has run. The redacted map is the
first argument; the original pre-redaction map is the second, in case you need
to compare what changed. A non-array return falls back to an empty map.

**Parameters:**

- `array<string,mixed> $redacted_headers  Headers after built-in redaction.`
- `array<string,mixed> $original_headers  Headers before redaction.`
- `array<string,mixed> $context           { route: string, direction: 'request'|'response' }`

**Example:**

```php
add_filter(
    'brl_post_redact_headers',
    static function ( $redacted, $original, $context ) {
        // Keep the first two chars of Authorization so you can tell bearer from basic.
        if ( isset( $original['authorization'] ) ) {
            $redacted['authorization'] = substr( $original['authorization'], 0, 2 ) . '***';
        }
        return $redacted;
    },
    10,
    3
);
```

---

## brl_pre_insert_entry

**Type:** Filter  
**Since:** 1.0.0

Last chance to mutate or drop a log entry before it reaches the database.
The entry data array mirrors the columns in wp_brl_logs — you can add
extra fields, but the INSERT will ignore any column it doesn't know.
Return an empty array to drop the entry entirely; it won't be written.

Fires AFTER redaction — listeners see scrubbed data, never raw credentials.

**Parameters:**

- `array<string,mixed>                        $entry_data Assembled column values for the row.`
- `\BetterRestApiLogs\Domain\RequestSnapshot  $req        Request snapshot (post-redaction).`
- `\BetterRestApiLogs\Domain\ResponseSnapshot $res        Response snapshot (post-redaction).`

**Example:**

```php
add_filter(
    'brl_pre_insert_entry',
    static function ( $entry, $req, $res ) {
        // Drop any 2xx response to /wp/v2/users from the log.
        if ( '/wp/v2/users' === $req->route && 2 === $res->status_class ) {
            return [];
        }
        return $entry;
    },
    10,
    3
);
```

---

## brl_pre_redact_body

**Type:** Filter  
**Since:** 1.0.0

Fires before the built-in body redactor runs. The full raw body string is
passed so you can remove anything sensitive before the pattern-based scrubber
takes a pass. Returning a non-string falls back to an empty string.

**Parameters:**

- `string              $body    Raw body before redaction.`
- `array<string,mixed> $context { route: string, direction: 'request'|'response' }`

**Example:**

```php
add_filter(
    'brl_pre_redact_body',
    static function ( $body, $context ) {
        // Drop SSN-shaped values before anything else sees them.
        return preg_replace( '/\b\d{3}-\d{2}-\d{4}\b/', '[ssn]', $body );
    },
    10,
    2
);
```

---

## brl_pre_redact_headers

**Type:** Filter  
**Since:** 1.0.0

Fires before the built-in header redactor runs, giving you a chance to strip
or replace values the plugin doesn't know about. Returning a non-array falls
back to an empty map — don't return null or a scalar.

**Parameters:**

- `array<string,mixed> $headers Raw headers map (lower-cased keys).`
- `array<string,mixed> $context { route: string, direction: 'request'|'response' }`

**Example:**

```php
add_filter(
    'brl_pre_redact_headers',
    static function ( $headers, $context ) {
        // Wipe internal tracing headers before the log sees them.
        unset( $headers['x-internal-trace-id'] );
        return $headers;
    },
    10,
    2
);
```

---

## brl_purge_completed

**Type:** Action  
**Since:** 1.0.0

Fires at the end of each purge tick after the batch delete commits.
Use it to update a dashboard widget, send an alert when a large number of
rows are deleted, or chain custom clean-up work that depends on the purge.
Not fired when the tick bails early on the transient lock or on a
keep-forever site (retention_days = 0).

**Parameters:**

- `int  $deleted      Number of log rows deleted in this batch.`
- `bool $more_pending True when the batch was full and a follow-up tick`

**Example:**

```php
add_action(
    'brl_purge_completed',
    static function ( $deleted, $more_pending ) {
        if ( $deleted > 500 ) {
            // Large purge — surface a Slack alert.
            my_plugin_notify_slack( "Purged {$deleted} REST log rows." );
        }
    },
    10,
    2
);
```

---

## brl_query_args

**Type:** Filter  
**Since:** 1.0.0

Lets extensions mutate the validated QueryArgs object before it reaches
LogRepository::search. Fires from the Admin list screen, the REST list
endpoint, and the WP-CLI list command.

**Parameters:**

- `\BetterRestApiLogs\DB\Query\QueryArgs $args    The validated filter set.`
- `string                                $surface One of 'admin' | 'rest' | 'cli'.`

**Example:**

```php
add_filter(
    'brl_query_args',
    static function ( $args, $surface ) {
        if ( 'rest' === $surface && current_user_can( 'edit_posts' ) ) {
            $args->user_id = get_current_user_id();
        }
        return $args;
    },
    10,
    2
);
```

---

## brl_rest_response

**Type:** Filter  
**Since:** 1.0.0

Last-word filter on every REST response payload. Lets extensions add
fields without forking a controller.

**Parameters:**

- `array<string,mixed> $payload The response body.`
- `string              $route   The matched route slug (e.g. '/logs', '/stats').`
- `\WP_REST_Request    $request The originating request.`

**Example:**

```php
add_filter(
    'brl_rest_response',
    static function ( $payload, $route ) {
        if ( '/stats' === $route ) {
            $payload['my_extra_field'] = 'custom value';
        }
        return $payload;
    },
    10,
    2
);
```

---

## brl_should_capture

**Type:** Filter  
**Since:** 1.0.0

Last word at the pre-dispatch stage — return false to skip capture for this
request even when the route/method/CIDR settings would allow it. Return true
to force capture even when a prior filter denied it. Runs inside a try/catch
Throwable, so a crashing listener never surfaces to the REST client.

**Parameters:**

- `bool             $capture Whether to capture. Defaults to true unless`
- `\WP_REST_Request $request The incoming REST request.`

**Example:**

```php
add_filter(
    'brl_should_capture',
    static function ( $capture, $request ) {
        // Skip order endpoints — they carry too much customer data.
        if ( str_starts_with( $request->get_route(), '/wc/v3/orders' ) ) {
            return false;
        }
        return $capture;
    },
    10,
    2
);
```

---

## brl_use_fastcgi_finish_request

**Type:** Filter  
**Since:** 1.0.1

Controls whether the shutdown flusher calls fastcgi_finish_request() to close
the response before draining the queue. The default is true unless Query
Monitor is loaded (class QM or constant QM_DISABLED is present), in which
case the plugin auto-disables it so QM's shutdown-time HTML injection still
reaches the browser. Return false to keep the connection open for any other
debug or profiling tool that writes during shutdown.

**Parameters:**

- `bool $use Whether to call fastcgi_finish_request(). True by default,`

**Example:**

```php
// Keep the response open when New Relic's RUM injector is running.
add_filter(
    'brl_use_fastcgi_finish_request',
    static function ( $use ) {
        return $use && ! function_exists( 'newrelic_get_browser_timing_header' );
    }
);
```

---
