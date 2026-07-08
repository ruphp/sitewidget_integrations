<?php
/**
 * Plugin Name: SiteWidget
 * Plugin URI: https://sitewidget.ru
 * Description: Adds SiteWidget assistant script to the site.
 * Version: 0.1.0
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
                    <?php self::renderCheckbox($options, 'send_user_id', 'Передавать ID пользователя'); ?>
                    <?php self::renderCheckbox($options, 'send_roles', 'Передавать ID ролей пользователя'); ?>
                    <?php self::renderCheckbox($options, 'send_email', 'Передавать email'); ?>
                    <?php self::renderCheckbox($options, 'send_name', 'Передавать имя'); ?>
                </table>
                <?php submit_button('Сохранить'); ?>
            </form>
        </div>
        <?php
    }

    private static function renderCheckbox(array $options, string $key, string $label): void
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
            'send_roles' => 1,
            'send_email' => 1,
            'send_name' => 1,
        ];
    }
}

SiteWidgetPlugin::boot();
