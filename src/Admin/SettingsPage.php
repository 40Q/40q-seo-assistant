<?php

namespace FortyQ\SeoAssistant\Admin;

use FortyQ\SeoAssistant\Support\OpenAiClient;
use function __;

class SettingsPage
{
    public const OPTION_NAME = '40q_seo_assistant_settings';
    private const LEGACY_OPTION_NAME = 'acorn_seo_assistant_settings';

    public function boot(bool $registerOptionsMenu = true): void
    {
        add_action('admin_init', [$this, 'register']);
        if ($registerOptionsMenu) {
            add_action('admin_menu', [$this, 'addMenu']);
        }
    }

    public static function defaults(): array
    {
        $config = function_exists('config') ? (array) config('seo-assistant', []) : [];
        return [
            'ai_model' => $config['ai_model'] ?? 'heuristic',
            'seo_plugin' => $config['seo_plugin'] ?? 'tsf',
            'openai_api_key' => $config['openai']['api_key'] ?? '',
            'openai_model' => $config['openai']['model'] ?? 'gpt-4o-mini',
            'openai_prompt' => $config['openai']['prompt'] ?? OpenAiClient::defaultPrompt(),
            'openai_user_prompt' => $config['openai']['user_prompt'] ?? OpenAiClient::defaultUserPrompt(),
        ];
    }

    public static function getSettings(): array
    {
        $stored = (array) get_option(self::OPTION_NAME, []);

        if (empty($stored)) {
            // Backward compatibility for previously saved options.
            $stored = (array) get_option(self::LEGACY_OPTION_NAME, []);
        }

        $defaults = self::defaults();

        return [
            'ai_model' => self::envDefined('SEO_ASSISTANT_MODEL') ? $defaults['ai_model'] : ($stored['ai_model'] ?? $defaults['ai_model']),
            'seo_plugin' => self::envDefined('SEO_ASSISTANT_PLUGIN') ? $defaults['seo_plugin'] : ($stored['seo_plugin'] ?? $defaults['seo_plugin']),
            'openai_api_key' => self::envDefined('SEO_ASSISTANT_OPENAI_KEY') ? $defaults['openai_api_key'] : ($stored['openai_api_key'] ?? $defaults['openai_api_key']),
            'openai_model' => self::envDefined('SEO_ASSISTANT_OPENAI_MODEL') ? $defaults['openai_model'] : ($stored['openai_model'] ?? $defaults['openai_model']),
            'openai_prompt' => $stored['openai_prompt'] ?? $defaults['openai_prompt'],
            'openai_user_prompt' => $stored['openai_user_prompt'] ?? $defaults['openai_user_prompt'],
        ];
    }

    public function register(): void
    {
        register_setting(
            '40q-seo-assistant',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
                'default' => self::defaults(),
            ]
        );

        add_settings_section(
            '40q-seo-assistant/main',
            __('SEO Assistant Settings', 'radicle'),
            function () {
                echo '<p>' . esc_html__('Configure SEO Assistant behavior. AI provider and credentials are managed in Autonomy AI > General Settings.', 'radicle') . '</p>';
            },
            '40q-seo-assistant'
        );

        add_settings_field(
            '40q-seo-assistant/seo-plugin',
            __('SEO plugin', 'radicle'),
            [$this, 'renderSeoPluginField'],
            '40q-seo-assistant',
            '40q-seo-assistant/main'
        );

        add_settings_field(
            '40q-seo-assistant/openai-prompt',
            __('OpenAI prompt', 'radicle'),
            [$this, 'renderOpenAiPromptField'],
            '40q-seo-assistant',
            '40q-seo-assistant/main'
        );

        add_settings_field(
            '40q-seo-assistant/openai-user-prompt',
            __('OpenAI user prompt', 'radicle'),
            [$this, 'renderOpenAiUserPromptField'],
            '40q-seo-assistant',
            '40q-seo-assistant/main'
        );
    }

    public function addMenu(): void
    {
        add_options_page(
            __('SEO Assistant', 'radicle'),
            __('SEO Assistant', 'radicle'),
            'manage_options',
            '40q-seo-assistant',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="wrap">
            <?php settings_errors(); ?>
            <h1><?php esc_html_e('SEO Assistant', 'radicle'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('40q-seo-assistant');
                do_settings_sections('40q-seo-assistant');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function renderSeoPluginField(): void
    {
        $settings = self::getSettings();
        $value = $settings['seo_plugin'] ?? 'tsf';
        ?>
        <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[seo_plugin]">
            <option value="tsf" <?php selected($value, 'tsf'); ?>>
                <?php esc_html_e('The SEO Framework', 'radicle'); ?>
            </option>
            <option value="yoast" <?php selected($value, 'yoast'); ?>>
                <?php esc_html_e('Yoast SEO (planned)', 'radicle'); ?>
            </option>
        </select>
        <p class="description">
            <?php esc_html_e('Currently supported: The SEO Framework.', 'radicle'); ?>
        </p>
        <?php
    }

    public function sanitize(array $value): array
    {
        $defaults = self::defaults();

        $seoPlugin = $value['seo_plugin'] ?? $defaults['seo_plugin'];
        $openAiPrompt = $value['openai_prompt'] ?? $defaults['openai_prompt'];
        $openAiUserPrompt = $value['openai_user_prompt'] ?? $defaults['openai_user_prompt'];

        $seoPlugin = in_array($seoPlugin, ['tsf', 'yoast'], true)
            ? $seoPlugin
            : $defaults['seo_plugin'];

        return [
            'seo_plugin' => $seoPlugin,
            'openai_prompt' => trim((string) $openAiPrompt) ?: $defaults['openai_prompt'],
            'openai_user_prompt' => trim((string) $openAiUserPrompt) ?: $defaults['openai_user_prompt'],
        ];
    }

    public function renderOpenAiPromptField(): void
    {
        $settings = self::getSettings();
        $value = $settings['openai_prompt'] ?? OpenAiClient::defaultPrompt();
        ?>
        <textarea name="<?php echo esc_attr(self::OPTION_NAME); ?>[openai_prompt]" rows="24" cols="60" style="width: 100%; max-width: 1100px; font-family: monospace;"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('Customize the system prompt sent to OpenAI. Keep JSON keys: meta_title, meta_description, open_graph_description, twitter_description. OpenAI credentials live in General Settings.', 'radicle'); ?>
        </p>
        <?php
    }

    public function renderOpenAiUserPromptField(): void
    {
        $settings = self::getSettings();
        $value = $settings['openai_user_prompt'] ?? OpenAiClient::defaultUserPrompt();
        ?>
        <textarea name="<?php echo esc_attr(self::OPTION_NAME); ?>[openai_user_prompt]" rows="24" cols="60" style="width: 100%; max-width: 1100px; font-family: monospace;"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('Customize the user message sent to OpenAI. Available placeholders: {{title}}, {{raw_content}}. Keep JSON keys in the instructions.', 'radicle'); ?>
        </p>
        <?php
    }

    protected static function envDefined(string $key): bool
    {
        return getenv($key) !== false;
    }
}
