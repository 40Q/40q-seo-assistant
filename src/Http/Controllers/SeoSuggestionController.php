<?php

namespace FortyQ\SeoAssistant\Http\Controllers;

use FortyQ\SeoAssistant\Admin\SettingsPage;
use FortyQ\SeoAssistant\Support\SocialImageGenerator;
use FortyQ\SeoAssistant\Support\SuggestionBuilder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use function __;
use function is_wp_error;

class SeoSuggestionController
{
    public function __construct(
        protected SuggestionBuilder $builder,
        protected SocialImageGenerator $socialImageGenerator
    )
    {
    }

    public function register(): void
    {
        register_rest_route('40q-seo-assistant/v1', 'suggest', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => $this->permissionCallback(...),
            'callback' => [$this, 'suggest'],
            'args' => [
                'post_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'content' => [
                    'type' => 'string',
                    'required' => false,
                ],
                'title' => [
                    'type' => 'string',
                    'required' => false,
                ],
                'raw_blocks' => [
                    'type' => 'string',
                    'required' => false,
                ],
            ],
        ]);

        register_rest_route('40q-seo-assistant/v1', 'apply', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => $this->permissionCallback(...),
            'callback' => [$this, 'apply'],
            'args' => [
                'post_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'meta_title' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
                'open_graph_title' => ['type' => 'string'],
                'open_graph_description' => ['type' => 'string'],
                'twitter_title' => ['type' => 'string'],
                'twitter_description' => ['type' => 'string'],
                'apply' => ['type' => 'object'],
            ],
        ]);

        register_rest_route('40q-seo-assistant/v1', 'social-image', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => $this->permissionCallback(...),
            'callback' => [$this, 'generateSocialImage'],
            'args' => [
                'post_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'url' => [
                    'type' => 'string',
                    'required' => false,
                ],
            ],
        ]);
    }

    public function suggest(WP_REST_Request $request): array|WP_Error
    {
        $postId = (int) $request->get_param('post_id');
        $content = (string) ($request->get_param('content') ?? '');
        $title = (string) ($request->get_param('title') ?? '');

        $settings = SettingsPage::getSettings();
        $seoPlugin = $settings['seo_plugin'] ?? 'tsf';

        if ($seoPlugin === 'tsf' && !function_exists('the_seo_framework')) {
            return new WP_Error('tsf_inactive', __('The SEO Framework must be active to generate suggestions.', 'radicle'), ['status' => 400]);
        }

        if (!in_array($seoPlugin, ['tsf'], true)) {
            return new WP_Error('seo_plugin_unsupported', __('Selected SEO plugin is not supported yet.', 'radicle'), ['status' => 400]);
        }

        $settings['raw_blocks'] = $request->get_param('raw_blocks') ?? null;

        $suggestions = $this->builder->build($postId, $title, $content, $settings);

        if (is_wp_error($suggestions)) {
            return $suggestions;
        }

        return [
            'suggestions' => $suggestions,
            'current_meta' => $this->getCurrentMeta($postId),
            'settings' => $settings,
        ];
    }

    public function generateSocialImage(WP_REST_Request $request): array|WP_Error
    {
        $postId = (int) $request->get_param('post_id');

        if ($postId <= 0) {
            return new WP_Error('invalid_post_id', __('Post ID is required.', 'radicle'), ['status' => 400]);
        }

        if (!function_exists('the_seo_framework')) {
            return new WP_Error('tsf_inactive', __('The SEO Framework must be active to generate social images.', 'radicle'), ['status' => 400]);
        }

        $targetUrl = $request->get_param('url');

        $result = $this->socialImageGenerator->generate($postId, $targetUrl);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'attachment_id' => $result['attachment_id'],
            'url' => $result['url'],
        ];
    }

    public function apply(WP_REST_Request $request): array|WP_Error
    {
        $settings = SettingsPage::getSettings();
        $seoPlugin = $settings['seo_plugin'] ?? 'tsf';

        $postId = (int) $request->get_param('post_id');
        $meta = [];

        if ($seoPlugin === 'tsf') {
            if (!function_exists('the_seo_framework')) {
                return new WP_Error('tsf_inactive', __('The SEO Framework must be active to apply suggestions.', 'radicle'), ['status' => 400]);
            }

            $meta = [
                '_genesis_title' => sanitize_text_field((string) $request->get_param('meta_title')),
                '_genesis_description' => sanitize_textarea_field((string) $request->get_param('meta_description')),
                '_open_graph_title' => sanitize_text_field((string) $request->get_param('open_graph_title')),
                '_open_graph_description' => sanitize_textarea_field((string) $request->get_param('open_graph_description')),
                '_twitter_title' => sanitize_text_field((string) $request->get_param('twitter_title')),
                '_twitter_description' => sanitize_textarea_field((string) $request->get_param('twitter_description')),
            ];
        } else {
            return new WP_Error('seo_plugin_unsupported', __('Selected SEO plugin is not supported yet.', 'radicle'), ['status' => 400]);
        }

        $applyFlags = (array) $request->get_param('apply');
        $updated = [];

        foreach ($meta as $key => $value) {
            $shouldApply = isset($applyFlags[$key]) ? (bool) $applyFlags[$key] : true;
            if (!$shouldApply) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                delete_post_meta($postId, $key);
                continue;
            }

            update_post_meta($postId, $key, wp_slash($value));
            $updated[] = $key;
        }

        return [
            'success' => true,
            'updated_keys' => $updated,
            'settings' => $settings,
        ];
    }

    private function permissionCallback(WP_REST_Request $request): bool
    {
        $postId = (int) $request->get_param('post_id');

        return $postId > 0 && current_user_can('edit_post', $postId);
    }

    private function getCurrentMeta(int $postId): array
    {
        $keys = [
            '_genesis_title',
            '_genesis_description',
            '_open_graph_title',
            '_open_graph_description',
            '_twitter_title',
            '_twitter_description',
        ];

        $current = [];

        foreach ($keys as $key) {
            $value = get_post_meta($postId, $key, true);
            $current[$key] = is_string($value) ? $value : '';
        }

        return [
            'meta_title' => $current['_genesis_title'] ?? '',
            'meta_description' => $current['_genesis_description'] ?? '',
            'open_graph_title' => $current['_open_graph_title'] ?? '',
            'open_graph_description' => $current['_open_graph_description'] ?? '',
            'twitter_title' => $current['_twitter_title'] ?? '',
            'twitter_description' => $current['_twitter_description'] ?? '',
        ];
    }
}
