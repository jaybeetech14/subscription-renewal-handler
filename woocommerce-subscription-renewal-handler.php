<?php
/**
 * WooCommerce Subscription Renewal Handler
 * By Jason Bearthes
 *
 * Handles TWO things on every subscription renewal:
 *   1. Grant download access to the monthly design file
 *   2. Award store credit based on subscription tier
 *
 * CONFIGURATION (example product IDs — replace with your own):
 *   114176 = Basic Subscription    ($12 credit / month, 1 download)
 *   114180 = Premium Subscription  ($24 credit / month, 3 downloads)
 *   114181 = VIP Subscription      ($30 credit / month, 5 downloads)
 *
 * Last updated: October 14, 2026
 *
 * CHANGELOG:
 *   October 14, 2026 - Fixed all downloads disappearing from the Downloads tab.
 *                      Two PHP 8.2 bugs were breaking the entire downloads list:
 *                      (1) the download sorter called intval() on
 *                      $item['order_id'] without checking the key exists first.
 *                      PHP 8.2 treats undefined array keys as fatal deprecation
 *                      warnings that corrupt page output, breaking every download
 *                      link on the page — not just subscription ones. Fixed with
 *                      isset() guards and a null-coalescing fallback.
 *                      (2) Injected download arrays were missing access_expires_formatted,
 *                      a key WooCommerce's downloads template reads directly. PHP 8.2
 *                      threw an undefined key warning on every injected item. Fixed
 *                      by formatting the value from access_expires before injecting.
 *
 *   May 21, 2026     - Fixed subscription downloads not appearing in the My Account
 *                      Downloads tab. With HPOS and sync-on-read disabled, WooCommerce's
 *                      Downloads tab query joins against wp_posts for order status —
 *                      new renewal orders only exist in the HPOS tables, so the join
 *                      finds nothing. Fixed via woocommerce_customer_available_downloads
 *                      filter that queries permissions directly and injects them.
 *                      Added stale permission cleanup: before granting new renewal
 *                      access, any existing permissions for the same customer + product
 *                      whose download ID is no longer on the product are deleted, so
 *                      only the current month's file is ever accessible.
 *                      Fixed subscription downloads not showing in the Downloads section
 *                      on the order details page via woocommerce_order_get_downloadable_items
 *                      filter that injects the current active permission on any order
 *                      containing a subscription product.
 *                      Added sort filter on woocommerce_customer_available_downloads
 *                      at priority 99 to return downloads newest-first by order_id.
 *                      Root cause identified: HPOS is enabled with sync-on-read disabled.
 *                      wcs_order_contains_renewal() reads from the legacy posts meta
 *                      table which is no longer synced, returning false on every renewal.
 *                      Replaced all wcs_order_contains_renewal() calls with
 *                      jbt_order_is_renewal(), an HPOS-native helper that reads
 *                      _subscription_renewal meta directly from the HPOS order tables.
 *                      Switched all logging to jbt_log() writing to wp-content/jbt-debug.log.
 *                      Added woocommerce_payment_complete as a third hook entry point.
 *                      Replaced the admin URL grant tool with a WooCommerce order action
 *                      (Order Actions dropdown → "Grant renewal downloads & credit").
 *
 *   April 26, 2026   - Fixed download grant silently failing on renewal orders.
 *                      wc_downloadable_product_permissions() bails when order status is
 *                      'processing' and woocommerce_downloads_grant_access_after_payment
 *                      is 'no'. Replaced with direct WC_Customer_Download implementation
 *                      that has no order-status dependency. Fixed jbt_grant_download_access()
 *                      always returning true regardless of success, which caused the
 *                      _renewal_download_granted meta flag to be set on a failed grant,
 *                      blocking the fallback hook from retrying.
 */

/**
 * ============================================================
 * CONFIGURATION
 * ============================================================
 */

define( 'JBT_SUBSCRIPTION_CREDITS', array(
    114176 => 12,  // Basic Subscription    - $12 store credit / month
    114180 => 24,  // Premium Subscription  - $24 store credit / month
    114181 => 30,  // VIP Subscription      - $30 store credit / month
) );

define( 'JBT_SUBSCRIPTION_DOWNLOAD_LIMITS', array(
    114176 => 1,   // Basic   - 1 download per month
    114180 => 3,   // Premium - 3 downloads per month
    114181 => 5,   // VIP     - 5 downloads per month
) );

/**
 * ============================================================
 * LOGGER
 *
 * Writes to wp-content/jbt-debug.log with a UTC timestamp.
 * Unlike error_log(), this file is always in a known location
 * and is not affected by server PHP error_log configuration.
 * ============================================================
 */
