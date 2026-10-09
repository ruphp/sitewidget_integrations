<?php
/**
 * Plugin Name: SiteWidget
 * Plugin URI: https://sitewidget.ru
 * Description: Adds SiteWidget assistant script to the site.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: SiteWidget
 * Author URI: https://sitewidget.ru
 * Text Domain: sitewidget
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SiteWidgetPlugin
{
    private const OPTION_NAME = 'sitewidget_options';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'registerSettingsPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('wp_footer', [self::class, 'renderWidgetScript'], 100);
    }

    public static function registerSettingsPage(): void
    {
        add_options_page(
            'SiteWidget',
            'SiteWidget',
            'manage_options',
            'sitewidget',
            [self::class, 'renderSettingsPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('sitewidget', self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeOptions'],
            'default' => self::defaultOptions(),
        ]);
    }

    /**
     * @param mixed $value
     */
    public static function sanitizeOptions($value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'public_key' => absint($value['public_key'] ?? 0),
            'send_user_id' => !empty($value['send_user_id']) ? 1 : 0,
            'send_roles' => !empty($value['send_roles']) ? 1 : 0,
            'send_email' => !empty($value['send_email']) ? 1 : 0,
            'send_name' => !empty($value['send_name']) ? 1 : 0,
            'sms_api_token' => sanitize_text_field((string)($value['sms_api_token'] ?? '')),
        ];
    }

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = self::options();
        ?>
        <div class="wrap">
            <h1>SiteWidget</h1>
            <p>Public key можно посмотреть в панели управления SiteWidget.</p>
            <form method="post" action="options.php">
                <?php settings_fields('sitewidget'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sitewidget_public_key">Public key</label></th>
                        <td>
                            <input
                                name="<?= esc_attr(self::OPTION_NAME) ?>[public_key]"
                                id="sitewidget_public_key"
                                type="number"
                                min="1"
                                value="<?= esc_attr((string)$options['public_key']) ?>"
                                class="regular-text"
                            >
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sitewidget_sms_api_token">SMS API-токен</label></th>
                        <td>
                            <input
                                name="<?= esc_attr(self::OPTION_NAME) ?>[sms_api_token]"
                                id="sitewidget_sms_api_token"
                                type="password"
                                value="<?= esc_attr((string)$options['sms_api_token']) ?>"
                                class="regular-text"
                                autocomplete="new-password"
                            >
                            <p class="description">Серверный токен из раздела SiteWidget «SMS-коды». Не передаётся в браузер.</p>
                        </td>
                    </tr>
                    <?php self::renderCheckbox($options, 'send_user_id', 'Передавать ID пользователя'); ?>
                    <?php self::renderCheckbox($options, 'send_email', 'Передавать email'); ?>
                    <?php self::renderCheckbox($options, 'send_name', 'Передавать имя'); ?>
                    <?php self::renderCheckbox(
                        $options,
                        'send_roles',
                        'Передавать ID ролей пользователя',
                        'Роли нужны для дополнительной фильтрации контента модулей виджета и доступны в платных тарифах.'
                    ); ?>
                </table>
                <?php submit_button('Сохранить'); ?>
            </form>
        </div>
        <?php
    }

    private static function renderCheckbox(array $options, string $key, string $label, string $description = ''): void
    {
        ?>
        <tr>
            <th scope="row"><?= esc_html($label) ?></th>
            <td>
                <label>
                    <input
                        name="<?= esc_attr(self::OPTION_NAME) ?>[<?= esc_attr($key) ?>]"
                        type="checkbox"
                        value="1"
                        <?= checked(1, (int)$options[$key], false) ?>
                    >
                    Включить
                </label>
                <?php if ($description !== ''): ?>
                    <p class="description"><?= esc_html($description) ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    public static function renderWidgetScript(): void
    {
        $options = self::options();
        $publicKey = (int)$options['public_key'];

        if ($publicKey <= 0) {
            return;
        }

        $context = self::userContext($options);
        ?>
        <script>
            window.SiteWidget = {
                apiUrl: 'https://sitewidget.ru/api',
                staticUrl: 'https://sitewidget.ru/static',
                customUrl: 'https://sitewidget.ru/custom',
                supportWsUrl: 'wss://sitewidget.ru/ws',
                publicKey: <?= (int)$publicKey ?>,
                _user: <?= wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
            };
            (function () {
                var script = document.createElement('script');
                script.src = 'https://sitewidget.ru/static/lib.js';
                script.async = true;
                document.head.appendChild(script);
            })();
        </script>
        <?php
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public static function queueSmsCode(string $requestId, string $phone, string $text, ?\DateTimeInterface $expiresAt = null)
    {
        $token = trim((string)self::options()['sms_api_token']);
        if ($token === '') {
            return new \WP_Error('sitewidget_sms_not_configured', 'SMS API-токен SiteWidget не настроен');
        }

        $response = wp_remote_post('https://sitewidget.ru/api/sms/v1/tasks', [
            'timeout' => 5,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode([
                'requestId' => $requestId,
                'phone' => $phone,
                'text' => $text,
                'expiresAt' => ($expiresAt ?? new \DateTimeImmutable('+5 minutes'))->format(DATE_ATOM),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $data = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            return new \WP_Error(
                'sitewidget_sms_request_failed',
                is_array($data) ? (string)($data['message'] ?? 'SiteWidget не принял SMS-задание') : 'SiteWidget вернул некорректный ответ',
                ['status' => $status]
            );
        }

        return $data;
    }

    /**
     * @return array{requestId:string,expiresIn:int}|\WP_Error
     */
    public static function requestSmsCode(string $phone)
    {
        $phone = self::normalizePhone($phone);
        if ($phone === null) {
            return new \WP_Error('sitewidget_sms_invalid_phone', 'Некорректный номер телефона');
        }

        $requestId = wp_generate_uuid4();
        $code = (string)random_int(1000, 9999);
        $storageKey = 'sitewidget_sms_' . str_replace('-', '_', $requestId);
        set_transient($storageKey, [
            'phone' => $phone,
            'code_hash' => hash_hmac('sha256', $code, wp_salt('auth')),
            'attempts' => 0,
        ], 5 * MINUTE_IN_SECONDS);

        $result = self::queueSmsCode(
            $requestId,
            $phone,
            sprintf('Код подтверждения %s: %s', wp_specialchars_decode(get_bloginfo('name')), $code),
            new \DateTimeImmutable('+5 minutes')
        );
        if (is_wp_error($result)) {
            delete_transient($storageKey);
            return $result;
        }

        return ['requestId' => $requestId, 'expiresIn' => 300];
    }

    /**
     * @return array{verified:bool,phone:string}|\WP_Error
     */
    public static function verifySmsCode(string $requestId, string $code)
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $requestId) || !preg_match('/^\d{4}$/', $code)) {
            return new \WP_Error('sitewidget_sms_invalid_code', 'Некорректный код');
        }

        $storageKey = 'sitewidget_sms_' . str_replace('-', '_', $requestId);
        $data = get_transient($storageKey);
        if (!is_array($data)) {
            return new \WP_Error('sitewidget_sms_expired', 'Код истёк или не найден');
        }
        $attempts = (int)($data['attempts'] ?? 0) + 1;
        if ($attempts > 5) {
            delete_transient($storageKey);
            return new \WP_Error('sitewidget_sms_attempts', 'Превышено число попыток');
        }

        if (!hash_equals((string)$data['code_hash'], hash_hmac('sha256', $code, wp_salt('auth')))) {
            $data['attempts'] = $attempts;
            set_transient($storageKey, $data, 5 * MINUTE_IN_SECONDS);
            return new \WP_Error('sitewidget_sms_wrong_code', 'Неверный код');
        }

        delete_transient($storageKey);
        $phone = (string)$data['phone'];
        do_action('sitewidget_sms_verified', $phone, $requestId);
        return ['verified' => true, 'phone' => $phone];
    }

    private static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7' . substr($digits, 1);
        }
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? '+' . $digits : null;
    }

    private static function userContext(array $options): array
    {
        $context = [
            'id' => null,
            'role' => null,
            'name' => null,
            'email' => null,
        ];

        if (!is_user_logged_in()) {
            return $context;
        }

        $user = wp_get_current_user();

        if (!empty($options['send_user_id'])) {
            $context['id'] = (int)$user->ID;
        }

        if (!empty($options['send_roles'])) {
            $context['role'] = array_values(array_map('strval', (array)$user->roles));
        }

        if (!empty($options['send_email'])) {
            $context['email'] = (string)$user->user_email;
        }

        if (!empty($options['send_name'])) {
            $name = trim((string)$user->display_name);
            $context['name'] = $name !== '' ? $name : (string)$user->user_login;
        }

        return $context;
    }

    private static function options(): array
    {
        return array_merge(self::defaultOptions(), (array)get_option(self::OPTION_NAME, []));
    }

    private static function defaultOptions(): array
    {
        return [
            'public_key' => 0,
            'send_user_id' => 1,
            'send_roles' => 0,
            'send_email' => 1,
            'send_name' => 1,
            'sms_api_token' => '',
        ];
    }
}

SiteWidgetPlugin::boot();

if (!function_exists('sitewidget_queue_sms_code')) {
    /**
     * @return array<string, mixed>|\WP_Error
     */
    function sitewidget_queue_sms_code(string $requestId, string $phone, string $text, ?\DateTimeInterface $expiresAt = null)
    {
        return SiteWidgetPlugin::queueSmsCode($requestId, $phone, $text, $expiresAt);
    }
}

if (!function_exists('sitewidget_request_sms_code')) {
    function sitewidget_request_sms_code(string $phone)
    {
        return SiteWidgetPlugin::requestSmsCode($phone);
    }
}

if (!function_exists('sitewidget_verify_sms_code')) {
    function sitewidget_verify_sms_code(string $requestId, string $code)
    {
        return SiteWidgetPlugin::verifySmsCode($requestId, $code);
    }
}
