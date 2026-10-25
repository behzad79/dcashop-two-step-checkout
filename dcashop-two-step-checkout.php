<?php
/**
 * Plugin Name: Two-Step Checkout for WooCommerce
 * Description: Two-stage Classic WooCommerce checkout with address modal and optional address book, preserving existing Flatsome templates, shipping and gateways.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Author: Seyyed Behzad Mousaviyan
 * Author URI: https://github.com/behzad79
 * Plugin URI: https://github.com/behzad79/dcashop-two-step-checkout
 * Text Domain: dcashop-two-step-checkout
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DCAShop_Two_Step_Checkout {
    private const OPTION = 'dcashop_two_step_checkout_mode';
    private const BOOK = 'dcashop_checkout_address_book_v1';
    private const DEFAULT_EXTRA = 'dcashop_checkout_default_extra_v1';
    private const SELECTED = 'dcashop_checkout_selected_address_v1';
    private const VERSION = '1.0.0';
    private const SHIPPING_OK = 'dcashop_checkout_confirmed_shipping_v1';
    private const SHIPPING_DRAFT = 'dcashop_checkout_shipping_draft_v1';
    private const SHIPPING_RECIPIENT = 'dcashop_checkout_shipping_recipient_v1';
    private const ROUTE_VAR = 'dcashop_checkout_step';
    private const REWRITE_SIGNATURE = 'dcashop_checkout_rewrite_signature_v050';
    private const MAX_ADDRESSES = 5; // Includes the existing WooCommerce default address, if present.

    public static function boot(): void {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_post_dcashop_two_step_save', array( __CLASS__, 'save_settings' ) );
        // One original WooCommerce checkout page, two virtual URLs.
        add_action( 'init', array( __CLASS__, 'register_step_routes' ), 19 );
        add_action( 'admin_init', array( __CLASS__, 'ensure_routes_are_flushed' ), 20 );
        add_filter( 'redirect_canonical', array( __CLASS__, 'skip_step_canonical_redirect' ), 10, 2 );
        add_action( 'template_redirect', array( __CLASS__, 'route_checkout_step' ), 2 );
        add_action( 'wp_ajax_dcashop_checkout_confirm_shipping', array( __CLASS__, 'ajax_confirm_shipping' ) );
        add_action( 'wp_ajax_nopriv_dcashop_checkout_confirm_shipping', array( __CLASS__, 'ajax_confirm_shipping' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 50 );
        add_filter( 'body_class', array( __CLASS__, 'checkout_loading_body_class' ) );
        add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'checkout_loading_indicator' ), -999 );
        add_action( 'wp_ajax_dcashop_address_save', array( __CLASS__, 'ajax_address_save' ) );
        add_action( 'wp_ajax_dcashop_address_select', array( __CLASS__, 'ajax_address_select' ) );
        add_action( 'wp_ajax_dcashop_address_delete', array( __CLASS__, 'ajax_address_delete' ) );
        add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'render_checkout_totals_rows' ), 6 );
        // The approved shipping draft is authoritative, not a stale billing/shipping profile.
        add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'use_approved_checkout_address' ), 9999 );
        add_filter( 'woocommerce_checkout_update_customer_data', array( __CLASS__, 'preserve_address_book_account' ), 9999 );
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'add_recipient_to_order' ), 9999, 2 );
        // An order may already exist when a payment/notification plugin fails.
        // Remember it before other order_processed hooks run so a retry can reuse
        // the same unpaid WooCommerce order instead of making a duplicate.
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'remember_pending_checkout_order' ), -100, 3 );
        // Never discard confirmed shipping at order creation: payment has not
        // started yet. A gateway/plugin error would otherwise strand the user
        // on /checkout/payment/ with no approved delivery address.
        add_action( 'woocommerce_thankyou', array( __CLASS__, 'clear_confirmed_shipping_after_order' ), 99 );
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'render_admin_recipient' ) );
        add_action( 'admin_footer', array( __CLASS__, 'attach_admin_delivery_to_native_editor' ) );
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_admin_shipping_details' ), 40, 2 );
        add_filter( 'woocommerce_my_account_my_address_formatted_address', array( __CLASS__, 'format_account_address' ), 30, 3 );
        add_filter( 'woocommerce_order_formatted_billing_address', array( __CLASS__, 'format_order_billing_address' ), 30, 2 );
        add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'account_address_fields' ), 50, 2 );
        add_action( 'woocommerce_customer_save_address', array( __CLASS__, 'save_account_unit_field' ), 30, 2 );
        // The existing Flatsome Child my-address.php stays untouched. Attach our
        // shared address book after WooCommerce renders its native address page.
        add_action( 'woocommerce_account_edit-address_endpoint', array( __CLASS__, 'render_account_address_book' ), 30 );
        add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'checkout_saved_value' ), 20, 2 );
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'label_delivery_fields' ), 9999 );
        add_filter( 'woocommerce_enable_order_notes_field', array( __CLASS__, 'disable_empty_order_notes' ), 99 );
    }

    /** Routes work for any checkout page slug (including a nested page). */
    private static function checkout_page(): int {
        return function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
    }

    private static function page_path(): string {
        $page_id = self::checkout_page();
        return $page_id > 0 ? trim( (string) get_page_uri( $page_id ), '/' ) : '';
    }

    public static function register_step_routes(): void {
        $id = self::checkout_page();
        $path = self::page_path();
        if ( $id <= 0 || '' === $path ) { return; }
        add_rewrite_tag( '%' . self::ROUTE_VAR . '%', '(shipping|payment)' );
        add_rewrite_rule(
            '^' . preg_quote( $path, '#' ) . '/(shipping|payment)/?$',
            'index.php?page_id=' . $id . '&' . self::ROUTE_VAR . '=$matches[1]',
            'top'
        );
    }

    public static function ensure_routes_are_flushed(): void {
        if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'flush_rewrite_rules' ) ) { return; }
        $path = self::page_path();
        if ( '' === $path ) { return; }
        $signature = $path . '|v050|' . (string) get_option( 'permalink_structure' );
        if ( get_option( self::REWRITE_SIGNATURE, '' ) === $signature ) { return; }
        flush_rewrite_rules( false );
        update_option( self::REWRITE_SIGNATURE, $signature, false );
    }

    public static function on_activation(): void {
        self::register_step_routes();
        flush_rewrite_rules( false );
    }

    private static function checkout_step(): string {
        $step = (string) get_query_var( self::ROUTE_VAR, '' );
        return in_array( $step, array( 'shipping', 'payment' ), true ) ? $step : '';
    }

    public static function skip_step_canonical_redirect( $redirect, $requested ) {
        return self::checkout_step() ? false : $redirect;
    }

    private static function step_url( string $step ): string {
        $page_id = self::checkout_page();
        $base = $page_id > 0 ? get_permalink( $page_id ) : wc_get_checkout_url();
        if ( ! get_option( 'permalink_structure' ) || strpos( $base, '?' ) !== false ) {
            return add_query_arg( self::ROUTE_VAR, $step, $base );
        }
        return trailingslashit( $base ) . $step . '/';
    }

    /** Payment guard is bound to shipping selection, address and cart items. */
    private static function shipping_fingerprint(): string {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) { return ''; }
        $cart_items = array();
        foreach ( WC()->cart->get_cart() as $cart_key => $item ) {
            $cart_items[] = array(
                (string) $cart_key,
                (int) ( $item['product_id'] ?? 0 ),
                (int) ( $item['variation_id'] ?? 0 ),
                (float) ( $item['quantity'] ?? 0 ),
            );
        }
        // Coupon changes do not invalidate an already confirmed address; cart
        // contents, selected address and the shipping method do invalidate it.
        return hash( 'sha256', wp_json_encode( array(
            'cart'     => $cart_items,
            'selected' => WC()->session->get( self::SELECTED, '' ),
            'shipping' => WC()->session->get( 'chosen_shipping_methods', array() ),
            'address'  => WC()->session->get( self::SHIPPING_DRAFT, array() ),
        ) ) );
    }

    private static function confirmed_shipping(): bool {
        if ( ! WC()->cart || ! WC()->session || WC()->cart->is_empty() ) { return false; }
        $confirmed = WC()->session->get( self::SHIPPING_OK, '' );
        if ( ! is_string( $confirmed ) || '' === $confirmed ) { return false; }
        $draft = WC()->session->get( self::SHIPPING_DRAFT, array() );
        if ( ! is_array( $draft ) || ! self::draft_is_complete( $draft ) ) { return false; }
        // The confirmed form fields are the source of truth for this order.
        // Do NOT compare them with the customer's saved address record: a map
        // provider or a manual edit may legitimately produce different values.
        // The session fingerprint still binds cart, selected address ID, chosen
        // shipping methods and the exact submitted checkout draft.
        return hash_equals( $confirmed, self::shipping_fingerprint() );
    }

    private static function draft_is_complete( array $draft ): bool {
        foreach ( array( 'billing_first_name', 'billing_last_name', 'billing_phone',
            'billing_state', 'billing_city', 'billing_address_1', 'billing_address_4', 'billing_address_3' ) as $field ) {
            if ( ! isset( $draft[ $field ] ) || '' === trim( (string) $draft[ $field ] ) ) { return false; }
        }
        return true;
    }

    public static function route_checkout_step(): void {
        if ( ! self::on_checkout() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) { return; }
        $step = self::checkout_step();
        if ( ! self::active() ) {
            // Old checkout still works for visitors outside preview mode.
            if ( $step ) { wp_safe_redirect( wc_get_checkout_url(), 302 ); exit; }
            return;
        }
        if ( ! $step ) {
            wp_safe_redirect( self::step_url( 'shipping' ), 302 );
            exit;
        }
        if ( 'payment' === $step && ( ! WC()->session || ! self::confirmed_shipping() ) ) {
            wp_safe_redirect( self::step_url( 'shipping' ), 302 );
            exit;
        }
        nocache_headers();
    }

    /**
     * Preserve every street component that the customer or map supplied.
     * Remove only a standalone comma-delimited city name if it is saved in
     * billing_city as well. Never strip state, settlement, village, alley or
     * a street or neighborhood name that happens to contain the city name.
     */
    private static function clean_street_prefix( string $street, string $state, string $city ): string {
        $street = trim( $street );
        $city = trim( $city );
        if ( '' === $street || '' === $city ) { return $street; }
        $norm = static function ( string $text ): string {
            $text = str_replace( array( 'ي', 'ك', '‌', "\u{200F}", "\u{200E}" ), array( 'ی', 'ک', ' ', '', '' ), trim( $text ) );
            return preg_replace( '/\s+/u', ' ', $text ) ?: $text;
        };
        $name = $norm( $city );
        $parts = preg_split( '/\s*[،,؛;]\s*/u', $street, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) ) { return $street; }
        $kept = array();
        foreach ( $parts as $part ) {
            $part_name = $norm( $part );
            if ( $part_name !== $name && $part_name !== 'شهر ' . $name ) {
                $kept[] = trim( $part );
            }
        }
        return $kept ? implode( '، ', $kept ) : $street;
    }

    public static function ajax_confirm_shipping(): void {
        if ( ! self::active() || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
            wp_send_json_error( array( 'message' => 'سبد خرید یا نشست مشتری معتبر نیست.' ), 400 );
        }
        check_ajax_referer( 'dcashop_checkout_address_v1', 'nonce' );
        $incoming = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! is_array( $incoming ) ) { wp_send_json_error( array( 'message' => 'اطلاعات آدرس نامعتبر است.' ), 400 ); }
        $draft = array();
        foreach ( self::columns() as $key ) {
            $draft[ $key ] = isset( $incoming[ $key ] ) && is_scalar( $incoming[ $key ] )
                ? sanitize_text_field( $incoming[ $key ] ) : '';
        }
        $draft['billing_address_1'] = self::clean_street_prefix( $draft['billing_address_1'], $draft['billing_state'], $draft['billing_city'] );
        if ( ! self::draft_is_complete( $draft ) ) {
            wp_send_json_error( array( 'message' => 'اطلاعات نام، تماس و آدرس را کامل کنید.' ), 400 );
        }
        // This is a freshly confirmed checkout address. It may differ from a
        // saved address because the customer corrected it or chose a map pin.
        // Field completeness and the current shipping rate are checked below;
        // the confirmed draft will be used on the payment route.
        if ( WC()->cart->needs_shipping() ) {
            $chosen = WC()->session->get( 'chosen_shipping_methods', array() );
            if ( ! is_array( $chosen ) || ! count( array_filter( $chosen ) ) ) {
                wp_send_json_error( array( 'message' => 'لطفاً ابتدا روش ارسال را انتخاب کنید.' ), 400 );
            }
            $packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
            if ( ! empty( $packages ) ) {
                foreach ( $packages as $key => $package ) {
                    if ( empty( $package['rates'] ) ) { wp_send_json_error( array( 'message' => 'برای این آدرس روش ارسال موجود نیست.' ), 400 ); }
                    $chosen_rate = $chosen[ $key ] ?? '';
                    if ( ! isset( $package['rates'][ $chosen_rate ] ) ) {
                        wp_send_json_error( array( 'message' => 'روش ارسال انتخابی تغییر کرده است. دوباره انتخاب کنید.' ), 409 );
                    }
                }
            }
        }
        // Freeze recipient with the shipping choice. Never re-read the address book
        // when the order is created because it may have been edited in another tab.
        $selected = is_user_logged_in() ? self::selected_address_for_order() : null;
        $recipient = array( 'recipient_type' => 'self', 'recipient_first_name' => '', 'recipient_last_name' => '', 'recipient_phone' => '' );
        if ( is_array( $selected ) ) {
            foreach ( array_keys( $recipient ) as $key ) {
                $recipient[ $key ] = isset( $selected[ $key ] ) ? sanitize_text_field( (string) $selected[ $key ] ) : $recipient[ $key ];
            }
        }
        WC()->session->set( self::SHIPPING_RECIPIENT, $recipient );
        // A first-time customer should see their first confirmed address in
        // My Account. Convert the selected book entry into the primary record
        // rather than creating a duplicate of the same address.
        self::initialize_first_account_address( $draft, $selected );
        WC()->session->set( self::SHIPPING_DRAFT, $draft );
        WC()->session->set( self::SHIPPING_OK, self::shipping_fingerprint() );
        wp_send_json_success( array( 'paymentUrl' => self::step_url( 'payment' ) ) );
    }

    private static function active(): bool {
        $mode = get_option( self::OPTION, 'off' );
        return 'all' === $mode || ( 'preview' === $mode && current_user_can( 'manage_woocommerce' ) );
    }

    public static function disable_empty_order_notes( $enabled ) {
        // The 'Additional information' header comes from form-shipping.php;
        // suppress that optional WooCommerce section while leaving Mobits hook output alone.
        return ( self::active() && self::on_checkout() ) ? false : $enabled;
    }

    private static function on_checkout(): bool {
        return function_exists( 'is_checkout' ) && is_checkout()
            && ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' )
            && ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() );
    }

    public static function admin_menu(): void {
        add_submenu_page( 'woocommerce', 'تسویه‌حساب دو مرحله‌ای دکاشاپ', 'مراحل پرداخت دکاشاپ', 'manage_woocommerce', 'dcashop-two-step-checkout', array( __CLASS__, 'settings_page' ) );
    }

    public static function settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'دسترسی کافی نیست.' ); }
        $mode = get_option( self::OPTION, 'off' );
        ?>
        <div class="wrap" dir="rtl">
            <h1>تسویه‌حساب دو مرحله‌ای دکاشاپ — نسخه <?php echo esc_html( self::VERSION ); ?></h1>
            <p>نمای آدرس به صورت پنجره‌ای و مرحله دوم پرداخت. موتور سفارش، ورود موبیتس، محاسبه ارسال و درگاه‌ها تغییری نمی‌کنند.</p>
            <?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="dcashop_two_step_save">
                <?php wp_nonce_field( 'dcashop_two_step_save', 'dcashop_two_step_nonce' ); ?>
                <p><label for="dc-shop-steps-mode"><strong>وضعیت نمایش</strong></label></p>
                <select id="dc-shop-steps-mode" name="mode">
                    <option value="off" <?php selected( $mode, 'off' ); ?>>خاموش — صفحه سابق</option>
                    <option value="preview" <?php selected( $mode, 'preview' ); ?>>پیش‌نمایش فقط مدیران</option>
                    <option value="all" <?php selected( $mode, 'all' ); ?>>فعال برای همه (فقط بعد از تست کامل)</option>
                </select>
                <?php submit_button( 'ذخیره تنظیمات' ); ?>
            </form>
            <p><strong>آدرس آزمایشی ارسال:</strong> <a href="<?php echo esc_url( self::step_url( 'shipping' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::step_url( 'shipping' ) ); ?></a></p>
            <p><strong>آدرس آزمایشی پرداخت:</strong> <a href="<?php echo esc_url( self::step_url( 'payment' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::step_url( 'payment' ) ); ?></a></p>
            <p><strong>مهم:</strong> افزودن یا ویرایش آدرس از طریق AJAX در حساب کاربری ذخیره می‌شود. قبل از استفاده عمومی، روی محیط آزمایشی موبیتس، نیکان و تمام روش‌های ارسال و پرداخت را تست کنید.</p>
        </div>
        <?php
    }

    public static function save_settings(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'دسترسی کافی نیست.' ); }
        check_admin_referer( 'dcashop_two_step_save', 'dcashop_two_step_nonce' );
        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'off';
        update_option( self::OPTION, in_array( $mode, array( 'off', 'preview', 'all' ), true ) ? $mode : 'off' );
        wp_safe_redirect( add_query_arg( array( 'page' => 'dcashop-two-step-checkout', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    // Use YITH billing_address_4 for plaque, billing_address_3 for unit; never repurpose Woo address_2.
    private static function columns(): array {
        return array( 'billing_first_name', 'billing_last_name', 'billing_phone',
            'billing_country', 'billing_state', 'billing_city', 'billing_address_1',
            'billing_address_4', 'billing_address_3', 'billing_postcode', 'billing_map_lat_long' );
    }

    public static function label_delivery_fields( $fields ) {
        if ( ! self::active() || ! self::on_checkout() || ! is_array( $fields ) ) { return $fields; }
        foreach ( array( 'billing_address_4' => 'پلاک', 'billing_address_3' => 'واحد' ) as $key => $label ) {
            if ( ! isset( $fields['billing'][ $key ] ) ) {
                $fields['billing'][ $key ] = array(
                    'type' => 'text', 'label' => $label, 'required' => true,
                    'class' => array( 'form-row-wide' ), 'priority' => $key === 'billing_address_4' ? 63 : 64,
                );
            }
            $fields['billing'][ $key ]['label'] = $label;
            $fields['billing'][ $key ]['required'] = true;
        }
        return $fields;
    }

    private static function default_address( int $user_id ): array {
        $result = array( 'id' => 'default', 'label' => 'آدرس اصلی', 'recipient_type' => 'self',
            'recipient_first_name' => '', 'recipient_last_name' => '', 'recipient_phone' => '' );
        foreach ( self::columns() as $field ) {
            if ( 'billing_map_lat_long' === $field ) { continue; }
            $result[ $field ] = (string) get_user_meta( $user_id, $field, true );
        }
        // Addresses saved by 0.5.2-0.5.4 used Woo's native address_2 for the plaque.
        // Read them without destructive bulk migration. All new saves use address_4.
        if ( empty( $result['billing_address_4'] ) ) {
            $result['billing_address_4'] = (string) get_user_meta( $user_id, 'billing_address_2', true );
        }
        $extra = get_user_meta( $user_id, self::DEFAULT_EXTRA, true );
        foreach ( array( 'billing_map_lat_long', 'label', 'recipient_type', 'recipient_first_name', 'recipient_last_name', 'recipient_phone' ) as $field ) {
            if ( is_array( $extra ) && isset( $extra[ $field ] ) ) { $result[ $field ] = (string) $extra[ $field ]; }
        }
        return $result;
    }

    /** Initialize the account's primary address once; never replace an existing
     * primary address merely because a different delivery destination is used. */
    private static function initialize_first_account_address( array $draft, ?array $selected ): void {
        if ( ! is_user_logged_in() || ! self::draft_is_complete( $draft ) ) { return; }
        $uid = get_current_user_id();
        if ( '' !== trim( (string) get_user_meta( $uid, 'billing_address_1', true ) ) ) { return; }
        $customer = new WC_Customer( $uid );
        foreach ( array( 'first_name', 'last_name', 'phone', 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' ) as $field ) {
            $setter = 'set_billing_' . $field;
            if ( is_callable( array( $customer, $setter ) ) ) {
                $value = sanitize_text_field( (string) ( $draft[ 'billing_' . $field ] ?? '' ) );
                if ( 'country' === $field && '' === $value ) { $value = 'IR'; }
                $customer->{$setter}( $value );
            }
        }
        $customer->save();
        update_user_meta( $uid, 'billing_address_4', sanitize_text_field( (string) ( $draft['billing_address_4'] ?? '' ) ) );
        update_user_meta( $uid, 'billing_address_3', sanitize_text_field( (string) ( $draft['billing_address_3'] ?? '' ) ) );
        $extra = array( 'label' => 'آدرس اصلی', 'recipient_type' => 'self', 'recipient_first_name' => '',
            'recipient_last_name' => '', 'recipient_phone' => '',
            'billing_map_lat_long' => sanitize_text_field( (string) ( $draft['billing_map_lat_long'] ?? '' ) ) );
        if ( is_array( $selected ) ) {
            foreach ( array( 'label', 'recipient_type', 'recipient_first_name', 'recipient_last_name', 'recipient_phone' ) as $field ) {
                if ( isset( $selected[ $field ] ) ) { $extra[ $field ] = sanitize_text_field( (string) $selected[ $field ] ); }
            }
            $selected_id = (string) ( $selected['id'] ?? '' );
            if ( '' !== $selected_id && 'default' !== $selected_id ) {
                $book = array_values( array_filter( self::address_book( $uid ), static function ( $item ) use ( $selected_id ) {
                    return (string) ( $item['id'] ?? '' ) !== $selected_id;
                } ) );
                update_user_meta( $uid, self::BOOK, $book );
            }
        }
        update_user_meta( $uid, self::DEFAULT_EXTRA, $extra );
        if ( WC()->session ) { WC()->session->set( self::SELECTED, 'default' ); }
    }

    private static function address_book( int $user_id ): array {
        $book = get_user_meta( $user_id, self::BOOK, true );
        if ( ! is_array( $book ) ) { return array(); }
        $book = array_values( array_filter( $book, 'is_array' ) );
        // Previous plugin versions saved plaque in billing_address_2 inside the book.
        foreach ( $book as &$entry ) {
            if ( empty( $entry['billing_address_4'] ) && ! empty( $entry['billing_address_2'] ) ) {
                $entry['billing_address_4'] = (string) $entry['billing_address_2'];
            }
        }
        unset( $entry );
        return $book;
    }

    private static function selected_address( int $user_id ): ?array {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) { return null; }
        $id = WC()->session->get( self::SELECTED );
        if ( ! is_string( $id ) ) { return null; }
        if ( 'default' === $id ) { return self::default_address( $user_id ); }
        foreach ( self::address_book( $user_id ) as $address ) {
            if ( isset( $address['id'] ) && $address['id'] === $id ) { return $address; }
        }
        return null;
    }

    public static function checkout_saved_value( $value, $input ) {
        if ( ! self::active() || ! self::on_checkout() || ! is_user_logged_in() || ! in_array( $input, self::columns(), true ) || ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return $value;
        }
        $draft = WC()->session ? WC()->session->get( self::SHIPPING_DRAFT, array() ) : array();
        if ( 'payment' === self::checkout_step() && is_array( $draft ) && isset( $draft[ $input ] ) ) { return $draft[ $input ]; }
        $address = self::selected_address( get_current_user_id() );
        return $address && isset( $address[ $input ] ) ? $address[ $input ] : $value;
    }

    /** Mark the real classic checkout on the server, before the page's HTML is painted.
     * A short, visible loading message replaces the old checkout layout until JS
     * completes. CSS automatically reveals the normal WooCommerce form if JS fails.
     */
    public static function checkout_loading_body_class( $classes ) {
        if ( self::active() && self::on_checkout() ) { $classes[] = 'dc-checkout-awaiting-layout'; }
        return $classes;
    }

    public static function checkout_loading_indicator(): void {
        if ( ! self::active() || ! self::on_checkout() ) { return; }
        // Flatsome's normal store logo; fall back to the WordPress custom logo.
        // Do not hardcode an external image URL or depend on a third-party loader.
        $logo = get_theme_mod( 'site_logo', '' );
        if ( is_numeric( $logo ) ) {
            $logo = wp_get_attachment_image_url( (int) $logo, 'full' );
        }
        if ( ! is_string( $logo ) || '' === $logo ) {
            $logo_id = (int) get_theme_mod( 'custom_logo', 0 );
            $logo = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
        }
        echo '<div class="dc-checkout-initial-loader" role="status" aria-label="در حال بارگذاری تسویه‌حساب" aria-live="polite">';
        echo '<div class="dc-checkout-loading-card">';
        if ( $logo ) {
            echo '<img class="dc-checkout-loading-logo" src="' . esc_url( $logo ) . '" alt="دکاشاپ" decoding="async" />';
        } else {
            echo '<div class="dc-checkout-loading-wordmark" aria-hidden="true">DCASHOP</div>';
        }
        echo '<span class="dc-checkout-initial-spinner" aria-hidden="true"></span>';
        echo '</div></div>';
    }

    public static function enqueue(): void {
        if ( ! self::active() || ! function_exists( 'WC' ) ) { return; }
        $base = plugin_dir_url( __FILE__ );
        if ( self::account_addresses_index() ) {
            wp_enqueue_style( 'dcashop-account-address-book', $base . 'assets/account-addresses.css', array(), self::VERSION );
            wp_enqueue_script( 'dcashop-account-address-book', $base . 'assets/account-addresses.js', array( 'jquery' ), self::VERSION, true );
            $uid = get_current_user_id();
            $customer = get_userdata( $uid );
            $states = WC()->countries ? WC()->countries->get_states( 'IR' ) : array();
            $default = self::default_address( $uid );
            $selected = WC()->session ? WC()->session->get( self::SELECTED, '' ) : '';
            wp_localize_script( 'dcashop-account-address-book', 'dcAccountBook', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'dcashop_checkout_address_v1' ),
                'defaultAddress' => $default,
                'addresses' => self::address_book( $uid ),
                'selected' => is_string( $selected ) ? $selected : '',
                'states' => is_array( $states ) ? $states : array(),
                'maxAddresses' => self::MAX_ADDRESSES,
                'shippingUrl' => self::step_url( 'shipping' ),
                'billingEditUrl' => wc_get_endpoint_url( 'edit-address', 'billing', wc_get_page_permalink( 'myaccount' ) ),
                'fallbackName' => array(
                    'first' => (string) get_user_meta( $uid, 'first_name', true ),
                    'last' => (string) get_user_meta( $uid, 'last_name', true ),
                    'phone' => (string) get_user_meta( $uid, 'billing_phone', true ),
                ),
            ) );
            return;
        }
        if ( ! WC()->cart || ! self::on_checkout() ) { return; }
        wp_enqueue_style( 'dcashop-two-step', $base . 'assets/checkout-steps.css', array(), self::VERSION );
        wp_enqueue_script( 'dcashop-two-step', $base . 'assets/checkout-steps.js', array( 'jquery', 'wc-checkout' ), self::VERSION, true );
        $uid = get_current_user_id();
        $default = $uid ? self::default_address( $uid ) : null;
        $book = $uid ? self::address_book( $uid ) : array();
        $selected = $uid && WC()->session ? WC()->session->get( self::SELECTED ) : '';
        wp_localize_script( 'dcashop-two-step', 'dcShopSteps', array(
            'uttamap' => class_exists('DCAShop_Uttamap_Map') && DCAShop_Uttamap_Map::is_ready(),
            'shippingRequired' => (bool) ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ),
            'loggedIn' => is_user_logged_in(), 'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'dcashop_checkout_address_v1' ),
            'defaultAddress' => $default, 'addresses' => $book, 'selected' => $selected,
            'maxAddresses' => self::MAX_ADDRESSES,
            'couponsEnabled' => function_exists( 'wc_coupons_enabled' ) && wc_coupons_enabled(),
            'couponNonce' => wp_create_nonce( 'apply-coupon' ),
            'couponUrl' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'apply_coupon' ) : '',
            'homeUrl' => home_url( '/' ),
            'shippingUrl' => self::step_url( 'shipping' ),
            'paymentUrl' => self::step_url( 'payment' ),
            'initialStep' => self::checkout_step() === 'payment' ? 'payment' : 'shipping',
            'text' => array(
                'firstTitle' => 'آدرس و روش ارسال', 'secondTitle' => 'پرداخت',
                'next' => 'ادامه فرآیند خرید', 'back' => 'آدرس ارسال',
                'missing' => 'لطفاً اطلاعات ضروری را کامل کنید.', 'missingShipping' => 'لطفاً روش ارسال را انتخاب کنید.',
                'noShipping' => 'روش ارسالی برای این آدرس پیدا نشد.',
                'updateError' => 'به‌روزرسانی هزینه ارسال کامل نشد. دوباره تلاش کنید.',
                'savingError' => 'ذخیره آدرس انجام نشد. دوباره تلاش کنید.',
                'deleteError' => 'حذف آدرس انجام نشد. دوباره تلاش کنید.',
            ),
        ) );
    }

    /** WooCommerce also uses /edit-address/billing/ for the native editor.
     * Only render our shared book on the address INDEX endpoint. */
    private static function account_addresses_index(): bool {
        if ( ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page()
            || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'edit-address' ) ) {
            return false;
        }
        return '' === trim( (string) get_query_var( 'edit-address', '' ), '/' );
    }

    /** Display the SAME book read by Shipping checkout, no duplicate storage.
     * WooCommerce has already rendered my-address.php when this action runs.
     * In the active mode a scoped CSS rule hides the redundant native address
     * cards; if JS fails this PHP list remains visible and the default edit link works. */
    public static function render_account_address_book(): void {
        if ( ! self::active() || ! self::account_addresses_index() ) { return; }
        $uid     = get_current_user_id();
        $default = self::default_address( $uid );
        $book    = self::address_book( $uid );
        $all     = array();
        if ( ! empty( $default['billing_address_1'] ) ) { $all[] = $default; }
        foreach ( $book as $address ) {
            if ( ! empty( $address['billing_address_1'] ) ) { $all[] = $address; }
        }
        $count = count( $all );
        $capacity_count = count( $book ) + ( ! empty( $default['billing_address_1'] ) ? 1 : 0 );
        $selected = function_exists( 'WC' ) && WC()->session ? WC()->session->get( self::SELECTED, '' ) : '';
        $state_names = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_states( 'IR' ) : array();
        $state_names = is_array( $state_names ) ? $state_names : array();
        $native_edit = wc_get_endpoint_url( 'edit-address', 'billing', wc_get_page_permalink( 'myaccount' ) );
        ?>
        <section id="dc-account-book" class="dc-account-book" dir="rtl" aria-label="دفترچه نشانی‌های دکاشاپ">
            <div class="dc-account-book-head">
                <div>
                    <h2>نشانی‌های من</h2>
                    <p>نشانی‌های این بخش با نشانی‌های تسویه‌حساب دکاشاپ یکسان‌اند. انتخاب یک نشانی، آدرس اصلی حساب یا سفارش‌های قبلی را تغییر نمی‌دهد.</p>
                    <small><?php echo esc_html( sprintf( '%d نشانی از حداکثر %d نشانی', $count, self::MAX_ADDRESSES ) ); ?></small>
                </div>
                <button type="button" class="button dc-account-book-add" <?php disabled( $capacity_count >= self::MAX_ADDRESSES ); ?>>+ افزودن نشانی</button>
            </div>
            <div class="dc-account-book-notice" role="status" aria-live="polite"></div>
            <div class="dc-account-book-grid">
                <?php foreach ( $all as $item ) :
                    $id = (string) ( $item['id'] ?? '' );
                    $is_primary = 'default' === $id;
                    $state = (string) ( $item['billing_state'] ?? '' );
                    $province = isset( $state_names[ $state ] ) ? (string) $state_names[ $state ] : $state;
                    $address_bits = array_filter( array(
                        $province,
                        (string) ( $item['billing_city'] ?? '' ),
                        (string) ( $item['billing_address_1'] ?? '' ),
                        ! empty( $item['billing_address_4'] ) ? 'پلاک ' . $item['billing_address_4'] : '',
                        ! empty( $item['billing_address_3'] ) ? 'واحد ' . $item['billing_address_3'] : '',
                    ), static function ( $bit ) { return '' !== trim( (string) $bit ); } );
                    $recipient = ( isset( $item['recipient_type'] ) && 'other' === $item['recipient_type'] )
                        ? trim( (string) ( $item['recipient_first_name'] ?? '' ) . ' ' . (string) ( $item['recipient_last_name'] ?? '' ) )
                        : trim( (string) ( $item['billing_first_name'] ?? '' ) . ' ' . (string) ( $item['billing_last_name'] ?? '' ) );
                    ?>
                    <article class="dc-account-book-card<?php echo (string) $selected === $id ? ' dc-account-selected' : ''; ?>" data-id="<?php echo esc_attr( $id ); ?>">
                        <div class="dc-account-book-card-top">
                            <strong><?php echo esc_html( $is_primary ? 'آدرس اصلی' : (string) ( $item['label'] ?? 'نشانی' ) ); ?></strong>
                            <span class="dc-account-book-selected-tag" <?php echo (string) $selected === $id ? '' : 'hidden'; ?>>انتخاب‌شده برای خرید</span>
                        </div>
                        <p class="dc-account-book-street"><?php echo esc_html( implode( '، ', $address_bits ) ); ?></p>
                        <?php if ( '' !== $recipient ) : ?><p class="dc-account-book-person">تحویل‌گیرنده: <?php echo esc_html( $recipient ); ?></p><?php endif; ?>
                        <div class="dc-account-book-actions">
                            <button type="button" class="button dc-book-select" <?php disabled( (string) $selected === $id ); ?>><?php echo (string) $selected === $id ? 'انتخاب‌شده' : 'انتخاب برای خرید'; ?></button>
                            <?php if ( $is_primary ) : ?>
                                <a class="button dc-book-native-edit" href="<?php echo esc_url( $native_edit ); ?>">ویرایش</a>
                            <?php else : ?>
                                <button type="button" class="button dc-book-edit">ویرایش</button>
                            <?php endif; ?>
                            <button type="button" class="button dc-book-delete">حذف</button>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if ( 0 === $count ) : ?><p class="dc-account-book-empty">هنوز هیچ نشانی ذخیره نشده است. اولین نشانی‌ای که اضافه کنید، آدرس اصلی حساب شما خواهد شد.</p><?php endif; ?>
            </div>
            <div class="dc-account-book-foot"><a class="button" href="<?php echo esc_url( self::step_url( 'shipping' ) ); ?>">ادامه خرید با نشانی انتخابی</a><a class="button" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">بازگشت به حساب کاربری</a></div>
            <div class="dc-account-book-dialog" hidden>
                <div class="dc-book-dialog-backdrop"></div>
                <section class="dc-book-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="dc-book-dialog-title">
                    <div class="dc-book-dialog-head"><h3 id="dc-book-dialog-title">افزودن نشانی</h3><button type="button" class="dc-book-close" aria-label="بستن">×</button></div>
                    <form id="dc-book-address-form" novalidate>
                        <div class="dc-book-fields">
                            <label>عنوان نشانی <input name="label" maxlength="60" placeholder="خانه، محل کار و ..." required></label>
                            <div class="dc-book-person-fields" hidden>
                                <label>نام <input name="billing_first_name" autocomplete="given-name"></label>
                                <label>نام خانوادگی <input name="billing_last_name" autocomplete="family-name"></label>
                                <label>شماره تماس <input name="billing_phone" type="tel" autocomplete="tel"></label>
                            </div>
                            <div class="dc-book-two">
                                <label>استان <span class="dc-required">*</span><select name="billing_state" required><option value="">انتخاب استان</option><?php foreach ( $state_names as $code => $name ) : ?><option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( (string) $name ); ?></option><?php endforeach; ?></select></label>
                                <label>شهر <span class="dc-required">*</span><input name="billing_city" required placeholder="نام شهر"></label>
                            </div>
                            <label>نشانی کامل <span class="dc-required">*</span><textarea name="billing_address_1" rows="3" required placeholder="خیابان، کوچه و سایر جزئیات"></textarea></label>
                            <div class="dc-book-two"><label>پلاک <span class="dc-required">*</span><input name="billing_address_4" required></label><label>واحد <span class="dc-required">*</span><input name="billing_address_3" required></label></div>
                            <label>کدپستی <input name="billing_postcode" inputmode="numeric"></label>
                            <label>تحویل‌گیرنده <select name="recipient_type"><option value="self">خودم</option><option value="other">شخص دیگر</option></select></label>
                            <div class="dc-book-recipient-fields" hidden>
                                <label>نام تحویل‌گیرنده <span class="dc-required">*</span><input name="recipient_first_name"></label>
                                <label>نام خانوادگی تحویل‌گیرنده <span class="dc-required">*</span><input name="recipient_last_name"></label>
                                <label>شماره موبایل تحویل‌گیرنده <span class="dc-required">*</span><input name="recipient_phone" type="tel" inputmode="tel"></label>
                            </div>
                        </div>
                        <p class="dc-book-form-error" role="alert" aria-live="assertive"></p>
                        <div class="dc-book-dialog-actions"><button type="submit" class="button dc-book-submit">ذخیره نشانی</button><button type="button" class="button dc-book-close">انصراف</button></div>
                    </form>
                </section>
            </div>
            <div class="dc-book-confirm" hidden>
                <div class="dc-book-dialog-backdrop"></div>
                <section class="dc-book-confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="dc-book-confirm-title">
                    <h3 id="dc-book-confirm-title">حذف نشانی</h3>
                    <p>این نشانی از حساب کاربری حذف می‌شود؛ آدرس سفارش‌های قبلی تغییر نمی‌کند.</p>
                    <p class="dc-book-confirm-error" role="alert"></p>
                    <div class="dc-book-dialog-actions"><button type="button" class="button dc-book-confirm-delete">حذف نشانی</button><button type="button" class="button dc-book-confirm-cancel">انصراف</button></div>
                </section>
            </div>
        </section>
        <?php
    }

    private static function ajax_guard(): int {
        if ( ! self::active() || ! is_user_logged_in() ) { wp_send_json_error( array( 'message' => 'برای ذخیره آدرس وارد حساب شوید.' ), 403 ); }
        check_ajax_referer( 'dcashop_checkout_address_v1', 'nonce' );
        return get_current_user_id();
    }

    /** Prevent using a previously approved shipping draft after address-book changes. */
    private static function invalidate_confirmed_shipping(): void {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( self::SHIPPING_OK );
            WC()->session->__unset( self::SHIPPING_DRAFT );
            WC()->session->__unset( self::SHIPPING_RECIPIENT );
        }
    }

    public static function ajax_address_save(): void {
        $uid = self::ajax_guard();
        $raw = isset( $_POST['address'] ) ? wp_unslash( $_POST['address'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! is_array( $raw ) ) { wp_send_json_error( array( 'message' => 'اطلاعات نامعتبر است.' ), 400 ); }
        $incoming_id = isset( $raw['id'] ) ? sanitize_text_field( $raw['id'] ) : '';
        $address = array( 'id' => $incoming_id, 'label' => isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : 'آدرس جدید' );
        if ( '' === $address['label'] ) { $address['label'] = 'آدرس جدید'; }
        $address['label'] = function_exists( 'mb_substr' ) ? mb_substr( $address['label'], 0, 60 ) : substr( $address['label'], 0, 60 );
        foreach ( self::columns() as $key ) {
            $address[ $key ] = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
        }
        $address['billing_address_1'] = self::clean_street_prefix( $address['billing_address_1'], $address['billing_state'], $address['billing_city'] );
        if ( ! $address['billing_first_name'] || ! $address['billing_last_name'] || ! $address['billing_city'] || ! $address['billing_state'] || ! $address['billing_address_1'] || ! $address['billing_address_4'] || ! $address['billing_address_3'] ) {
            wp_send_json_error( array( 'message' => 'نام، نام خانوادگی، استان، شهر، نشانی، پلاک و واحد ضروری هستند.' ), 400 );
        }
        if ( strlen( $address['billing_map_lat_long'] ) > 80 ) { $address['billing_map_lat_long'] = ''; }
        $address['recipient_type'] = isset( $raw['recipient_type'] ) && 'other' === $raw['recipient_type'] ? 'other' : 'self';
        foreach ( array( 'recipient_first_name', 'recipient_last_name', 'recipient_phone' ) as $key ) {
            $address[$key] = isset( $raw[$key] ) && is_scalar( $raw[$key] ) ? sanitize_text_field( $raw[$key] ) : '';
        }
        if ( 'other' === $address['recipient_type'] && ( ! $address['recipient_first_name'] || ! $address['recipient_last_name'] || ! $address['recipient_phone'] ) ) {
            wp_send_json_error( array( 'message' => 'نام، نام خانوادگی و موبایل تحویل‌گیرنده ضروری هستند.' ), 400 );
        }
        $book = self::address_book( $uid );
        // First new address is the WooCommerce primary address, not an
        // unrelated book-only record invisible under My Account > Addresses.
        if ( '' === $incoming_id && '' === trim( (string) get_user_meta( $uid, 'billing_address_1', true ) ) ) {
            $incoming_id = 'default';
            $address['id'] = 'default';
        }
        if ( 'default' === $incoming_id ) {
            $before_primary = self::default_address( $uid );
            if ( empty( $before_primary['billing_address_1'] ) && count( $book ) >= self::MAX_ADDRESSES ) {
                wp_send_json_error( array( 'message' => 'حداکثر ۵ آدرس شامل آدرس اصلی قابل ذخیره است.' ), 400 );
            }
            // Editing the primary WC address is an explicit user action.
            foreach ( self::columns() as $field ) {
                if ( 'billing_map_lat_long' === $field ) { continue; }
                update_user_meta( $uid, $field, $address[ $field ] );
            }
            // Native Woo address line two is no longer the plaque field.
            update_user_meta( $uid, 'billing_address_2', '' );
            update_user_meta( $uid, self::DEFAULT_EXTRA, array_intersect_key( $address, array_flip( array(
                'billing_map_lat_long', 'label', 'recipient_type', 'recipient_first_name', 'recipient_last_name', 'recipient_phone'
            ) ) ) );
            // Keep the active WC customer object in sync with saved user_meta.
            if ( function_exists( 'WC' ) && WC()->customer && (int) WC()->customer->get_id() === $uid ) {
                foreach ( array( 'first_name', 'last_name', 'phone', 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' ) as $part ) {
                    $setter = 'set_billing_' . $part;
                    if ( is_callable( array( WC()->customer, $setter ) ) ) {
                        WC()->customer->{$setter}( $address['billing_' . $part] ?? '' );
                    }
                }
                WC()->customer->save();
            }
        } else {
            $index = -1;
            foreach ( $book as $i => $item ) {
                if ( isset( $item['id'] ) && $item['id'] === $incoming_id ) { $index = $i; break; }
            }
            if ( $index < 0 ) {
                $existing_primary = self::default_address( $uid );
                $primary_count = ! empty( $existing_primary['billing_address_1'] ) ? 1 : 0;
                if ( count( $book ) + $primary_count >= self::MAX_ADDRESSES ) {
                    wp_send_json_error( array( 'message' => 'حداکثر ۵ آدرس شامل آدرس اصلی می‌توانید ذخیره کنید. برای ثبت آدرس جدید، یکی از آدرس‌های قبلی را حذف کنید.' ), 400 );
                }
                $address['id'] = wp_generate_uuid4();
                $book[] = $address;
            } else {
                $book[ $index ] = $address;
            }
            update_user_meta( $uid, self::BOOK, array_values( $book ) );
        }
        if ( WC()->session ) { WC()->session->set( self::SELECTED, $address['id'] ); }
        self::invalidate_confirmed_shipping();
        wp_send_json_success( array( 'address' => $address, 'addresses' => $book, 'defaultAddress' => self::default_address( $uid ) ) );
    }


    public static function ajax_address_delete(): void {
        $uid = self::ajax_guard();
        $id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
        if ( '' === $id ) { wp_send_json_error( array( 'message' => 'شناسه آدرس نامعتبر است.' ), 400 ); }
        $book = self::address_book( $uid );
        if ( 'default' === $id ) {
            // Keep the Mobits account name/phone intact. Delete only the saved destination.
            foreach ( array( 'billing_state', 'billing_city', 'billing_address_1', 'billing_address_4', 'billing_address_3', 'billing_postcode' ) as $key ) {
                update_user_meta( $uid, $key, '' );
            }
            delete_user_meta( $uid, self::DEFAULT_EXTRA );
            // Clear any cached WooCommerce customer destination, not the Mobits identity.
            if ( function_exists( 'WC' ) && WC()->customer ) {
                $customer = WC()->customer;
                $customer->set_billing_state( '' );
                $customer->set_billing_city( '' );
                $customer->set_billing_address_1( '' );
                $customer->set_billing_address_2( '' );
                $customer->set_billing_postcode( '' );
                $customer->save();
            }
        } else {
            $remaining = array_values( array_filter( $book, function ( $item ) use ( $id ) {
                return ! isset( $item['id'] ) || $item['id'] !== $id;
            } ) );
            if ( count( $remaining ) === count( $book ) ) {
                wp_send_json_error( array( 'message' => 'آدرس یافت نشد.' ), 404 );
            }
            $book = $remaining;
            update_user_meta( $uid, self::BOOK, $book );
        }
        $default = self::default_address( $uid );
        $next = $default['billing_address_1'] ? 'default' : ( isset( $book[0]['id'] ) ? $book[0]['id'] : '' );
        $selected = WC()->session ? WC()->session->get( self::SELECTED ) : '';
        if ( $id === $selected && WC()->session ) { WC()->session->set( self::SELECTED, $next ); }
        self::invalidate_confirmed_shipping();
        wp_send_json_success( array( 'addresses' => $book, 'defaultAddress' => $default,
            'selected' => $id === $selected ? $next : $selected ) );
    }

    /**
     * Present the selected WooCommerce shipping rate without changing its charge.
     * Some carriers (e.g. freight and Tipax) have 0 online cost because delivery
     * is paid later. A zero-priced method is NOT necessarily free shipping.
     */
    private static function shipping_cost_display( $cart ): string {
        if ( ! $cart->needs_shipping() ) { return 'بدون نیاز به ارسال'; }
        $chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        if ( ! is_array( $chosen ) || ! count( array_filter( $chosen ) ) ) {
            return 'پس از انتخاب روش ارسال';
        }

        $packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
        $has_price = false;
        $all_named_free = true;
        $matched = 0;
        $rate_cost = 0.0;
        foreach ( $chosen as $index => $rate_id ) {
            if ( ! is_scalar( $rate_id ) || '' === (string) $rate_id ) { continue; }
            $rate = isset( $packages[ $index ]['rates'][ (string) $rate_id ] ) ? $packages[ $index ]['rates'][ (string) $rate_id ] : null;
            if ( ! $rate ) {
                $all_named_free = false;
                continue;
            }
            ++$matched;
            $label = method_exists( $rate, 'get_label' ) ? wp_strip_all_tags( $rate->get_label() ) : '';
            $label = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), (string) $label );
            if ( false === strpos( $label, 'رایگان' ) ) { $all_named_free = false; }
            $cost = method_exists( $rate, 'get_cost' ) ? (float) $rate->get_cost() : 0.0;
            if ( $cost > 0 ) { $has_price = true; $rate_cost += $cost; }
        }

        // Display the Persian word for 'free' only when a shipping method is explicitly labeled free.
        if ( $matched > 0 && $all_named_free ) { return 'رایگان'; }
        $cart_shipping = (float) $cart->get_shipping_total();
        $cart_shipping_tax = (float) $cart->get_shipping_tax();
        if ( $has_price || $cart_shipping > 0 || $cart_shipping_tax > 0 ) {
            if ( $cart_shipping > 0 || $cart_shipping_tax > 0 ) {
                return $cart->get_cart_shipping_total();
            }
            return wc_price( $rate_cost );
        }
        return 'پس کرایه';
    }

    public static function render_checkout_totals_rows(): void {
        if ( ! self::active() || ! self::on_checkout() || ! function_exists( 'WC' ) || ! WC()->cart ) { return; }
        $cart = WC()->cart;
        echo '<tr class="dc-review-subtotal"><th>جمع جزء</th><td>' . wp_kses_post( $cart->get_cart_subtotal() ) . '</td></tr>';
        echo '<tr class="dc-review-shipping"><th>هزینه ارسال</th><td>' . wp_kses_post( self::shipping_cost_display( $cart ) ) . '</td></tr>';
    }

    private static function selected_address_for_order(): ?array {
        if ( ! is_user_logged_in() ) { return null; }
        return self::selected_address( get_current_user_id() );
    }

    /** On payment submission restore the *confirmed* shipping address. */
    public static function use_approved_checkout_address( $data ) {
        if ( ! is_array( $data ) || ! self::active() || ! function_exists( 'WC' ) || ! WC()->session || ! self::confirmed_shipping() ) { return $data; }
        $draft = WC()->session->get( self::SHIPPING_DRAFT, array() );
        if ( ! is_array( $draft ) ) { return $data; }
        foreach ( self::columns() as $field ) {
            if ( isset( $draft[ $field ] ) && is_scalar( $draft[ $field ] ) ) { $data[ $field ] = (string) $draft[ $field ]; }
        }
        // There is one customer-selected destination. Do not let a legacy
        // shipping form secretly override it with the customer's old address.
        $data['ship_to_different_address'] = 0;
        foreach ( array( 'first_name', 'last_name', 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ) as $field ) {
            $key = 'billing_' . $field;
            if ( isset( $draft[ $key ] ) ) { $data['shipping_' . $field] = (string) $draft[ $key ]; }
        }
        return $data;
    }

    /** Only the explicit address editor may change the user's primary address. */
    public static function preserve_address_book_account( $should_update ) {
        if ( self::active() && function_exists( 'WC' ) && WC()->session && self::confirmed_shipping() ) { return false; }
        return $should_update;
    }

    private static function approved_shipping_address(): ?array {
        if ( ! function_exists( 'WC' ) || ! WC()->session || ! self::confirmed_shipping() ) { return null; }
        $draft = WC()->session->get( self::SHIPPING_DRAFT, array() );
        return is_array( $draft ) && self::draft_is_complete( $draft ) ? $draft : null;
    }

    /** Store a complete and immutable destination in the WC order itself. */
    public static function add_recipient_to_order( $order, $data ): void {
        if ( ! self::active() || ! $order instanceof WC_Order ) { return; }
        $draft = self::approved_shipping_address();
        if ( ! $draft ) {
            // Fail closed rather than silently taking the customer's unrelated
            // primary billing/shipping address after a stale session.
            throw new Exception( 'آدرس ارسال تأییدشده در دسترس نیست. لطفاً به مرحله آدرس ارسال برگردید.' );
        }
        $val = static function ( $key ) use ( $draft ): string {
            return sanitize_text_field( (string) ( $draft[ $key ] ?? '' ) );
        };
        $plaque = $val( 'billing_address_4' );
        $unit   = $val( 'billing_address_3' );
        $street = self::clean_street_prefix( $val( 'billing_address_1' ), $val( 'billing_state' ), $val( 'billing_city' ) );
        $details = array();
        if ( '' !== $plaque ) { $details[] = 'پلاک ' . $plaque; }
        if ( '' !== $unit ) { $details[] = 'واحد ' . $unit; }
        $details_line = implode( '، ', $details );

        // Billing: keep account holder / buyer contact intact, but write the
        // actual submitted destination rather than the previous billing street.
        foreach ( array( 'first_name', 'last_name', 'phone', 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ) as $field ) {
            $setter = 'set_billing_' . $field;
            if ( is_callable( array( $order, $setter ) ) ) { $order->{$setter}( 'address_1' === $field ? $street : $val( 'billing_' . $field ) ); }
        }
        $order->update_meta_data( '_billing_address_4', $plaque );
        $order->update_meta_data( '_billing_address_3', $unit );
        // Retain the original coordinate metadata key for existing orders and integrations.
        // Orders remain independent of the user's mutable address book.
        $coordinates = $val( 'billing_map_lat_long' );
        if ( preg_match( '/^\s*([0-9]{1,2}(?:\.[0-9]+)?),\s*([0-9]{1,2}(?:\.[0-9]+)?)\s*$/', $coordinates, $matches )
            && (float) $matches[1] >= 24 && (float) $matches[1] <= 41
            && (float) $matches[2] >= 43 && (float) $matches[2] <= 64 ) {
            $order->update_meta_data( '_billing_map_lat_long', $coordinates );
            $order->update_meta_data( '_dcashop_delivery_coordinates', $coordinates );
        }

        $recipient = WC()->session->get( self::SHIPPING_RECIPIENT, array() );
        if ( ! is_array( $recipient ) ) { $recipient = array(); }
        $other = ( $recipient['recipient_type'] ?? '' ) === 'other';
        $first = $other ? sanitize_text_field( (string) ( $recipient['recipient_first_name'] ?? '' ) ) : $val( 'billing_first_name' );
        $last  = $other ? sanitize_text_field( (string) ( $recipient['recipient_last_name'] ?? '' ) ) : $val( 'billing_last_name' );
        $phone = $other ? sanitize_text_field( (string) ( $recipient['recipient_phone'] ?? '' ) ) : $val( 'billing_phone' );
        if ( '' === $first || '' === $last || '' === $phone ) {
            throw new Exception( 'اطلاعات تحویل‌گیرنده کامل نیست. لطفاً آدرس را دوباره تأیید کنید.' );
        }
        $order->set_shipping_first_name( $first );
        $order->set_shipping_last_name( $last );
        $order->set_shipping_country( $val( 'billing_country' ) ?: 'IR' );
        $order->set_shipping_state( $val( 'billing_state' ) );
        $order->set_shipping_city( $val( 'billing_city' ) );
        $order->set_shipping_postcode( $val( 'billing_postcode' ) );
        $order->set_shipping_address_1( $street );
        // Line two is visible in standard WC formatted shipping addresses,
        // shipping-label plugins, and most invoicing integrations.
        $order->set_shipping_address_2( $details_line );
        if ( is_callable( array( $order, 'set_shipping_phone' ) ) ) { $order->set_shipping_phone( $phone ); }

        $order->update_meta_data( '_dcashop_delivery_plaque', $plaque );
        $order->update_meta_data( '_dcashop_delivery_unit', $unit );
        $order->update_meta_data( '_dcashop_delivery_recipient_type', $other ? 'other' : 'self' );
        $order->update_meta_data( '_dcashop_delivery_recipient_first_name', $first );
        $order->update_meta_data( '_dcashop_delivery_recipient_last_name', $last );
        $order->update_meta_data( '_dcashop_delivery_recipient_phone', $phone );
        $order->update_meta_data( '_dcashop_delivery_confirmed_address', $draft );
    }

    /** Save pending order before payment/notification hooks can interrupt checkout.
     * WooCommerce's classic checkout already reuses order_awaiting_payment when
     * the cart hash and order status still match. Do not change any order status.
     */
    public static function remember_pending_checkout_order( $order_id, $posted_data = array(), $order = null ): void {
        if ( ! self::active() || ! function_exists( 'WC' ) || ! WC()->session ) { return; }
        if ( ! $order instanceof WC_Order ) { $order = wc_get_order( absint( $order_id ) ); }
        if ( ! $order instanceof WC_Order || ! $order->needs_payment() ) { return; }
        if ( ! $order->get_meta( '_dcashop_delivery_confirmed_address' ) ) { return; }
        WC()->session->set( 'order_awaiting_payment', $order->get_id() );
    }

    public static function clear_confirmed_shipping_after_order(): void {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( self::SHIPPING_OK );
            WC()->session->__unset( self::SHIPPING_DRAFT );
            WC()->session->__unset( self::SHIPPING_RECIPIENT );
        }
    }

    /** Show editable delivery details under the standard shipping address.
     * WooCommerce's own order address editor remains intact. */
    public static function render_admin_recipient( $order ): void {
        if ( ! $order instanceof WC_Order || ! $order->get_meta( '_dcashop_delivery_confirmed_address' ) ) { return; }
        $plaque = (string) $order->get_meta( '_dcashop_delivery_plaque' );
        $unit = (string) $order->get_meta( '_dcashop_delivery_unit' );
        wp_nonce_field( 'dcashop_delivery_order_' . $order->get_id(), 'dcashop_delivery_order_nonce' );
        // An ordinary collapsed details control is the no-JS fallback. In Woo's
        // order editor, JS moves this block into the existing shipping edit panel,
        // which is controlled by WooCommerce's native pencil icon.
        echo '<details class="dcashop-admin-delivery-edit" style="clear:both;margin-top:8px;">';
        echo '<summary style="cursor:pointer;font-weight:600;">✎ ویرایش پلاک و واحد</summary>';
        echo '<div class="dcashop-admin-delivery-fields" style="padding-top:10px;">';
        echo '<p class="form-field form-field-wide"><label for="dcashop_delivery_plaque">پلاک</label> ';
        echo '<input type="text" id="dcashop_delivery_plaque" name="dcashop_delivery_plaque" value="' . esc_attr( $plaque ) . '" style="width:100%;max-width:320px;" /></p>';
        echo '<p class="form-field form-field-wide"><label for="dcashop_delivery_unit">واحد</label> ';
        echo '<input type="text" id="dcashop_delivery_unit" name="dcashop_delivery_unit" value="' . esc_attr( $unit ) . '" style="width:100%;max-width:320px;" /></p>';
        echo '<p class="description">پس از تغییر پلاک یا واحد، سفارش را به‌روزرسانی کنید تا نشانی حمل‌ونقل و برچسب چاپی اصلاح شوند.</p>';
        echo '</div></details>';
        $phone = (string) $order->get_meta( '_dcashop_delivery_recipient_phone' );
        if ( '' !== $phone ) { echo '<p><strong>تلفن تحویل‌گیرنده:</strong> ' . esc_html( $phone ) . '</p>'; }
        if ( 'other' === $order->get_meta( '_dcashop_delivery_recipient_type' ) ) {
            echo '<p><strong>تحویل‌گیرنده دیگر:</strong> ' . esc_html( trim( $order->get_meta( '_dcashop_delivery_recipient_first_name' ) . ' ' . $order->get_meta( '_dcashop_delivery_recipient_last_name' ) ) ) . '</p>';
        }
    }

    /** When available, reuse WooCommerce's existing shipping pencil editor.
     * The native order shipping address block has a collapsed div.edit_address.
     * If the markup differs, <details> remains usable without JavaScript.
     */
    public static function attach_admin_delivery_to_native_editor(): void {
        if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) { return; }
        ?>
        <script>
        jQuery(function($){
            $('.dcashop-admin-delivery-edit').each(function(){
                var $details = $(this);
                var $column = $details.closest('.order_data_column');
                var $editor = $column.find('div.edit_address').first();
                if (!$editor.length) return;
                $details.appendTo($editor).prop('open', true);
                $details.children('summary').hide();
            });
        });
        </script>
        <?php
    }

    /** The WC order editor verifies its own permissions; verify our additional
     * nonce as well. Use WC CRUD for HPOS / legacy storage compatibility. */
    public static function save_admin_shipping_details( $order_id, $post = null ): void {
        if ( ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'edit_post', $order_id ) ) { return; }
        if ( ! isset( $_POST['dcashop_delivery_order_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dcashop_delivery_order_nonce'] ) ), 'dcashop_delivery_order_' . $order_id ) ) { return; }
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->get_meta( '_dcashop_delivery_confirmed_address' ) ) { return; }
        // When the manager changes unrelated order fields (or edits the raw
        // shipping address), never rewrite the shipping address_2 from stale input.
        if ( ! isset( $_POST['dcashop_delivery_plaque'], $_POST['dcashop_delivery_unit'] ) ) { return; }
        $plaque = isset( $_POST['dcashop_delivery_plaque'] ) ? sanitize_text_field( wp_unslash( $_POST['dcashop_delivery_plaque'] ) ) : '';
        $unit = isset( $_POST['dcashop_delivery_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['dcashop_delivery_unit'] ) ) : '';
        if ( $plaque === (string) $order->get_meta( '_dcashop_delivery_plaque' ) &&
             $unit === (string) $order->get_meta( '_dcashop_delivery_unit' ) ) { return; }
        $parts = array();
        if ( '' !== $plaque ) { $parts[] = 'پلاک ' . $plaque; }
        if ( '' !== $unit ) { $parts[] = 'واحد ' . $unit; }
        $order->update_meta_data( '_dcashop_delivery_plaque', $plaque );
        $order->update_meta_data( '_dcashop_delivery_unit', $unit );
        $order->set_shipping_address_2( implode( '، ', $parts ) );
        $order->save();
    }

    /** Format Woo account address line 2 from named plaque and unit meta, without repurposing the Woo field. */
    public static function format_account_address( $address, $user_id, $address_type ) {
        if ( 'billing' !== $address_type || ! is_array( $address ) ) { return $address; }
        $plaque = trim( (string) get_user_meta( (int) $user_id, 'billing_address_4', true ) );
        if ( '' === $plaque ) { $plaque = trim( (string) get_user_meta( (int) $user_id, 'billing_address_2', true ) ); } // legacy only
        $unit = trim( (string) get_user_meta( (int) $user_id, 'billing_address_3', true ) );
        if ( '' === $plaque && '' === $unit ) { return $address; }
        $parts = array();
        if ( '' !== $plaque ) { $parts[] = 'پلاک ' . $plaque; }
        if ( '' !== $unit ) { $parts[] = 'واحد ' . $unit; }
        $address['address_2'] = implode( '، ', $parts );
        return $address;
    }

    /** Give plaque/unit a readable label in the order's billing address too;
     * keep WooCommerce raw fields untouched so admins can still edit them. */
    public static function format_order_billing_address( $address, $order ) {
        if ( ! is_array( $address ) || ! $order instanceof WC_Order ) { return $address; }
        if ( ! $order->get_meta( '_dcashop_delivery_confirmed_address' ) ) { return $address; }
        $plaque = trim( (string) $order->get_meta( '_dcashop_delivery_plaque' ) );
        $unit = trim( (string) $order->get_meta( '_dcashop_delivery_unit' ) );
        $parts = array();
        if ( '' !== $plaque ) { $parts[] = 'پلاک ' . $plaque; }
        if ( '' !== $unit ) { $parts[] = 'واحد ' . $unit; }
        if ( $parts ) { $address['address_2'] = implode( '، ', $parts ); }
        return $address;
    }

    /** Expose the WC-compatible extra address field on My Account editor too.
     * YITH sometimes adds it only to checkout, not account editing. */
    public static function account_address_fields( $fields, $country = '' ) {
        if ( ! is_array( $fields ) || ! function_exists( 'is_account_page' ) || ! is_account_page() ||
             ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'edit-address' ) ) { return $fields; }
        // Keep the canonical WC address_2 (second address line) separate from plaque.
        $fields['billing_address_4'] = array_merge( array(
            'label' => 'پلاک', 'type' => 'text', 'required' => true,
            'class' => array( 'form-row-first' ), 'priority' => 64,
        ), isset( $fields['billing_address_4'] ) ? $fields['billing_address_4'] : array() );
        $fields['billing_address_4']['label'] = 'پلاک';
        $fields['billing_address_4']['required'] = true;
        if ( ! isset( $fields['billing_address_3'] ) ) {
            $fields['billing_address_3'] = array( 'label' => 'واحد', 'type' => 'text', 'required' => true,
                'class' => array( 'form-row-last' ), 'priority' => 65 );
        }
        return $fields;
    }

    public static function save_account_unit_field( $user_id, $load_address ) : void {
        if ( 'billing' !== $load_address || (int) $user_id !== get_current_user_id() ) { return; }
        foreach ( array( 'billing_address_4', 'billing_address_3' ) as $key ) {
            if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
                update_user_meta( (int) $user_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
            }
        }
    }

    public static function ajax_address_select(): void {
        $uid = self::ajax_guard();
        $id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $found = 'default' === $id;
        if ( ! $found ) {
            foreach ( self::address_book( $uid ) as $item ) {
                if ( isset( $item['id'] ) && $item['id'] === $id ) { $found = true; break; }
            }
        }
        if ( ! $found ) { wp_send_json_error( array( 'message' => 'آدرس یافت نشد.' ), 404 ); }
        if ( WC()->session ) {
            WC()->session->set( self::SELECTED, $id );
            // My Account can initiate the session before Woo has written a
            // session cookie. Persist selection across the checkout redirect.
            if ( is_callable( array( WC()->session, 'set_customer_session_cookie' ) ) ) {
                WC()->session->set_customer_session_cookie( true );
            }
        }
        self::invalidate_confirmed_shipping();
        wp_send_json_success( array( 'id' => $id ) );
    }
}

register_activation_hook( __FILE__, array( 'DCAShop_Two_Step_Checkout', 'on_activation' ) );
add_action( 'plugins_loaded', array( 'DCAShop_Two_Step_Checkout', 'boot' ) );