function jbt_log( $message ) {
    $log_file  = WP_CONTENT_DIR . '/jbt-debug.log';
    $timestamp = gmdate( '[d-M-Y H:i:s' ) . ' UTC]';
    file_put_contents( $log_file, "$timestamp $message\n", FILE_APPEND | LOCK_EX );
}

/**
 * ============================================================
 * HPOS-SAFE RENEWAL DETECTION
 *
 * wcs_order_contains_renewal() reads from the legacy posts meta
 * table. With HPOS enabled and sync-on-read disabled, that table
 * is no longer kept up to date, so the function returns false on
 * every renewal order regardless of what WCS actually stored.
 *
 * This helper reads _subscription_renewal directly from the HPOS
 * order meta tables via $order->get_meta(), which is always
 * authoritative when HPOS is the active storage backend. Falls
 * back to wcs_order_contains_renewal() as a secondary check so
 * it also works correctly on non-HPOS sites.
 * ============================================================
 */
function jbt_order_is_renewal( $order ) {
    if ( ! $order ) {
        return false;
    }

    // Primary: read directly from HPOS order meta — always accurate
    // regardless of whether the posts table is being synced.
    // WCS stores the parent subscription ID in _subscription_renewal
    // on every renewal order it creates.
    if ( ! empty( $order->get_meta( '_subscription_renewal', true ) ) ) {
        jbt_log( 'JBT: jbt_order_is_renewal() — confirmed via _subscription_renewal meta (HPOS-native)' );
        return true;
    }

    // Secondary: WCS function, works on non-HPOS or fully-synced HPOS sites
    if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
        jbt_log( 'JBT: jbt_order_is_renewal() — confirmed via wcs_order_contains_renewal()' );
        return true;
    }

    return false;
}

/**
 * ============================================================
 * HELPER: Award store credit via direct database insert
 *
 * Bypasses Store_Credit_Entry object validation entirely.
 * Writes directly to the acfw_store_credits table using the
 * exact same column structure confirmed from Store_Credit_Entry.php
 * _create() method. Fires acfw_store_credits_total_changed action
 * afterward so the plugin recalculates the customer balance cache.
 * ============================================================
 */
function jbt_award_store_credit( $customer_id, $amount, $note, $order_id = 0 ) {
    if ( ! $customer_id || $amount <= 0 ) {
        jbt_log( "JBT: jbt_award_store_credit() called with invalid params. Customer: $customer_id, Amount: $amount" );
        return false;
    }

    global $wpdb;

    $table        = $wpdb->prefix . 'acfw_store_credits';
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table;

    if ( ! $table_exists ) {
        jbt_log( "JBT: Store credits table '$table' does not exist - trying unprefixed variant." );
        $table        = 'acfw_store_credits';
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table;
        if ( ! $table_exists ) {
            jbt_log( 'JBT: Store credits table not found at all. Cannot award credit.' );
            return false;
        }
    }

    jbt_log( "JBT: Inserting store credit into table: $table" );

    $result = $wpdb->insert(
        $table,
        array(
            'user_id'      => intval( $customer_id ),
            'entry_date'   => current_time( 'mysql', true ),
            'entry_type'   => 'increase',
            'entry_action' => 'admin_adjustment',
            'entry_amount' => floatval( $amount ),
            'object_id'    => intval( $order_id ),
            'entry_note'   => sanitize_text_field( $note ),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
    );

    jbt_log( 'JBT: wpdb->insert result: ' . var_export( $result, true ) );
    jbt_log( 'JBT: wpdb->last_error: ' . $wpdb->last_error );
    jbt_log( 'JBT: wpdb->insert_id: ' . $wpdb->insert_id );

    if ( $result === false ) {
        jbt_log( "JBT: Direct DB insert FAILED for customer $customer_id. Error: " . $wpdb->last_error );
        return false;
    }

    do_action( 'acfw_store_credits_total_changed', null );

    jbt_log( "JBT: Credit awarded - \$$amount to customer $customer_id for order $order_id. Insert ID: " . $wpdb->insert_id );
    return true;
}

/**
 * ============================================================
 * HELPER: Revoke stale download permissions
 *
 * Deletes all permissions for a given customer + product where
 * the download ID is no longer attached to the product. Called
 * before each renewal grant so that only the current month's
 * file remains accessible and previous months' files are revoked.
 * ============================================================
 */
function jbt_revoke_stale_download_permissions( $customer_id, $product_id, $current_download_ids ) {
    if ( empty( $current_download_ids ) ) {
        return;
    }

    global $wpdb;
    $permissions_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

    $placeholders = implode( ', ', array_fill( 0, count( $current_download_ids ), '%s' ) );
    $query_args   = array_merge( array( $customer_id, $product_id ), $current_download_ids );

    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$permissions_table}
              WHERE user_id    = %d
                AND product_id = %d
                AND download_id NOT IN ($placeholders)",
            $query_args
        )
    );

    if ( $deleted > 0 ) {
        jbt_log( "JBT: Revoked $deleted stale permission(s) for customer $customer_id on product $product_id (file no longer on product)." );
    }
}

