<?php
/**
 * Billing Fields — the customer identification Alegra needs for WooCommerce.
 *
 * WooCommerce already collects name, email, phone and address. The only thing
 * Alegra needs that WooCommerce does not collect is the customer's
 * identification (document type + number, plus the verification digit for a
 * NIT). This class holds that catalog, registers the WooCommerce hooks that
 * render/validate/save it, and builds the contact payloads sent to Alegra.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Billing_Fields
{
    /**
     * Option name that stores which catalog fields are enabled.
     */
    public const OPTION_ENABLED = 'alegra_connector_billing_field_catalog_enabled';

    /**
     * Options controlling the Colombia fiscal fields sent with every contact.
     *
     * Colombia documents TWO contact schemas:
     *  - "sin facturación electrónica": only `name` is required.
     *  - "con facturación electrónica": `identificationObject`, `regime` and
     *    `kindOfPerson` are required.
     *
     * GET /company exposes NO reliable "e-invoicing enabled" flag, and both
     * fields are valid properties in BOTH schemas, so the plugin always sends
     * `regime` + `kindOfPerson` for a CO account. Sending a valid extra field
     * is safer than omitting a field the FE schema requires (which would 400
     * and silently fall back to Consumidor Final).
     *
     * @see https://developer.alegra.com/reference/post_contacts.md
     * @see https://developer.alegra.com/docs/colombia.md
     */
    public const OPTION_KIND_OF_PERSON = 'alegra_connector_contact_kind_of_person';
    public const OPTION_REGIME         = 'alegra_connector_contact_regime';

    /**
     * `kindOfPerson` enum (post_contacts.md, Colombia).
     */
    public const KIND_OF_PERSONS = ['PERSON_ENTITY', 'LEGAL_ENTITY', 'OTHER_ENTITY'];

    /**
     * `regime` enum (post_contacts.md, Colombia). `NOT_REPONSIBLE_FOR_CONSUMPTION`
     * keeps the exact spelling used by the official docs.
     */
    public const REGIMES = [
        'SIMPLIFIED_REGIME',
        'COMMON_REGIME',
        'NATIONAL_CONSUMPTION_TAX',
        'NOT_REPONSIBLE_FOR_CONSUMPTION',
        'INC_IVA_RESPONSIBLE',
        'SPECIAL_REGIME',
    ];

    /**
     * Pre-defined billing field catalog.
     *
     * The identification is the only billing datum WooCommerce does not
     * already collect. Everything else (name, email, phone, address) comes
     * from WooCommerce's own billing fields.
     */
    public const CATALOG = [
        'idtype' => [
            'meta_key' => 'billing_alegra_idtype', 'label' => 'Tipo de documento',
            'alegra_field' => 'identificationObject.type', 'type' => 'select',
            'options' => [],
            'required' => true, 'group' => 'A', 'render_when' => 'always',
            'help' => 'El tipo de identificación del cliente.',
            'help_url' => '',
        ],
        'identification' => [
            'meta_key' => 'billing_alegra_identification', 'label' => 'Número de documento',
            'alegra_field' => 'identificationObject.number', 'type' => 'text',
            'required' => true, 'group' => 'A', 'render_when' => 'always',
            'help' => 'El número de identificación sin puntos ni espacios.',
            'help_url' => '',
        ],
        'dv' => [
            'meta_key' => 'billing_alegra_dv', 'label' => 'Dígito de verificación',
            'alegra_field' => 'identificationObject.dv', 'type' => 'text',
            'required' => false, 'group' => 'A', 'render_when' => 'idtype_nit',
            'help' => 'Solo para NIT. Es el dígito que aparece después del NIT.',
            'help_url' => '',
        ],
    ];

    /**
     * Identification types accepted by Alegra (union of natural person and
     * legal entity types; the person type is no longer collected).
     */
    public const ID_TYPES = [
        'RC' => 'Registro civil', 'TI' => 'Tarjeta de identidad', 'CC' => 'Cédula de ciudadanía',
        'TE' => 'Tarjeta de extranjería', 'CE' => 'Cédula de extranjería', 'PP' => 'Pasaporte',
        'PEP' => 'Permiso especial de permanencia', 'DIE' => 'Documento de identificación extranjero',
        'NUIP' => 'NUIP', 'NIT' => 'NIT', 'FOREIGN_NIT' => 'NIT de otro país',
    ];

    /**
     * Register the WooCommerce hooks for rendering, validating and saving
     * the billing fields. Idempotent.
     */
    public static function register_hooks(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        add_action('woocommerce_register_form', static function (): void {
            self::render_registration_fields();
        });
        add_filter('woocommerce_checkout_fields', static function (array $fields): array {
            return self::render_checkout_fields($fields);
        });
        add_action('woocommerce_edit_account_form', static function (): void {
            self::render_account_fields();
        });

        add_action('woocommerce_register_post', static function ($username, $email, $errors): void {
            self::on_register_post($username, $email, $errors);
        }, 10, 3);
        add_action('woocommerce_checkout_process', static function (): void {
            self::on_checkout_process();
        });

        add_action('woocommerce_created_customer', static function (int $customer_id): void {
            self::save_registration($customer_id);
        }, 10, 1);
        add_action('woocommerce_checkout_update_order_meta', static function (int $order_id): void {
            self::save_checkout($order_id);
        }, 10, 1);
        // Checkout Blocks / Store API path: WooCommerce persists additional
        // checkout fields through CheckoutFields and fires this action for each
        // value it saves. Mirrors the value into the `billing_alegra_*` shape.
        add_action('woocommerce_set_additional_field_value', static function ($key, $value, $group, $wc_object): void {
            self::save_additional_field((string) $key, $value, (string) $group, $wc_object);
        }, 10, 4);
        // Checkout Blocks: address additional fields arrive inside the
        // `billing_address` request param. This runs before the order is saved,
        // so mirroring here covers guests too (the action above only sees the
        // order when WC syncs a logged-in customer's fields).
        add_action('woocommerce_store_api_checkout_update_order_from_request', static function ($order, $request): void {
            if ($order instanceof \WC_Order && $request instanceof \WP_REST_Request) {
                self::save_blocks_checkout($order, $request);
            }
        }, 10, 2);
        add_action('woocommerce_save_account_details', static function (int $user_id): void {
            self::save_account($user_id);
        }, 10, 1);
    }

    /**
     * Whether a catalog field is enabled in the stored option.
     */
    public static function is_field_enabled(string $key): bool
    {
        $opts = get_option(self::OPTION_ENABLED, []);
        if (!is_array($opts)) {
            $opts = [];
        }

        return !empty($opts[$key]);
    }

    /**
     * Whether the store requires billing data at checkout (`require_data` mode).
     *
     * In `auto` mode an incomplete form falls back to Consumidor Final and in
     * `always_generic` the data is ignored, so only `require_data` may hard-block
     * checkout.
     */
    public static function is_require_data_mode(): bool
    {
        return (string) get_option('alegra_connector_customer_resolution_mode', 'auto') === 'require_data';
    }

    /**
     * Read a billing field value from a user's meta.
     */
    public static function get_field_value(int $user_id, string $key): string
    {
        if (!isset(self::CATALOG[$key])) {
            return '';
        }

        return (string) get_user_meta($user_id, self::CATALOG[$key]['meta_key'], true);
    }

    /**
     * Persist a billing field value into a user's meta, sanitized.
     */
    public static function set_field_value(int $user_id, string $key, string $value): void
    {
        if (!isset(self::CATALOG[$key])) {
            return;
        }

        $field = self::CATALOG[$key];
        $sanitized = $field['type'] === 'textarea'
            ? sanitize_textarea_field($value)
            : sanitize_text_field($value);

        update_user_meta($user_id, $field['meta_key'], $sanitized);
    }

    /**
     * Whether the critical identification fields are all present.
     *
     * Requires idType + identification, and DV when idType is NIT.
     */
    public static function has_critical_data(array $values): bool
    {
        $idtype = self::as_string($values['idtype'] ?? '');
        $identification = self::as_string($values['identification'] ?? '');

        // AC-26/AC-38: a DISABLED field is never required. Group A is
        // force-enabled by the settings sanitizer, so in practice these are
        // always required; the gate exists so a disabled field can never block
        // checkout/registration.
        if (self::is_field_enabled('idtype') && $idtype === '') {
            return false;
        }
        if (self::is_field_enabled('identification') && $identification === '') {
            return false;
        }
        if (self::is_field_enabled('dv') && $idtype === 'NIT' && self::as_string($values['dv'] ?? '') === '') {
            return false;
        }

        return true;
    }

    /**
     * Validate and sanitize the billing fields.
     *
     * @param array $input Values keyed by catalog key or by prefixed meta key.
     * @return array|\WP_Error Sanitized values keyed by catalog key, or an error.
     */
    public static function validate(array $input): array|\WP_Error
    {
        $values = self::normalize_input($input);

        // AC-26/AC-38: every requirement below is gated on the field being
        // ENABLED. A disabled field must never be required — enabling only
        // optional fields must not block checkout/registration.
        $idtype = self::as_string($values['idtype']);
        if (self::is_field_enabled('idtype') && ($idtype === '' || !isset(self::ID_TYPES[$idtype]))) {
            return new \WP_Error(
                'invalid_idtype',
                __('Seleccione un tipo de documento válido.', 'alegra-connector')
            );
        }

        $identification = trim(self::as_string($values['identification']));
        if (self::is_field_enabled('identification') && $identification === '') {
            return new \WP_Error('missing_identification', __('El número de documento es obligatorio.', 'alegra-connector'));
        }

        if ($identification !== '' && $idtype !== '') {
            $identification_error = self::check_identification($idtype, $identification);
            if ($identification_error !== null) {
                return $identification_error;
            }
        }

        $dv = trim(self::as_string($values['dv']));
        if (self::is_field_enabled('dv') && $idtype === 'NIT') {
            if (!preg_match('/^\d$/', $dv)) {
                return new \WP_Error(
                    'invalid_dv',
                    __('El dígito de verificación (DV) debe ser un solo dígito (0-9)', 'alegra-connector')
                );
            }
        } elseif ($dv !== '') {
            return new \WP_Error(
                'unexpected_dv',
                __('El dígito de verificación (DV) solo aplica para NIT.', 'alegra-connector')
            );
        }

        return self::sanitize_values($values);
    }

    /**
     * Build the Alegra contact payload for a registered user.
     *
     * @return array|\WP_Error
     */
    public static function build_contact_payload(\WP_User $user): array|\WP_Error
    {
        $user_id = (int) $user->ID;

        // AC-38: only ENABLED fields are collected; a disabled field is never
        // sent to Alegra.
        $values = [];
        foreach (self::CATALOG as $key => $field) {
            $values[$key] = self::is_field_enabled($key) ? self::get_field_value($user_id, $key) : '';
        }

        $contact = [
            'first_name' => (string) get_user_meta($user_id, 'billing_first_name', true),
            'last_name' => (string) get_user_meta($user_id, 'billing_last_name', true),
            'display_name' => (string) $user->display_name,
            'email' => (string) $user->user_email,
            'phone' => (string) get_user_meta($user_id, 'billing_phone', true),
            'address' => [
                'address' => (string) get_user_meta($user_id, 'billing_address_1', true),
                'city' => (string) get_user_meta($user_id, 'billing_city', true),
                'state' => (string) get_user_meta($user_id, 'billing_state', true),
                'postcode' => (string) get_user_meta($user_id, 'billing_postcode', true),
            ],
        ];

        return self::build_payload($values, $contact);
    }

    /**
     * Build a minimal Alegra contact payload for a customer without the
     * e-invoicing billing data.
     *
     * Naming-drift collapse: Customers::prepare_customer_data() used to build
     * its own `['name','email','type']` payload, so the contact shape lived in
     * two places. The generic shape is defined here now.
     *
     * @return array<string, string>
     */
    public static function build_minimal_contact_payload(\WP_User $user): array
    {
        $name = trim((string) $user->display_name);
        if ($name === '') {
            $name = (string) $user->user_email;
        }

        return [
            'name'  => $name,
            'email' => (string) $user->user_email,
            'type'  => 'client',
        ];
    }

    /**
     * Build the Alegra contact payload for a guest order.
     *
     * @return array|\WP_Error
     */
    public static function build_guest_contact_payload(\WC_Order $order): array|\WP_Error
    {
        // AC-38: only ENABLED fields are collected; a disabled field is never
        // sent to Alegra.
        $values = [];
        foreach (self::CATALOG as $key => $field) {
            $values[$key] = self::is_field_enabled($key)
                ? (string) $order->get_meta('_' . $field['meta_key'], true)
                : '';
        }

        $contact = [
            'first_name' => (string) $order->get_billing_first_name(),
            'last_name' => (string) $order->get_billing_last_name(),
            'display_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'email' => (string) $order->get_billing_email(),
            'phone' => (string) $order->get_billing_phone(),
            'address' => [
                'address' => (string) $order->get_billing_address_1(),
                'city' => (string) $order->get_billing_city(),
                'state' => (string) $order->get_billing_state(),
                'postcode' => (string) $order->get_billing_postcode(),
            ],
        ];

        return self::build_payload($values, $contact);
    }

    /**
     * Whether the connected Alegra company is Colombian.
     *
     * Colombia uses `identificationObject` (a structured type/number/dv) while
     * other countries use the generic flat `identification` string. The
     * country is stored when the connection is tested; when it is not known
     * yet the plugin keeps its CO-first default.
     */
    public static function is_colombia_account(): bool
    {
        $country = strtoupper(trim((string) get_option('alegra_connector_company_country', '')));
        if ($country !== '') {
            return in_array($country, ['CO', 'COLOMBIA'], true);
        }

        return true;
    }

    /**
     * Resolve the `kindOfPerson` sent for a CO contact.
     *
     * Defaults to PERSON_ENTITY (natural person), the common case for a
     * WooCommerce storefront. The merchant can override it in Advanced
     * settings for a B2B-only store.
     */
    public static function resolve_kind_of_person(): string
    {
        $value = strtoupper(trim((string) get_option(self::OPTION_KIND_OF_PERSON, '')));

        return in_array($value, self::KIND_OF_PERSONS, true) ? $value : 'PERSON_ENTITY';
    }

    /**
     * Resolve the `regime` sent for a CO contact.
     *
     * Defaults to SIMPLIFIED_REGIME (no responsable de IVA), the common case
     * for a small Colombian storefront. Overridable in Advanced settings.
     */
    public static function resolve_regime(): string
    {
        $value = strtoupper(trim((string) get_option(self::OPTION_REGIME, 'SIMPLIFIED_REGIME')));

        return in_array($value, self::REGIMES, true) ? $value : 'SIMPLIFIED_REGIME';
    }

    /**
     * Render the billing fields inside the registration form.
     */
    private static function render_registration_fields(): void
    {
        if (!self::has_enabled_fields()) {
            return;
        }

        echo '<div class="alegra-billing-fields alegra-billing-fields--register">';
        echo '<h3>' . esc_html__('Datos de facturación', 'alegra-connector') . '</h3>';

        foreach (self::CATALOG as $key => $field) {
            if (!self::is_field_enabled($key)) {
                continue;
            }
            self::render_field($key, $field);
        }

        echo '</div>';
    }

    /**
     * Inject the billing fields into the WooCommerce checkout fields array.
     */
    private static function render_checkout_fields(array $fields): array
    {
        if (!isset($fields['billing']) || !is_array($fields['billing'])) {
            $fields['billing'] = [];
        }

        foreach (self::CATALOG as $key => $field) {
            if (!self::is_field_enabled($key)) {
                continue;
            }

            $type = (string) $field['type'];
            $wc_type = in_array($type, ['select', 'textarea'], true) ? $type : 'text';

            // Conditionally-required fields (render_when != always) must NOT be
            // marked required in the WC fields array: WooCommerce enforces
            // `required` server-side regardless of the JS visibility toggle, which
            // would block customers who did not pick a NIT on the `dv` field. They
            // are enforced by self::validate() on woocommerce_checkout_process.
            //
            // `required` is also gated on `require_data`: in `auto` mode an
            // incomplete form must reach the Consumidor Final fallback instead of
            // being blocked at checkout.
            $is_conditional = (string) ($field['render_when'] ?? 'always') !== 'always';

            $entry = [
                'type' => $wc_type,
                'label' => (string) $field['label'],
                'required' => self::is_require_data_mode() && !empty($field['required']) && !$is_conditional,
                'class' => [
                    'form-row-wide',
                    'alegra-billing-field',
                    'alegra-group-' . strtolower((string) $field['group']),
                ],
                'priority' => 20,
                'description' => (string) $field['help'],
                'custom_attributes' => [
                    'data-alegra-field' => $key,
                    'data-alegra-render-when' => (string) $field['render_when'],
                ],
            ];

            if ($wc_type === 'select') {
                // Prepend an explicit empty option so the browser never
                // pre-selects the first entry (RC = Registro civil). The `+`
                // operator preserves the catalog keys; '' cannot collide.
                $entry['options'] = ['' => __('Seleccione…', 'alegra-connector')] + self::options_for($key, $field);
            }

            $fields['billing']['billing_alegra_' . $key] = $entry;
        }

        return $fields;
    }

    /**
     * Render the billing fields inside the edit-account form, pre-filled.
     */
    private static function render_account_fields(): void
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0 || !self::has_enabled_fields()) {
            return;
        }

        echo '<div class="alegra-billing-fields alegra-billing-fields--account">';
        echo '<h3>' . esc_html__('Datos de facturación', 'alegra-connector') . '</h3>';

        foreach (self::CATALOG as $key => $field) {
            if (!self::is_field_enabled($key)) {
                continue;
            }
            self::render_field($key, $field, self::get_field_value($user_id, $key));
        }

        echo '</div>';
    }

    /**
     * Render a single catalog field.
     */
    private static function render_field(string $key, array $field, string $current = ''): void
    {
        $name = (string) $field['meta_key'];
        $id = 'alegra-' . $key;
        // Only unconditionally-rendered fields are marked required in the form.
        // Conditional fields (dv for NIT) are enforced by self::validate() and,
        // on checkout, by the JS visibility layer.
        $required = !empty($field['required']) && (string) ($field['render_when'] ?? 'always') === 'always';
        $required_attr = $required ? ' required' : '';

        $classes = 'form-row form-row-wide alegra-billing-field alegra-group-' . strtolower((string) $field['group']);
        if ($required) {
            $classes .= ' alegra-required';
        }

        echo '<p class="' . esc_attr($classes) . '"'
            . ' data-alegra-field="' . esc_attr($key) . '"'
            . ' data-alegra-render-when="' . esc_attr((string) $field['render_when']) . '">';

        echo '<label for="' . esc_attr($id) . '">' . esc_html((string) $field['label']);
        if ($required) {
            echo '&nbsp;<span class="required">*</span>';
        }
        echo '</label>';

        $type = (string) $field['type'];
        if ($type === 'select') {
            echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '"' . $required_attr . '>';
            echo '<option value="">' . esc_html__('Seleccione…', 'alegra-connector') . '</option>';
            foreach (self::options_for($key, $field) as $value => $label) {
                echo '<option value="' . esc_attr((string) $value) . '"'
                    . selected($current, (string) $value, false) . '>'
                    . esc_html((string) $label) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'textarea') {
            echo '<textarea name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" rows="3"' . $required_attr . '>'
                . esc_textarea($current) . '</textarea>';
        } else {
            echo '<input type="text" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" value="'
                . esc_attr($current) . '"' . $required_attr . ' />';
        }

        if ((string) $field['help'] !== '') {
            echo '<span class="description alegra-billing-help">' . esc_html((string) $field['help']);
            if ((string) $field['help_url'] !== '') {
                echo ' <a href="' . esc_url((string) $field['help_url']) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Más información', 'alegra-connector') . '</a>';
            }
            echo '</span>';
        }

        echo '</p>';
    }

    /**
     * Resolve the options for a select field.
     *
     * @return array<string, string>
     */
    private static function options_for(string $key, array $field): array
    {
        if ($key === 'idtype') {
            return self::ID_TYPES;
        }

        return isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
    }

    /**
     * Whether at least one catalog field is enabled.
     */
    private static function has_enabled_fields(): bool
    {
        foreach (array_keys(self::CATALOG) as $key) {
            if (self::is_field_enabled($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hook callback for `woocommerce_register_post`: validate the form.
     *
     * @param mixed           $username
     * @param mixed           $email
     * @param \WP_Error|mixed $errors
     */
    private static function on_register_post($username, $email, $errors): void
    {
        if (!self::has_enabled_fields()) {
            return;
        }

        $result = self::validate(self::collect_posted_values());
        if (is_wp_error($result) && $errors instanceof \WP_Error) {
            $errors->add($result->get_error_code(), $result->get_error_message());
        }
    }

    /**
     * Hook callback for `woocommerce_checkout_process`: validate the form.
     */
    private static function on_checkout_process(): void
    {
        if (!self::has_enabled_fields()) {
            return;
        }

        // Only `require_data` blocks checkout. In `auto` mode an incomplete form
        // falls back to Consumidor Final, and `always_generic` ignores the data
        // entirely, so blocking there would contradict the UI.
        if (!self::is_require_data_mode()) {
            return;
        }

        $values = self::collect_posted_values();
        if (!self::has_critical_data($values)) {
            wc_add_notice(
                __('Para completar la compra necesitás ingresar tu tipo y número de documento.', 'alegra-connector'),
                'error'
            );
            return;
        }

        $result = self::validate($values);
        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
        }
    }

    /**
     * Hook callback: persist the billing fields on customer creation.
     */
    private static function save_registration(int $customer_id): void
    {
        foreach (self::CATALOG as $key => $field) {
            // AC-38: a disabled field is never saved.
            if (!self::is_field_enabled($key)) {
                continue;
            }
            $meta_key = (string) $field['meta_key'];
            if (!array_key_exists($meta_key, $_POST)) {
                continue;
            }
            update_user_meta($customer_id, $meta_key, self::sanitize_value($field, $_POST[$meta_key]));
        }
    }

    /**
     * Hook callback: persist the billing fields on checkout.
     */
    private static function save_checkout(int $order_id): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $user_id = get_current_user_id();

        foreach (self::CATALOG as $key => $field) {
            // AC-38: a disabled field is never saved.
            if (!self::is_field_enabled($key)) {
                continue;
            }
            $meta_key = (string) $field['meta_key'];
            if (!array_key_exists($meta_key, $_POST)) {
                continue;
            }
            $value = self::sanitize_value($field, $_POST[$meta_key]);

            if ($order instanceof \WC_Order) {
                $order->update_meta_data('_' . $meta_key, $value);
            }
            if ($user_id > 0) {
                update_user_meta($user_id, $meta_key, $value);
            }
        }

        if ($order instanceof \WC_Order) {
            $order->save();
        }
    }

    /**
     * Hook callback for `woocommerce_set_additional_field_value` (Checkout Blocks).
     *
     * WooCommerce stores Block-checkout additional fields under a namespaced key
     * (`_wc_billing/alegra-connector/<key>`, see CheckoutFieldsStorage), which is
     * NOT where the invoice builder looks. This mirrors each submitted value into
     * the `billing_alegra_<key>` shape shared by both checkout paths, so Block
     * orders no longer fall back to Consumidor Final.
     *
     * @param string                 $key       Registered field id, e.g. `alegra-connector/idtype`.
     * @param mixed                  $value     The submitted value.
     * @param string                 $group     Group being saved: billing|shipping|other.
     * @param \WC_Order|\WC_Customer $wc_object The order/customer the value is saved for.
     */
    private static function save_additional_field(string $key, $value, string $group, $wc_object): void
    {
        $namespace = 'alegra-connector/';
        if (strpos($key, $namespace) !== 0) {
            return;
        }

        // Address fields are persisted once per group (billing + shipping); the
        // invoice only uses the billing copy.
        if ($group !== 'billing') {
            return;
        }

        $catalog_key = substr($key, strlen($namespace));
        if (!isset(self::CATALOG[$catalog_key])) {
            return;
        }

        // AC-38: a disabled field is never saved.
        if (!self::is_field_enabled($catalog_key)) {
            return;
        }

        $field = self::CATALOG[$catalog_key];
        $meta_key = (string) $field['meta_key'];
        $sanitized = self::sanitize_value($field, $value);

        if ($wc_object instanceof \WC_Order) {
            $wc_object->update_meta_data('_' . $meta_key, $sanitized);
        } elseif ($wc_object instanceof \WC_Customer && (int) $wc_object->get_id() > 0) {
            update_user_meta((int) $wc_object->get_id(), $meta_key, $sanitized);
        }
    }

    /**
     * Hook callback for `woocommerce_store_api_checkout_update_order_from_request`
     * (Checkout Blocks).
     *
     * Address additional fields arrive inside the `billing_address` request param.
     * This mirrors them into `billing_alegra_<key>` before WC saves the order, so
     * the invoice builder reads the same shape as on the classic checkout. Runs
     * for guests too, unlike the `woocommerce_set_additional_field_value` action
     * (which only reaches the order after a logged-in customer sync).
     *
     * @param \WC_Order        $order   The order being processed.
     * @param \WP_REST_Request $request Full checkout request.
     */
    private static function save_blocks_checkout(\WC_Order $order, \WP_REST_Request $request): void
    {
        $billing = $request['billing_address'] ?? [];
        if (!is_array($billing)) {
            $billing = [];
        }

        $user_id = (int) $order->get_customer_id();

        foreach (self::CATALOG as $key => $field) {
            // AC-38: a disabled field is never saved.
            if (!self::is_field_enabled($key)) {
                continue;
            }
            $raw = self::block_field_value('alegra-connector/' . $key, $billing, $order);
            if ($raw === null) {
                continue;
            }

            $sanitized = self::sanitize_value($field, $raw);
            $meta_key = (string) $field['meta_key'];

            $order->update_meta_data('_' . $meta_key, $sanitized);
            if ($user_id > 0) {
                update_user_meta($user_id, $meta_key, $sanitized);
            }
        }
    }

    /**
     * Resolve a Block-checkout additional field value from the checkout request,
     * the order meta, or the session customer, in that order.
     *
     * @param array<string, mixed> $billing Decoded `billing_address` request param.
     * @return mixed|null Null when no source carries the field.
     */
    private static function block_field_value(string $field_id, array $billing, \WC_Order $order)
    {
        if (array_key_exists($field_id, $billing)) {
            return $billing[$field_id];
        }

        // WC persists address fields under `_wc_billing/<namespace>/<field>`.
        $meta_key = '_wc_billing/' . $field_id;

        $value = $order->get_meta($meta_key, true);
        if ($value !== '' && $value !== null) {
            return $value;
        }

        if (function_exists('WC') && WC()->customer instanceof \WC_Customer) {
            $value = WC()->customer->get_meta($meta_key, true);
            if ($value !== '' && $value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Hook callback: persist the billing fields on account details save.
     *
     * Keys absent from the request are left untouched.
     */
    private static function save_account(int $user_id): void
    {
        foreach (self::CATALOG as $key => $field) {
            // AC-38: a disabled field is never saved.
            if (!self::is_field_enabled($key)) {
                continue;
            }
            $meta_key = (string) $field['meta_key'];
            if (!array_key_exists($meta_key, $_POST)) {
                continue;
            }
            update_user_meta($user_id, $meta_key, self::sanitize_value($field, $_POST[$meta_key]));
        }
    }

    /**
     * Collect the posted billing values, keyed by catalog key.
     *
     * @return array<string, string>
     */
    private static function collect_posted_values(): array
    {
        $values = [];
        foreach (self::CATALOG as $key => $field) {
            // AC-38: a disabled field is never collected.
            if (!self::is_field_enabled($key)) {
                $values[$key] = '';
                continue;
            }
            $meta_key = (string) $field['meta_key'];
            $values[$key] = array_key_exists($meta_key, $_POST)
                ? self::as_string(wp_unslash($_POST[$meta_key]))
                : '';
        }

        return $values;
    }

    /**
     * Normalize input that may use either catalog keys or prefixed meta keys.
     *
     * @return array<string, mixed>
     */
    private static function normalize_input(array $input): array
    {
        $values = [];
        foreach (self::CATALOG as $key => $field) {
            $meta_key = (string) $field['meta_key'];
            if (array_key_exists($meta_key, $input)) {
                $values[$key] = $input[$meta_key];
            } elseif (array_key_exists($key, $input)) {
                $values[$key] = $input[$key];
            } else {
                $values[$key] = '';
            }
        }

        return $values;
    }

    /**
     * Sanitize the full set of values, keyed by catalog key.
     *
     * @return array<string, string>
     */
    private static function sanitize_values(array $values): array
    {
        $sanitized = [];
        foreach (self::CATALOG as $key => $field) {
            $raw = self::as_string($values[$key] ?? '');
            $sanitized[$key] = $field['type'] === 'textarea'
                ? sanitize_textarea_field($raw)
                : sanitize_text_field($raw);
        }

        return $sanitized;
    }

    /**
     * Sanitize a single field's raw request value.
     */
    private static function sanitize_value(array $field, $raw): string
    {
        $value = wp_unslash(self::as_string($raw));

        return $field['type'] === 'textarea'
            ? sanitize_textarea_field($value)
            : sanitize_text_field($value);
    }

    /**
     * Validate the identification number for a given id type.
     */
    private static function check_identification(string $idtype, string $identification): ?\WP_Error
    {
        switch ($idtype) {
            case 'CC':
                if (!preg_match('/^\d{6,10}$/', $identification)) {
                    return new \WP_Error(
                        'invalid_identification_cc',
                        __('El número de documento para Cédula de Ciudadanía debe contener solo dígitos', 'alegra-connector')
                    );
                }
                break;
            case 'NIT':
                if (!preg_match('/^\d{9}$/', $identification)) {
                    return new \WP_Error(
                        'invalid_identification_nit',
                        __('El número de documento para NIT debe contener 9 dígitos', 'alegra-connector')
                    );
                }
                break;
            case 'CE':
            case 'TE':
                if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $identification)) {
                    return new \WP_Error(
                        'invalid_identification_ce',
                        __('El número de documento debe contener entre 1 y 10 caracteres alfanuméricos', 'alegra-connector')
                    );
                }
                break;
            case 'PP':
                if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $identification)) {
                    return new \WP_Error(
                        'invalid_identification_pp',
                        __('El número de pasaporte debe contener entre 1 y 20 caracteres alfanuméricos', 'alegra-connector')
                    );
                }
                break;
            default:
                if ($identification === '') {
                    return new \WP_Error('missing_identification', __('El número de documento es obligatorio.', 'alegra-connector'));
                }
                break;
        }

        return null;
    }

    /**
     * Build the Alegra contact payload from field values + contact data.
     *
     * @param array<string, string> $values  Catalog values.
     * @param array<string, mixed>  $contact Contact data (name/email/phone/address).
     * @return array|\WP_Error
     */
    private static function build_payload(array $values, array $contact): array|\WP_Error
    {
        if (!self::has_critical_data($values)) {
            return new \WP_Error(
                'incomplete_billing_data',
                __('El cliente no tiene los datos de facturación completos (tipo y número de documento).', 'alegra-connector')
            );
        }

        $idtype = (string) $values['idtype'];

        // A disabled core field is not required, but without it no valid Alegra
        // contact can be built — signal incomplete so the caller falls back to
        // Consumidor Final instead of POSTing a nameless contact.
        if ($idtype === '' || (string) $values['identification'] === '') {
            return new \WP_Error(
                'incomplete_billing_data',
                __('El cliente no tiene los datos de facturación completos (tipo y número de documento).', 'alegra-connector')
            );
        }

        $is_co = self::is_colombia_account();
        $kind_of_person = $is_co ? self::resolve_kind_of_person() : '';

        $payload = [];

        // NEVER send both `name` and `nameObject` (Alegra error 2039).
        // Colombia: the name shape follows kindOfPerson — `nameObject` is
        // required for a natural person, a flat `name` for a legal/other
        // entity (post_contacts.md). Non-CO always uses the flat `name`.
        if ($is_co && $kind_of_person === 'PERSON_ENTITY') {
            $name_object = self::build_name_object($contact);
            if (!empty($name_object)) {
                $payload['nameObject'] = $name_object;
            }
        } else {
            $display = trim((string) ($contact['display_name'] ?? ''));
            if ($display === '') {
                $display = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));
            }
            if ($display !== '') {
                $payload['name'] = $display;
            }
        }

        // Alegra requires a name; a contact with no usable name would otherwise
        // be POSTed nameless and rejected with a generic 400. Signal incomplete
        // so the caller falls back to Consumidor Final instead.
        if (empty($payload['name']) && empty($payload['nameObject'])) {
            return new \WP_Error(
                'incomplete_billing_data',
                __('El cliente no tiene un nombre válido para crear el contacto en Alegra.', 'alegra-connector')
            );
        }

        // Colombia uses the structured `identificationObject`; the generic
        // country variant uses a flat `identification` string.
        if ($is_co) {
            $identification = [
                'type' => $idtype,
                'number' => (string) $values['identification'],
            ];
            if ($idtype === 'NIT' && (string) $values['dv'] !== '') {
                $identification['dv'] = (string) $values['dv'];
            }
            $payload['identificationObject'] = $identification;

            // BUG 1: the "con facturación electrónica" CO schema requires
            // `regime` + `kindOfPerson` (post_contacts.md). Always send them:
            // both are valid in the non-FE schema too, and there is no reliable
            // GET /company signal to detect e-invoicing.
            $payload['kindOfPerson'] = $kind_of_person;
            $payload['regime'] = self::resolve_regime();
        } else {
            $payload['identification'] = (string) $values['identification'];
        }

        $payload['email'] = (string) ($contact['email'] ?? '');
        $payload['phonePrimary'] = (string) ($contact['phone'] ?? '');

        // AC-87: send only the address keys Alegra documents per country
        // (CO: address/city; other countries: + province/postalCode). The old
        // payload always sent department/country/zipCode, which the docs do not
        // define for contacts.
        $address = $contact['address'] ?? [];
        if (is_array($address)) {
            $documented = self::documented_address($address);
            if (!empty($documented)) {
                $payload['address'] = $documented;
            }
        }

        $payload['type'] = 'client';

        return $payload;
    }

    /**
     * Build the Alegra `nameObject` for a person.
     *
     * Alegra requires BOTH `firstName` and `lastName`; `fullname` is not part
     * of the schema and must never be emitted. Missing parts are derived from
     * the display name, and each field falls back to the other so the required
     * pair is always present.
     *
     * @param array<string, mixed>  $contact Contact data.
     * @return array<string, string>
     */
    private static function build_name_object(array $contact): array
    {
        $first = trim((string) ($contact['first_name'] ?? ''));
        $last = trim((string) ($contact['last_name'] ?? ''));
        $display = trim((string) ($contact['display_name'] ?? ''));

        // Derive whatever is missing from the display name.
        if (($first === '' || $last === '') && $display !== '') {
            $parts = preg_split('/\s+/', $display);
            if (is_array($parts) && !empty($parts)) {
                if ($first === '') {
                    $first = (string) array_shift($parts);
                }
                if ($last === '' && !empty($parts)) {
                    $last = (string) array_pop($parts);
                }
            }
        }

        // Never emit `fullname`; guarantee the required pair.
        if ($first === '' && $last !== '') {
            $first = $last;
        }
        if ($last === '' && $first !== '') {
            $last = $first;
        }
        if ($first === '' && $last === '') {
            return [];
        }

        $name_object = ['firstName' => $first];
        $name_object['lastName'] = $last;

        return $name_object;
    }

    /**
     * Normalize raw billing address fields to the keys Alegra documents (AC-87).
     *
     * Colombia documents only `address` and `city`; the generic/Argentina
     * variant adds `province` and `postalCode`. Anything else (department,
     * country, zipCode) is dropped so a country variant cannot reject the
     * payload for unknown keys.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private static function documented_address(array $raw): array
    {
        $address = [];
        if (trim((string) ($raw['address'] ?? '')) !== '') {
            $address['address'] = (string) $raw['address'];
        }
        if (trim((string) ($raw['city'] ?? '')) !== '') {
            $address['city'] = (string) $raw['city'];
        }

        if (!self::is_colombia_account()) {
            if (trim((string) ($raw['state'] ?? '')) !== '') {
                $address['province'] = (string) $raw['state'];
            }
            if (trim((string) ($raw['postcode'] ?? '')) !== '') {
                $address['postalCode'] = (string) $raw['postcode'];
            }
        }

        return $address;
    }

    /**
     * Coerce a scalar (or single-element array) request value to string.
     */
    private static function as_string($value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
