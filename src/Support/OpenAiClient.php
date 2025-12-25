<?php

namespace FortyQ\SeoAssistant\Support;

use WP_Error;

class OpenAiClient
{
    public static function defaultPrompt(): string
    {
        return <<<TXT
You are an expert SEO strategist for enterprise WordPress websites.
Your task is to generate metadata optimized for search visibility and click-through rate using pixel-based SERP heuristics rather than fixed character limits.

Rules:
* Meta descriptions must fit within typical Google SERP pixel widths.
* Target maximum pixel widths:
  * Meta description: ~920px (desktop), ~680px (mobile).
* Prefer shorter descriptions if uncertain.
* Keep meta_title consistent with the provided page title. Minor refinements are allowed; meaning must remain unchanged.
* Focus on clarity, intent matching, and concrete value.
* Avoid keyword stuffing and marketing fluff.
* Do not invent features or capabilities not present in the content.
* Do not use markdown.
* Return only valid JSON.

Heuristic guidance for length (approximate):
* Meta description: typically 140–155 characters, but prioritize pixel fit over character count.
* Twitter description: typically 120–130 characters.
* Open Graph description: may be slightly longer, but should still avoid truncation.
TXT;
    }

    public static function defaultUserPrompt(): string
    {
        return <<<TXT
Input is a JSON object containing:
* title: the current page title.
* raw_content: Gutenberg/block JSON content of the page.

Task:
1. Generate:
   * meta_title
   * meta_description
   * open_graph_description
   * twitter_description
2. Use the page title as the base for meta_title.
3. Base all descriptions strictly on the real content intent and value.
4. Write for enterprise B2B decision-makers (CMO, Head of Web, CTO).
5. Ensure descriptions would not be truncated in standard Google SERP previews on desktop or mobile.

Data:
title: {{title}}
raw_content: {{raw_content}}

Return a strictly valid JSON object with exactly these keys:
meta_title
meta_description
open_graph_description
twitter_description
TXT;
    }

    public function suggest(array $payload, string $apiKey, string $model): array|WP_Error
    {
        $content = (string) ($payload['content'] ?? '');
        $title = (string) ($payload['title'] ?? '');
        $prompt = (string) ($payload['prompt'] ?? self::defaultPrompt());
        $userPrompt = (string) ($payload['user_prompt'] ?? self::defaultUserPrompt());

        if ($apiKey === '') {
            return new WP_Error('openai_missing_key', __('OpenAI API key is missing.', 'radicle'), ['status' => 400]);
        }

        $blocksJson = $payload['raw_blocks'] ?? $content;
        $userMessage = strtr($userPrompt, [
            '{{title}}' => $title,
            '{{raw_content}}' => is_string($blocksJson) ? $blocksJson : json_encode($blocksJson),
        ]);

        $messages = [
            [
                'role' => 'system',
                'content' => $prompt,
            ],
            [
                'role' => 'user',
                'content' => $userMessage,
            ],
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
            'body' => wp_json_encode([
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.4,
                'max_tokens' => 250,
                'response_format' => ['type' => 'json_object'],
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('openai_http_error', __('OpenAI request failed.', 'radicle'), [
                'status' => $code,
                'body' => $body,
            ]);
        }

        $data = json_decode($body, true);
        $message = $data['choices'][0]['message']['content'] ?? '';

        if (!$message) {
            return new WP_Error('openai_empty', __('OpenAI response was empty.', 'radicle'));
        }

        $decoded = json_decode($message, true);

        if (!is_array($decoded)) {
            return new WP_Error('openai_parse_error', __('Could not parse OpenAI response.', 'radicle'));
        }

        return [
            'meta_title' => (string) ($decoded['meta_title'] ?? $title),
            'meta_description' => (string) ($decoded['meta_description'] ?? ''),
            'open_graph_description' => (string) ($decoded['open_graph_description'] ?? ''),
            'twitter_description' => (string) ($decoded['twitter_description'] ?? ''),
        ];
    }
}