/**
 * ============================================================
 * HELPER: Build a complete download array item
 *
 * Returns an array with every key WooCommerce's downloads
 * template reads, including access_expires_formatted which PHP 8.2
 * will throw an undefined-key warning on if it is missing.
 * ============================================================
 */
function jbt_build_download_item( $perm, $product, $customer_id ) {
    $product_downloads = $product->get_downloads();

    if ( ! isset( $product_downloads[ $perm->download_id ] ) ) {
        return null;
    }

    $download_file = $product_downloads[ $perm->download_id ];
    $file_name     = $download_file->get_name();

    // Format the expiry date the same way WooCommerce does internally
    $access_expires_formatted = '';
    if ( ! empty( $perm->access_expires ) ) {
        try {
            $date = new WC_DateTime( $perm->access_expires, new DateTimeZone( 'UTC' ) );
            $access_expires_formatted = $date->date_i18n( get_option( 'date_format' ) );
        } catch ( Exception $e ) {
            $access_expires_formatted = '';
        }
    }

    $download_url = add_query_arg(
        array(
            'download_file' => $perm->product_id,
            'order'         => $perm->order_key,
            'uid'           => $customer_id,
            'key'           => $perm->download_id,
        ),
        trailingslashit( home_url() )
    );

    return array(
        'download_url'             => $download_url,
        'download_id'              => $perm->download_id,
        'product_id'               => $perm->product_id,
        'product_name'             => $product->get_name(),
        'product_url'              => $product->is_visible() ? $product->get_permalink() : '',
        'download_name'            => $file_name,
        'order_id'                 => intval( $perm->order_id ),
        'order_key'                => $perm->order_key,
        'downloads_remaining'      => $perm->downloads_remaining,
        'access_expires'           => $perm->access_expires,
        'access_expires_formatted' => $access_expires_formatted,
        'file'                     => array(
            'name' => $file_name,
            'file' => $download_file->get_file(),
        ),
    );
}

/**
 * ============================================================
 * HELPER: Grant download access for a renewal order
 *
 * Uses WC_Customer_Download directly — the same internal class
 * WooCommerce uses — which has no order-status dependency.
 * Validates the product is actually downloadable and returns a
 * real true/false so the meta flag is only set on success.
 *
 * Calls jbt_revoke_stale_download_permissions() first so that
 * any permission pointing to a download ID no longer on the
 * product is removed before the new one is created. This ensures
 * only the current month's file is ever accessible.
 * ============================================================
 */
