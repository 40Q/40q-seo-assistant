<?php

namespace FortyQ\SeoAssistant;

use FortyQ\SeoAssistant\Admin\SettingsPage;
use FortyQ\SeoAssistant\Http\Controllers\SeoSuggestionController;
use FortyQ\SeoAssistant\Support\OpenAiClient;
use FortyQ\SeoAssistant\Support\SuggestionBuilder;
use Illuminate\Support\ServiceProvider;

class SeoAssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/seo-assistant.php', 'seo-assistant');

        $this->app->singleton(SuggestionBuilder::class, fn ($app) => new SuggestionBuilder($app->make(OpenAiClient::class)));
        $this->app->singleton(SettingsPage::class, fn () => new SettingsPage());
        $this->app->singleton(OpenAiClient::class, fn () => new OpenAiClient());

        $this->publishes([
            __DIR__ . '/../config/seo-assistant.php' => $this->app->configPath('seo-assistant.php'),
        ], 'seo-assistant-config');
    }

    public function boot(): void
    {
        if (!$this->isHubAvailable()) {
            if (is_admin()) {
                add_action('admin_notices', [$this, 'missingHubNotice']);
            }
            return;
        }

        $settingsPage = $this->app->make(SettingsPage::class);
        $settingsPage->boot(false);
        add_action('admin_menu', [$this, 'registerHubMenu'], 20);
        add_action('admin_menu', [$this, 'removeLegacyMenu'], 99);

        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
    }

    public function registerRoutes(): void
    {
        $controller = $this->app->make(SeoSuggestionController::class);
        $controller->register();
    }

    public function enqueueEditorAssets(): void
    {
        $settings = SettingsPage::getSettings();
        $seoPlugin = $settings['seo_plugin'] ?? 'tsf';
        $slug = '40q-seo-assistant';
        $handle = 'fortyq-seo-assistant';

        $pluginAvailable = match ($seoPlugin) {
            'tsf' => function_exists('the_seo_framework'),
            default => false,
        };

        $scriptHandle = $handle;
        $scriptPath = realpath(__DIR__ . '/../resources/scripts/editor.js');
        $dependencies = [
            'wp-api-fetch',
            'wp-components',
            'wp-data',
            'wp-edit-post',
            'wp-element',
            'wp-i18n',
            'wp-plugins',
        ];

        wp_register_script($scriptHandle, false, $dependencies, '0.2.0', true);

        if ($scriptPath && is_readable($scriptPath)) {
            wp_add_inline_script($scriptHandle, file_get_contents($scriptPath));
        }

        $postId = get_the_ID() ?: 0;

        wp_localize_script($scriptHandle, 'seoAssistantSettings', [
            'nonce' => wp_create_nonce('wp_rest'),
            'restNamespace' => "/{$slug}/v1",
            'postId' => $postId,
            'hasTSF' => $seoPlugin === 'tsf' && $pluginAvailable,
            'seoPlugin' => $seoPlugin,
            'aiModel' => $settings['ai_model'] ?? 'heuristic',
        ]);

        wp_enqueue_script($scriptHandle);
    }

    protected function registerHubMenu(): void
    {
        $capability = 'manage_options';
        $title = __('SEO Assistant', 'radicle');

        add_submenu_page(
            '40q-autonomy-ai',
            $title,
            $title,
            $capability,
            '40q-autonomy-ai-seo-assistant',
            [$this->app->make(SettingsPage::class), 'renderPage']
        );
    }

    protected function removeLegacyMenu(): void
    {
        remove_submenu_page('options-general.php', '40q-seo-assistant');
    }

    protected function isHubAvailable(): bool
    {
        return class_exists('FortyQ\\AutonomyAiHub\\AutonomyAiServiceProvider', false);
    }

    public function missingHubNotice(): void
    {
        echo '<div class="notice notice-error"><p>';
        esc_html_e('SEO Assistant requires the 40Q Autonomy AI hub to be installed and active.', 'radicle');
        echo '</p></div>';
    }
}
