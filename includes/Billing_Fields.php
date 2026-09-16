<?php
/**
 * Billing Fields — pre-defined Alegra billing fields for WooCommerce.
 *
 * Holds the catalog of billing fields Alegra requires for Colombian
 * e-invoicing, registers the WooCommerce hooks that render/validate/save
 * them, and builds the contact payloads sent to the Alegra API.
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
     * Pre-defined billing field catalog.
     *
     * Groups: A = Obligatorios, B = Recomendados, C = Opcionales.
     */
    public const CATALOG = [
        // GROUP A — Obligatorios
        'kindofperson' => [
            'meta_key' => 'billing_alegra_kindofperson', 'label' => 'Tipo de persona',
            'alegra_field' => 'kindOfPerson', 'type' => 'select',
            'options' => ['PERSON_ENTITY' => 'Persona Natural', 'LEGAL_ENTITY' => 'Persona Jurídica (empresa)'],
            'required' => true, 'group' => 'A', 'render_when' => 'always',
            'help' => 'Define si la factura sale a nombre de una persona natural o de una empresa.',
            'help_url' => 'https://developer.alegra.com/reference/colombia',
        ],
        'idtype' => [
            'meta_key' => 'billing_alegra_idtype', 'label' => 'Tipo de documento',
            'alegra_field' => 'identificationObject.type', 'type' => 'select',
            'options' => [],
            'required' => true, 'group' => 'A', 'render_when' => 'always',
            'help' => 'El tipo de identificación tributaria del cliente.',
            'help_url' => 'https://developer.alegra.com/reference/colombia',
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
        'regime' => [
            'meta_key' => 'billing_alegra_regime', 'label' => 'Régimen tributario',
            'alegra_field' => 'regime', 'type' => 'select',
            'options' => [],
            'required' => true, 'group' => 'A', 'render_when' => 'always',
            'help' => 'El régimen tributario del cliente ante la DIAN.',
            'help_url' => 'https://developer.alegra.com/reference/colombia',
        ],
        // GROUP B — Recomendados
        'company' => [
            'meta_key' => 'billing_alegra_company', 'label' => 'Razón social',
            'alegra_field' => 'name', 'type' => 'text',
            'required' => true, 'group' => 'B', 'render_when' => 'legal_entity',
            'help' => 'El nombre legal de la empresa.',
            'help_url' => '',
        ],
        'secondname' => [
            'meta_key' => 'billing_alegra_secondname', 'label' => 'Segundo nombre',
            'alegra_field' => 'nameObject.secondName', 'type' => 'text',
            'required' => false, 'group' => 'B', 'render_when' => 'person_entity',
            'help' => 'Opcional. Solo si el cliente tiene segundo nombre.',
            'help_url' => '',
        ],
        'secondlastname' => [
            'meta_key' => 'billing_alegra_secondlastname', 'label' => 'Segundo apellido',
            'alegra_field' => 'nameObject.secondLastName', 'type' => 'text',
            'required' => false, 'group' => 'B', 'render_when' => 'person_entity',
            'help' => 'Opcional. Solo si el cliente tiene segundo apellido.',
            'help_url' => '',
        ],
        // GROUP C — Opcionales
        'phonesecondary' => [
            'meta_key' => 'billing_alegra_phonesecondary', 'label' => 'Teléfono secundario',
            'alegra_field' => 'phoneSecondary', 'type' => 'text',
            'required' => false, 'group' => 'C', 'render_when' => 'always',
            'help' => 'Opcional.', 'help_url' => '',
        ],
        'mobile' => [
            'meta_key' => 'billing_alegra_mobile', 'label' => 'Celular',
            'alegra_field' => 'mobile', 'type' => 'text',
            'required' => false, 'group' => 'C', 'render_when' => 'always',
            'help' => 'Opcional.', 'help_url' => '',
        ],
        'observations' => [
            'meta_key' => 'billing_alegra_observations', 'label' => 'Observaciones',
            'alegra_field' => 'observations', 'type' => 'textarea',
            'required' => false, 'group' => 'C', 'render_when' => 'always',
            'help' => 'Opcional. Notas internas sobre el cliente.', 'help_url' => '',
        ],
    ];

    /**
     * Identification types valid for a natural person.
     */
    public const ID_TYPES_PERSON = [
        'RC' => 'Registro civil', 'TI' => 'Tarjeta de identidad', 'CC' => 'Cédula de ciudadanía',
        'TE' => 'Tarjeta de extranjería', 'CE' => 'Cédula de extranjería', 'PP' => 'Pasaporte',
        'PEP' => 'Permiso especial de permanencia', 'DIE' => 'Documento de identificación extranjero',
        'NUIP' => 'NUIP', 'NIT' => 'NIT (comerciante)',
    ];

    /**
     * Identification types valid for a legal entity.
     */
    public const ID_TYPES_LEGAL = [
        'NIT' => 'NIT', 'FOREIGN_NIT' => 'NIT de otro país',
    ];

    /**
     * Tax regimes valid for a natural person.
     */
    public const REGIMES_PERSON = [
        'SIMPLIFIED_REGIME' => 'Régimen simplificado', 'COMMON_REGIME' => 'Régimen común',
        'NATIONAL_CONSUMPTION_TAX' => 'Impuesto Nacional al Consumo',
        'INC_IVA_RESPONSIBLE' => 'Responsable de IVA e INC',
    ];

    /**
     * Tax regimes valid for a legal entity.
     */
    public const REGIMES_LEGAL = [
        'SIMPLIFIED_REGIME' => 'Régimen simplificado', 'COMMON_REGIME' => 'Régimen común',
        'NOT_REPONSIBLE_FOR_CONSUMPTION' => 'No responsable de consumo',
        'SPECIAL_REGIME' => 'Régimen especial',
        'NATIONAL_CONSUMPTION_TAX' => 'Impuesto Nacional al Consumo',
        'INC_IVA_RESPONSIBLE' => 'Responsable de IVA e INC',
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
     * Enable every catalog field. Returns the number of enabled fields.
     */
    public static function enable_all(): int
    {
        $enabled = [];
        foreach (array_keys(self::CATALOG) as $key) {
            $enabled[$key] = 1;
        }

        update_option(self::OPTION_ENABLED, $enabled);

        return count($enabled);
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
     * Requires kindOfPerson + idType + identification, and DV when idType is NIT.
     */
    public static function has_critical_data(array $values): bool
    {
        $kind = self::as_string($values['kindofperson'] ?? '');
        $idtype = self::as_string($values['idtype'] ?? '');
        $identification = self::as_string($values['identification'] ?? '');

        // AC-26/AC-38: a DISABLED field is never required. Group A is
        // force-enabled by the settings sanitizer, so in practice these are
        // always required; the gate exists so a disabled field can never block
        // checkout/registration.
        if (self::is_field_enabled('kindofperson') && $kind === '') {
            return false;
        }
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
        $kind = self::as_string($values['kindofperson']);
        if (self::is_field_enabled('kindofperson') && !in_array($kind, ['PERSON_ENTITY', 'LEGAL_ENTITY'], true)) {
            return new \WP_Error(
                'invalid_kindofperson',
                'Seleccione un tipo de persona válido (Persona Natural o Persona Jurídica).'
            );
        }

        $idtype = self::as_string($values['idtype']);
        $valid_id_types = self::id_types($kind);
        if (self::is_field_enabled('idtype') && ($idtype === '' || !isset($valid_id_types[$idtype]))) {
            return new \WP_Error(
                'invalid_idtype',
                'Seleccione un tipo de documento válido para el tipo de persona.'
            );
        }

        $identification = trim(self::as_string($values['identification']));
        if (self::is_field_enabled('identification') && $identification === '') {
            return new \WP_Error('missing_identification', 'El número de documento es obligatorio.');
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
                    'El dígito de verificación (DV) debe ser un solo dígito (0-9)'
                );
            }
        } elseif ($dv !== '') {
            return new \WP_Error(
                'unexpected_dv',
                'El dígito de verificación (DV) solo aplica para NIT.'
            );
        }

        $regime = self::as_string($values['regime']);
        $valid_regimes = self::regimes($kind);
        if (self::is_field_enabled('regime') && ($regime === '' || !isset($valid_regimes[$regime]))) {
            return new \WP_Error('invalid_regime', 'Seleccione un régimen tributario válido.');
        }

        if (self::is_field_enabled('company') && $kind === 'LEGAL_ENTITY' && trim(self::as_string($values['company'])) === '') {
            return new \WP_Error(
                'missing_company',
                'La razón social es obligatoria para persona jurídica'
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
                'department' => (string) get_user_meta($user_id, 'billing_state', true),
                'country' => (string) get_user_meta($user_id, 'billing_country', true),
                'zipCode' => (string) get_user_meta($user_id, 'billing_postcode', true),
            ],
        ];

        return self::build_payload($values, $contact);
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
                'department' => (string) $order->get_billing_state(),
                'country' => (string) $order->get_billing_country(),
                'zipCode' => (string) $order->get_billing_postcode(),
            ],
        ];

        return self::build_payload($values, $contact);
    }

    /**
     * Identification types valid for the given kind of person.
     */
    private static function id_types(string $kind_of_person): array
    {
        return $kind_of_person === 'LEGAL_ENTITY' ? self::ID_TYPES_LEGAL : self::ID_TYPES_PERSON;
    }

    /**
     * Tax regimes valid for the given kind of person.
     */
    private static function regimes(string $kind_of_person): array
    {
        return $kind_of_person === 'LEGAL_ENTITY' ? self::REGIMES_LEGAL : self::REGIMES_PERSON;
    }

    /**
     * Whether the connected Alegra company is Colombian (AC-49).
     *
     * Colombia uses `kindOfPerson` / `identificationObject` / `regime`; other
     * countries use the generic flat `identification`. When the country is not
     * known the plugin keeps its CO-first default, keyed off the e-invoicing
     * (stamp) setting.
     */
    private static function is_colombia_account(): bool
    {
        $country = strtoupper(trim((string) get_option('alegra_connector_company_country', '')));
        if ($country !== '') {
            return in_array($country, ['CO', 'COLOMBIA'], true);
        }

        return (bool) get_option('alegra_connector_stamp_enabled', true);
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
        echo '<h3>' . esc_html__('Datos de facturación electrónica', 'alegra-connector') . '</h3>';

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
            // would block natural persons on the `company` field. They are enforced
            // by self::validate() on woocommerce_checkout_process instead.
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
                $entry['options'] = self::options_for($key, $field);
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

        $kind = self::get_field_value($user_id, 'kindofperson');
        $kind_of_person = $kind !== '' ? $kind : null;

        echo '<div class="alegra-billing-fields alegra-billing-fields--account">';
        echo '<h3>' . esc_html__('Datos de facturación electrónica', 'alegra-connector') . '</h3>';

        foreach (self::CATALOG as $key => $field) {
            if (!self::is_field_enabled($key)) {
                continue;
            }
            self::render_field($key, $field, self::get_field_value($user_id, $key), $kind_of_person);
        }

        echo '</div>';
    }

    /**
     * Render a single catalog field.
     */
    private static function render_field(string $key, array $field, string $current = '', ?string $kind_of_person = null): void
    {
        $name = (string) $field['meta_key'];
        $id = 'alegra-' . $key;
        // Only unconditionally-rendered fields are marked required in the form.
        // Conditional fields (e.g. company for legal entities) are enforced by
        // self::validate() and, on checkout, by the JS visibility layer.
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
            foreach (self::options_for($key, $field, $kind_of_person) as $value => $label) {
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
    private static function options_for(string $key, array $field, ?string $kind_of_person = null): array
    {
        if ($key === 'idtype') {
            return $kind_of_person !== null
                ? self::id_types($kind_of_person)
                : array_merge(self::ID_TYPES_PERSON, self::ID_TYPES_LEGAL);
        }

        if ($key === 'regime') {
            return $kind_of_person !== null
                ? self::regimes($kind_of_person)
                : array_merge(self::REGIMES_PERSON, self::REGIMES_LEGAL);
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
                        'El número de documento para Cédula de Ciudadanía debe contener solo dígitos'
                    );
                }
                break;
            case 'NIT':
                if (!preg_match('/^\d{9}$/', $identification)) {
                    return new \WP_Error(
                        'invalid_identification_nit',
                        'El número de documento para NIT debe contener 9 dígitos'
                    );
                }
                break;
            case 'CE':
            case 'TE':
                if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $identification)) {
                    return new \WP_Error(
                        'invalid_identification_ce',
                        'El número de documento debe contener entre 1 y 10 caracteres alfanuméricos'
                    );
                }
                break;
            case 'PP':
                if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $identification)) {
                    return new \WP_Error(
                        'invalid_identification_pp',
                        'El número de pasaporte debe contener entre 1 y 20 caracteres alfanuméricos'
                    );
                }
                break;
            default:
                if ($identification === '') {
                    return new \WP_Error('missing_identification', 'El número de documento es obligatorio.');
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
                'El cliente no tiene los datos de facturación electrónica completos (tipo de persona, tipo y número de documento).'
            );
        }

        $kind = (string) $values['kindofperson'];
        $idtype = (string) $values['idtype'];

        // A disabled core field is not required, but without it no valid Alegra
        // contact can be built — signal incomplete so the caller falls back to
        // Consumidor Final instead of POSTing a nameless contact.
        if ($kind === '' || $idtype === '' || (string) $values['identification'] === '') {
            return new \WP_Error(
                'incomplete_billing_data',
                'El cliente no tiene los datos de facturación electrónica completos (tipo de persona, tipo y número de documento).'
            );
        }

        $is_co = self::is_colombia_account();

        $payload = [];

        // NEVER send both `name` and `nameObject` (Alegra error 2039).
        if ($kind === 'LEGAL_ENTITY' && trim((string) $values['company']) !== '') {
            $payload['name'] = (string) $values['company'];
        } elseif ($is_co) {
            $name_object = self::build_name_object($values, $contact);
            if (!empty($name_object)) {
                $payload['nameObject'] = $name_object;
            }
        } else {
            // Non-CO generic variant: a flat `name` (no nameObject).
            $display = trim((string) ($contact['display_name'] ?? ''));
            if ($display === '') {
                $display = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));
            }
            if ($display !== '') {
                $payload['name'] = $display;
            }
        }

        // AC-49: kindOfPerson / identificationObject / regime are Colombia
        // (e-invoicing) fields. Gate them on the account country; a non-CO
        // account gets the generic flat `identification`.
        if ($is_co) {
            $payload['kindOfPerson'] = $kind;

            $identification = [
                'type' => $idtype,
                'number' => (string) $values['identification'],
            ];
            if ($idtype === 'NIT' && (string) $values['dv'] !== '') {
                $identification['dv'] = (string) $values['dv'];
            }
            $payload['identificationObject'] = $identification;

            $payload['regime'] = (string) $values['regime'];
        } else {
            $payload['identification'] = (string) $values['identification'];
        }

        $payload['email'] = (string) ($contact['email'] ?? '');
        $payload['phonePrimary'] = (string) ($contact['phone'] ?? '');

        if ((string) $values['phonesecondary'] !== '') {
            $payload['phoneSecondary'] = (string) $values['phonesecondary'];
        }
        if ((string) $values['mobile'] !== '') {
            $payload['mobile'] = (string) $values['mobile'];
        }
        if ((string) $values['observations'] !== '') {
            $payload['observations'] = (string) $values['observations'];
        }

        $address = $contact['address'] ?? [];
        if (is_array($address)) {
            $payload['address'] = $address;
        }

        $payload['type'] = 'client';

        return $payload;
    }

    /**
     * Build the Alegra `nameObject` for a natural person.
     *
     * Colombia requires BOTH `firstName` and `lastName` (post_contacts.md);
     * `fullname` is not part of the schema and must never be emitted. Missing
     * parts are derived from the display name, and each field falls back to the
     * other so the required pair is always present.
     *
     * @param array<string, string> $values  Catalog values.
     * @param array<string, mixed>  $contact Contact data.
     * @return array<string, string>
     */
    private static function build_name_object(array $values, array $contact): array
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
        if ((string) $values['secondname'] !== '') {
            $name_object['secondName'] = (string) $values['secondname'];
        }
        $name_object['lastName'] = $last;
        if ((string) $values['secondlastname'] !== '') {
            $name_object['secondLastName'] = (string) $values['secondlastname'];
        }

        return $name_object;
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