function jbt_grant_download_access( $order, $product_id, $customer_id ) {
    if ( ! $order || ! $product_id || ! $customer_id ) {
        jbt_log( 'JBT: jbt_grant_download_access() called with invalid params.' );
        return false;
    }

    $order_id = $order->get_id();
    $product  = wc_get_product( $product_id );

    if ( ! $product ) {
        jbt_log( "JBT: Product $product_id not found - cannot grant downloads." );
        return false;
    }

    if ( ! $product->is_downloadable() ) {
        jbt_log( "JBT: Product $product_id is not marked as Downloadable in WooCommerce - cannot grant downloads. Check the product settings." );
        return false;
    }

    $downloads = $product->get_downloads();

    if ( empty( $downloads ) ) {
        jbt_log( "JBT: Product $product_id has no downloadable files attached - cannot grant downloads. Check the Downloadable files tab on the product." );
        return false;
    }

    // Remove any permissions for this customer + product whose download ID
    // is no longer on the product (i.e. last month's file). This runs before
    // the new permission is created so only the current file is accessible.
    jbt_revoke_stale_download_permissions( $customer_id, $product_id, array_keys( $downloads ) );

    global $wpdb;
    $permissions_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

    $granted = 0;

    foreach ( $downloads as $download_id => $download ) {

        // Check if this exact permission already exists for this order
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT permission_id
               FROM {$permissions_table}
              WHERE user_id     = %d
                AND order_id    = %d
                AND product_id  = %d
                AND download_id = %s
              LIMIT 1",
            $customer_id,
            $order_id,
            $product_id,
            $download_id
        ) );

        if ( $existing ) {
            jbt_log( "JBT: Permission for download '$download_id' already exists on order #$order_id - counting as granted." );
            $granted++;
            continue;
        }

        // Build the permission object and save it directly.
        // This path has no order-status check and no
        // woocommerce_downloads_grant_access_after_payment check.
        $permission = new WC_Customer_Download();
        $permission->set_download_id( $download_id );
        $permission->set_product_id( $product_id );
        $permission->set_user_id( $customer_id );
        $permission->set_order_id( $order_id );
        $permission->set_order_key( $order->get_order_key() );
        $permission->set_user_email( $order->get_billing_email() );
        $permission->set_download_count( 0 );

        // Respect the product's download limit (blank = unlimited)
        $limit = (int) $product->get_download_limit();
        $permission->set_downloads_remaining( $limit > 0 ? $limit : '' );

        // Respect the product's expiry window (0 = never expires)
        $expiry_days = (int) $product->get_download_expiry();
        if ( $expiry_days > 0 ) {
            $expires_timestamp = strtotime( "+{$expiry_days} days", current_time( 'timestamp', true ) );
            $permission->set_access_expires(
                new WC_DateTime( "@{$expires_timestamp}", new DateTimeZone( 'UTC' ) )
            );
        }

        $saved_id = $permission->save();

        if ( $saved_id ) {
            $granted++;
            jbt_log( "JBT: Permission created (ID: $saved_id) for download '$download_id' on product $product_id to customer $customer_id on order #$order_id" );
        } else {
            jbt_log( "JBT: FAILED to save permission for download '$download_id' on order #$order_id" );
        }
    }

    if ( $granted > 0 ) {
        jbt_log( "JBT: $granted download permission(s) granted for product $product_id to customer $customer_id on order #$order_id" );
        return true;
    }

    jbt_log( "JBT: No permissions were granted for product $product_id on order #$order_id - check product Downloadable settings." );
    return false;
}

/**
 * ============================================================
 * SHARED LOGIC: Process both download grant and store credit
 * for a renewal order. Called by all hooks below.
 * Meta flags prevent double-awarding if multiple hooks fire.
 * ============================================================
 */
function jbt_process_renewal_order( $order ) {
    if ( ! $order ) {
        return;
    }

    $order_id             = $order->get_id();
    $customer_id          = $order->get_customer_id();
    $subscription_credits = JBT_SUBSCRIPTION_CREDITS;

    jbt_log( "JBT: === Processing renewal order #$order_id for customer #$customer_id ===" );

    if ( ! $customer_id ) {
        jbt_log( "JBT: No customer ID on renewal order #$order_id - aborting." );
        return;
    }

    if ( empty( $order->get_items() ) ) {
        jbt_log( "JBT: Renewal order #$order_id has no line items - aborting." );
        return;
    }

    foreach ( $order->get_items() as $item ) {
        $product_id = $item->get_product_id();
        jbt_log( "JBT: Checking line item product ID: $product_id" );

        if ( ! isset( $subscription_credits[ $product_id ] ) ) {
            continue;
        }

        $credit_amount = $subscription_credits[ $product_id ];
        $product       = wc_get_product( $product_id );
        $product_name  = $product ? $product->get_name() : 'Subscription';

        // ---- 1. GRANT DOWNLOAD ACCESS --------------------------------
        $download_already_granted = $order->get_meta( '_renewal_download_granted', true );

        if ( ! $download_already_granted ) {
            $download_result = jbt_grant_download_access( $order, $product_id, $customer_id );

            if ( $download_result ) {
                $order->add_order_note(
                    sprintf( 'Monthly download access granted for %s renewal (Order #%s).', $product_name, $order->get_order_number() )
                );
                $order->update_meta_data( '_renewal_download_granted', 'yes' );
                $order->update_meta_data( '_renewal_download_granted_date', current_time( 'mysql' ) );
                jbt_log( "JBT: Download granted for product $product_id on order #$order_id" );
            } else {
                $order->add_order_note(
                    sprintf( 'WARNING: Monthly download grant FAILED for %s renewal. Check wp-content/jbt-debug.log and product Downloadable settings. Grant manually if needed.', $product_name )
                );
                jbt_log( "JBT: Download grant FAILED for product $product_id on order #$order_id" );
                // Note: meta flag intentionally NOT set on failure so the next
                // hook can attempt the grant again.
            }
        } else {
            jbt_log( "JBT: Download already granted for order #$order_id - skipping." );
        }

        // ---- 2. AWARD STORE CREDIT -----------------------------------
        $credit_already_awarded = $order->get_meta( '_renewal_store_credit_awarded', true );

        if ( ! $credit_already_awarded ) {
            $credit_result = jbt_award_store_credit(
                $customer_id,
                $credit_amount,
                sprintf( '%s Renewal - Order #%s', $product_name, $order->get_order_number() ),
                $order_id
            );

            if ( $credit_result ) {
                $order->add_order_note(
                    sprintf( '$%s store credit awarded for %s renewal.', $credit_amount, $product_name )
                );
                $order->update_meta_data( '_renewal_store_credit_awarded', 'yes' );
                $order->update_meta_data( '_renewal_store_credit_awarded_date', current_time( 'mysql' ) );
                jbt_log( "JBT: \$$credit_amount store credit awarded to customer #$customer_id for order #$order_id" );
            } else {
                $order->add_order_note(
                    sprintf( 'WARNING: $%s store credit award FAILED for %s renewal. Please award manually via WooCommerce > Store Credit Manager.', $credit_amount, $product_name )
                );
                jbt_log( "JBT: Store credit FAILED for customer #$customer_id on order #$order_id" );
            }
        } else {
            jbt_log( "JBT: Store credit already awarded for order #$order_id - skipping." );
        }

        // Save all meta and notes then stop - only one subscription product per order
        $order->save();
        break;
    }
}

/**
 * ============================================================
 * HOOK 1: WooCommerce Subscriptions renewal payment complete
 * (fires on automatic renewals via WCS)
 * ============================================================
 */
add_action( 'woocommerce_subscription_renewal_payment_complete', 'jbt_handle_renewal_payment_complete', 10, 2 );
function jbt_handle_renewal_payment_complete( $subscription, $renewal_order ) {
    jbt_log( 'JBT: woocommerce_subscription_renewal_payment_complete fired' );
    if ( ! $renewal_order ) {
        jbt_log( 'JBT: No renewal order object - aborting.' );
        return;
    }
    jbt_process_renewal_order( $renewal_order );
}

/**
 * ============================================================
 * HOOK 2: Order status completed
 * (fires on manual completions and after safety net)
 *
 * Uses jbt_order_is_renewal() instead of wcs_order_contains_renewal()
 * so it works correctly with HPOS and sync-on-read disabled.
 * ============================================================
 */
add_action( 'woocommerce_order_status_completed', 'jbt_handle_renewal_on_completion', 10, 1 );
function jbt_handle_renewal_on_completion( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    if ( ! jbt_order_is_renewal( $order ) ) {
        return;
    }
    jbt_log( "JBT: woocommerce_order_status_completed fired for renewal order #$order_id" );
    jbt_process_renewal_order( $order );
}

/**
 * ============================================================
 * HOOK 3: Payment complete catch-all (HPOS-safe)
 *
 * woocommerce_payment_complete fires when any payment gateway
 * calls $order->payment_complete(). Reliable regardless of
 * order status transitions or HPOS compatibility.
 *
 * Uses jbt_order_is_renewal() instead of wcs_order_contains_renewal()
 * so it works correctly with HPOS and sync-on-read disabled.
 *
 * Meta flags in jbt_process_renewal_order() prevent double-
 * processing if Hook 1 already ran successfully.
 * ============================================================
 */
add_action( 'woocommerce_payment_complete', 'jbt_handle_payment_complete_catchall', 20, 1 );
function jbt_handle_payment_complete_catchall( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    if ( ! jbt_order_is_renewal( $order ) ) {
        return;
    }
    jbt_log( "JBT: woocommerce_payment_complete catch-all fired for renewal order #$order_id" );
    jbt_process_renewal_order( $order );
}

/**
 * ============================================================
 * INITIAL PURCHASE HANDLER
 *
 * Uses jbt_order_is_renewal() to correctly exclude renewal
 * orders under HPOS with sync-on-read disabled.
 * ============================================================
 */
add_action( 'woocommerce_order_status_completed', 'jbt_handle_initial_subscription_purchase', 10, 1 );
function jbt_handle_initial_subscription_purchase( $order_id ) {

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Skip renewal orders — handled by jbt_handle_renewal_on_completion
    if ( jbt_order_is_renewal( $order ) ) {
        return;
    }

    if ( $order->get_meta( '_initial_store_credit_awarded', true ) ) {
        jbt_log( "JBT: Initial credit already awarded for order #$order_id - skipping." );
        return;
    }

    $customer_id          = $order->get_customer_id();
    $subscription_credits = JBT_SUBSCRIPTION_CREDITS;

    foreach ( $order->get_items() as $item ) {
        $product_id = $item->get_product_id();

        if ( ! isset( $subscription_credits[ $product_id ] ) ) {
            continue;
        }

        $credit_amount = $subscription_credits[ $product_id ];
        $product       = wc_get_product( $product_id );
        $product_name  = $product ? $product->get_name() : 'Subscription';

        if ( ! $customer_id ) {
            jbt_log( "JBT: No customer ID on initial order #$order_id - cannot award credit." );
            break;
        }

        $credit_result = jbt_award_store_credit(
            $customer_id,
            $credit_amount,
            sprintf( '%s Initial Purchase - Order #%s', $product_name, $order->get_order_number() ),
            $order_id
        );

        if ( $credit_result ) {
            $order->add_order_note(
                sprintf( '$%s store credit awarded for %s initial purchase.', $credit_amount, $product_name )
            );
            $order->update_meta_data( '_initial_store_credit_awarded', 'yes' );
            $order->update_meta_data( '_initial_store_credit_awarded_date', current_time( 'mysql' ) );
            jbt_log( "JBT: Initial \$$credit_amount store credit awarded to customer #$customer_id for order #$order_id" );
        } else {
            $order->add_order_note(
                sprintf( 'WARNING: Initial $%s store credit FAILED for %s. Please award manually.', $credit_amount, $product_name )
            );
            jbt_log( "JBT: Initial store credit FAILED for customer #$customer_id on order #$order_id" );
        }

        $order->save();
        break;
    }
}

/**
 * ============================================================
 * SAFETY NET: Auto-complete renewal orders stuck in processing
 *
 * Uses jbt_order_is_renewal() instead of wcs_order_contains_renewal()
 * so it correctly detects renewals under HPOS with sync-on-read
 * disabled.
 * ============================================================
 */
add_action( 'woocommerce_order_status_processing', 'jbt_autocomplete_subscription_renewals', 10, 1 );
function jbt_autocomplete_subscription_renewals( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    if ( jbt_order_is_renewal( $order ) ) {
        $order->update_status( 'completed', 'JBT: Renewal order auto-completed from processing.' );
        jbt_log( "JBT: Renewal order #$order_id auto-completed from processing status." );
    }
}

/**
 * ============================================================
 * MANUAL GRANT ORDER ACTION
 *
 * Adds a "JBT: Grant renewal downloads & credit" option to the
 * Order Actions dropdown on the order edit screen in WooCommerce.
 *
 * HOW TO USE:
 *   1. Go to WooCommerce > Orders and open the renewal order
 *   2. Find the Order Actions box on the right side of the screen
 *   3. Select "JBT: Grant renewal downloads & credit"
 *   4. Click the Run (arrow) button
 *   5. Check the order notes for the result and
 *      wp-content/jbt-debug.log for full detail
 *
 * The action resets the _renewal_download_granted flag before
 * running so the grant is always re-attempted, even on orders
 * where a previous failed attempt already set the flag.
 * ============================================================
 */
add_filter( 'woocommerce_order_actions', 'jbt_register_manual_grant_action' );
function jbt_register_manual_grant_action( $actions ) {
    $actions['jbt_grant_renewal'] = 'JBT: Grant renewal downloads & credit';
    return $actions;
}

add_action( 'woocommerce_order_action_jbt_grant_renewal', 'jbt_run_manual_grant_action' );
function jbt_run_manual_grant_action( $order ) {
    $order_id = $order->get_id();
    jbt_log( "JBT: === MANUAL GRANT triggered via order action for order #$order_id ===" );

    // Reset the download flag so the grant will be re-attempted
    // even if a previous failed attempt already set it.
    $had_flag = $order->get_meta( '_renewal_download_granted', true );
    if ( $had_flag ) {
        $order->delete_meta_data( '_renewal_download_granted' );
        $order->delete_meta_data( '_renewal_download_granted_date' );
        $order->save();
        jbt_log( "JBT: Reset _renewal_download_granted flag on order #$order_id before manual retry." );
    }

    jbt_process_renewal_order( $order );
}

/**
 * ============================================================
 * DEBUG LOG VIEWER
 *
 * Adds a "Subscription Debug Log" page under the WooCommerce menu.
 * Displays the contents of wp-content/jbt-debug.log inline
 * with options to download or clear the file.
 * ============================================================
 */
add_action( 'admin_menu', 'jbt_register_log_viewer_page' );
function jbt_register_log_viewer_page() {
    add_submenu_page(
        'woocommerce',
        'Subscription Debug Log',
        'Subscription Debug Log',
        'manage_woocommerce',
        'jbt-debug-log',
        'jbt_render_log_viewer_page'
    );
}

add_action( 'admin_init', 'jbt_handle_log_actions' );
function jbt_handle_log_actions() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $action = isset( $_GET['jbt_log_action'] ) ? $_GET['jbt_log_action'] : '';
    $nonce  = isset( $_GET['_wpnonce'] )       ? $_GET['_wpnonce']       : '';

    if ( ! $action || ! wp_verify_nonce( $nonce, 'jbt_log_action' ) ) {
        return;
    }

    $log_file = WP_CONTENT_DIR . '/jbt-debug.log';

    if ( $action === 'clear' ) {
        file_put_contents( $log_file, '' );
        wp_redirect( admin_url( 'admin.php?page=jbt-debug-log&cleared=1' ) );
        exit;
    }

    if ( $action === 'download' && file_exists( $log_file ) ) {
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="jbt-debug.log"' );
        header( 'Content-Length: ' . filesize( $log_file ) );
        readfile( $log_file );
        exit;
    }
}

function jbt_render_log_viewer_page() {
    $log_file = WP_CONTENT_DIR . '/jbt-debug.log';
    $nonce    = wp_create_nonce( 'jbt_log_action' );

    $clear_url    = admin_url( 'admin.php?page=jbt-debug-log&jbt_log_action=clear&_wpnonce=' . $nonce );
    $download_url = admin_url( 'admin.php?page=jbt-debug-log&jbt_log_action=download&_wpnonce=' . $nonce );

    $cleared = isset( $_GET['cleared'] );

    echo '<div class="wrap">';
    echo '<h1>Subscription Debug Log</h1>';

    if ( $cleared ) {
        echo '<div class="notice notice-success is-dismissible"><p>Log cleared.</p></div>';
    }

    echo '<p style="display:flex;gap:8px;align-items:center;">';
    echo '<a href="' . esc_url( $download_url ) . '" class="button">Download Log</a>';
    echo '<a href="' . esc_url( $clear_url ) . '" class="button" onclick="return confirm(\'Clear the log file?\')">Clear Log</a>';
    echo '</p>';

    if ( ! file_exists( $log_file ) || filesize( $log_file ) === 0 ) {
        echo '<p><em>Log file is empty or does not exist yet.</em></p>';
    } else {
        $contents = file_get_contents( $log_file );
        echo '<textarea readonly style="width:100%;height:70vh;font-family:monospace;font-size:12px;background:#1e1e1e;color:#d4d4d4;padding:12px;border:none;resize:vertical;">';
        echo esc_textarea( $contents );
        echo '</textarea>';
        echo '<script>const ta=document.querySelector("textarea[readonly]");ta.scrollTop=ta.scrollHeight;</script>';
    }

    echo '</div>';
}

/**
 * ============================================================
 * MY ACCOUNT DOWNLOADS TAB VISIBILITY FIX
 *
 * With HPOS enabled and sync-on-read disabled, WooCommerce's
 * My Account Downloads tab uses a SQL query that joins the
 * permissions table against wp_posts for order status. New
 * renewal orders only exist in the HPOS tables (wc_orders),
 * so the join finds nothing and subscription downloads are
 * invisible in the Downloads tab — even though they are
 * accessible from the Orders tab.
 *
 * This filter queries our subscription permissions directly
 * from the permissions table (HPOS-agnostic), builds the
 * download data array WooCommerce expects, and injects any
 * that aren't already in the list. Only permissions whose
 * download ID still exists on the product are included, so
 * stale entries from previous months never surface.
 * ============================================================
 */
add_filter( 'woocommerce_customer_available_downloads', 'jbt_inject_subscription_downloads', 10, 2 );
function jbt_inject_subscription_downloads( $downloads, $customer_id ) {
    if ( ! $customer_id ) {
        return $downloads;
    }

    global $wpdb;
    $permissions_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
    $subscription_ids  = array_keys( JBT_SUBSCRIPTION_CREDITS );
    $placeholders      = implode( ', ', array_fill( 0, count( $subscription_ids ), '%d' ) );

    // Build a set of keys already in the list to avoid duplicates
    $existing_keys = array();
    foreach ( $downloads as $d ) {
        $did = isset( $d['download_id'] ) ? $d['download_id'] : '';
        $oid = isset( $d['order_id'] )    ? $d['order_id']    : '';
        if ( $did !== '' ) {
            $existing_keys[] = $did . '|' . $oid;
        }
    }

    // Fetch all active permissions for our subscription products for this customer
    $query_args  = array_merge( array( $customer_id ), $subscription_ids );
    $permissions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
               FROM {$permissions_table}
              WHERE user_id    = %d
                AND product_id IN ($placeholders)
                AND ( downloads_remaining > 0 OR downloads_remaining = '' )
                AND ( access_expires IS NULL OR access_expires > UTC_TIMESTAMP() )",
            $query_args
        )
    );

    if ( empty( $permissions ) ) {
        return $downloads;
    }

    foreach ( $permissions as $perm ) {
        $key = $perm->download_id . '|' . $perm->order_id;

        if ( in_array( $key, $existing_keys, true ) ) {
            continue;
        }

        $product = wc_get_product( $perm->product_id );
        $order   = wc_get_order( $perm->order_id );

        if ( ! $product || ! $order ) {
            continue;
        }

        $item = jbt_build_download_item( $perm, $product, $customer_id );
        if ( ! $item ) {
            continue;
        }

        $downloads[]     = $item;
        $existing_keys[] = $key;

        jbt_log( "JBT: Injected download '{$perm->download_id}' for product {$perm->product_id} into Downloads tab for customer $customer_id." );
    }

    return $downloads;
}

/**
 * ============================================================
 * MY ACCOUNT DOWNLOADS TAB — SORT BY MOST RECENT FIRST
 *
 * WooCommerce returns downloads in oldest-first order by default.
 * This filter runs at priority 99 (after our injection filter at
 * priority 10) and sorts the complete list by order_id descending
 * so the newest purchase always appears at the top.
 *
 * PHP 8.2 fix: uses isset() before reading order_id to avoid
 * undefined array key warnings that corrupt page output.
 * ============================================================
 */
add_filter( 'woocommerce_customer_available_downloads', 'jbt_sort_downloads_newest_first', 99, 1 );
function jbt_sort_downloads_newest_first( $downloads ) {
    if ( empty( $downloads ) ) {
        return $downloads;
    }

    usort( $downloads, function( $a, $b ) {
        $a_id = isset( $a['order_id'] ) ? intval( $a['order_id'] ) : 0;
        $b_id = isset( $b['order_id'] ) ? intval( $b['order_id'] ) : 0;
        return $b_id - $a_id;
    } );

    return $downloads;
}

/**
 * ============================================================
 * ORDER DETAILS PAGE DOWNLOADS DISPLAY FIX
 *
 * WooCommerce's order details page calls get_downloadable_items()
 * which only looks for permissions where order_id matches the
 * order being viewed. Because we grant permissions on the renewal
 * order, they are invisible when the customer views any other
 * order (e.g. the original subscription order or any prior
 * renewal). The Downloads section renders with headers but no rows.
 *
 * This filter runs on every order that contains one of our
 * subscription products. It finds the customer's current active
 * permission for that product — regardless of which order it
 * was granted on — and injects it into the display if it isn't
 * already there. The customer sees the current month's download
 * link on every order containing their subscription.
 * ============================================================
 */
add_filter( 'woocommerce_order_get_downloadable_items', 'jbt_show_subscription_download_on_order', 10, 2 );
function jbt_show_subscription_download_on_order( $downloads, $order ) {
    $customer_id      = $order->get_customer_id();
    $subscription_ids = array_keys( JBT_SUBSCRIPTION_CREDITS );

    if ( ! $customer_id ) {
        return $downloads;
    }

    // Only run on orders that contain one of our subscription products
    $order_has_subscription = false;
    foreach ( $order->get_items() as $item ) {
        if ( in_array( $item->get_product_id(), $subscription_ids, true ) ) {
            $order_has_subscription = true;
            break;
        }
    }

    if ( ! $order_has_subscription ) {
        return $downloads;
    }

    // Build a set of download IDs already in the list to avoid duplicates
    $existing_download_ids = array();
    foreach ( $downloads as $d ) {
        if ( isset( $d['download_id'] ) ) {
            $existing_download_ids[] = $d['download_id'];
        }
    }

    global $wpdb;
    $permissions_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
    $placeholders      = implode( ', ', array_fill( 0, count( $subscription_ids ), '%d' ) );
    $query_args        = array_merge( array( $customer_id ), $subscription_ids );

    // Fetch the most recent active permission for each subscription product
    $permissions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
               FROM {$permissions_table}
              WHERE user_id    = %d
                AND product_id IN ($placeholders)
                AND ( downloads_remaining > 0 OR downloads_remaining = '' )
                AND ( access_expires IS NULL OR access_expires > UTC_TIMESTAMP() )
              ORDER BY permission_id DESC",
            $query_args
        )
    );

    foreach ( $permissions as $perm ) {
        if ( in_array( $perm->download_id, $existing_download_ids, true ) ) {
            continue;
        }

        $product = wc_get_product( $perm->product_id );
        if ( ! $product ) {
            continue;
        }

        $item = jbt_build_download_item( $perm, $product, $customer_id );
        if ( ! $item ) {
            continue;
        }

        $downloads[]             = $item;
        $existing_download_ids[] = $perm->download_id;
    }

    return $downloads;
}
