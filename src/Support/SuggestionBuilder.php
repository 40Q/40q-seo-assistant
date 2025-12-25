<?php

namespace FortyQ\SeoAssistant\Support;

use WP_Error;
use function is_wp_error;

class SuggestionBuilder
{
    public function __construct(protected OpenAiClient $openAiClient)
    {
    }

    public function build(int $postId, string $title = '', string $content = '', array $settings = []): array|WP_Error
    {
        $settings = wp_parse_args($settings, [
            'ai_model' => 'heuristic',
            'seo_plugin' => 'tsf',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'raw_blocks' => null,
        ]);

        $heuristic = $this->buildHeuristic($postId, $title, $content);
        $selectedModel = $settings['ai_model'] ?? 'heuristic';
        $payload = [
            'post_id' => $postId,
            'title' => $title,
            'content' => $content,
            'settings' => $settings,
            'raw_blocks' => $settings['raw_blocks'] ?? null,
            'prompt' => $settings['openai_prompt'] ?? OpenAiClient::defaultPrompt(),
            'user_prompt' => $settings['openai_user_prompt'] ?? OpenAiClient::defaultUserPrompt(),
        ];

        if ($selectedModel !== 'heuristic') {
            /**
             * Allow external generators to provide AI-based suggestions.
             *
             * Return an associative array of suggestion fields to override defaults.
             */
            $aiSuggestions = apply_filters('40q_seo_assistant/generate_suggestions', null, $selectedModel, $payload);

            if (is_array($aiSuggestions)) {
                return array_merge($heuristic, $aiSuggestions, [
                    'model_used' => $selectedModel,
                    'seo_plugin' => $settings['seo_plugin'],
                ]);
            }

            if ($selectedModel === 'openai') {
                $aiSuggestions = $this->openAiClient->suggest(
                    $payload,
                    (string) $settings['openai_api_key'],
                    (string) $settings['openai_model']
                );

                if (is_wp_error($aiSuggestions)) {
                    return $aiSuggestions;
                }

                if (is_array($aiSuggestions)) {
                    return array_merge($heuristic, $aiSuggestions, [
                        'model_used' => $selectedModel,
                        'seo_plugin' => $settings['seo_plugin'],
                    ]);
                }
            }
        }

        return array_merge($heuristic, [
            'model_used' => 'heuristic',
            'seo_plugin' => $settings['seo_plugin'],
        ]);
    }

    protected function buildHeuristic(int $postId, string $title = '', string $content = ''): array
    {
        $resolvedContent = $content ?: (string) get_post_field('post_content', $postId);
        $resolvedTitle = $title ?: (string) get_the_title($postId);
        $plainText = $this->normalizeContent($resolvedContent);

        $description = $this->generateDescription($plainText);
        $keywords = $this->extractKeywords($plainText);
        $titleSuggestion = $this->generateTitle($resolvedTitle, $keywords);

        $socialTitle = $titleSuggestion;
        $socialDescription = $description;

        return [
            'meta_title' => $titleSuggestion,
            'meta_description' => $description,
            'open_graph_title' => $socialTitle,
            'open_graph_description' => $socialDescription,
            'twitter_title' => $socialTitle,
            'twitter_description' => $socialDescription,
            'keywords' => $keywords,
        ];
    }

    protected function normalizeContent(string $content): string
    {
        $content = apply_filters('the_content', $content);
        $content = wp_strip_all_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES);

        return trim(preg_replace('/\s+/', ' ', $content));
    }

    protected function generateDescription(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $plainText);
        $description = trim($sentences[0] ?? $plainText);

        return $this->truncate($description, 155);
    }

    protected function generateTitle(string $postTitle, array $keywords): string
    {
        $title = $postTitle;
        $primaryKeyword = $keywords[0] ?? '';

        if ($primaryKeyword && stripos($postTitle, $primaryKeyword) === false) {
            $title = "{$postTitle} | {$primaryKeyword}";
        }

        return $this->truncate($title ?: 'Suggested Title', 60);
    }

    protected function extractKeywords(string $plainText): array
    {
        if ($plainText === '') {
            return [];
        }

        $words = str_word_count(strtolower($plainText), 1);
        $stopwords = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'have', 'will', 'about',
            'into', 'while', 'what', 'when', 'where', 'would', 'could', 'their', 'there', 'they',
            'them', 'over', 'under', 'above', 'below', 'between', 'after', 'before', 'because',
            'been', 'being', 'also', 'just', 'into', 'more', 'most', 'such', 'only', 'other',
        ];

        $keywords = array_values(array_filter($words, function ($word) use ($stopwords) {
            return strlen($word) > 4 && !in_array($word, $stopwords, true);
        }));

        $counts = array_count_values($keywords);
        arsort($counts);

        return array_slice(array_keys($counts), 0, 5);
    }

    protected function truncate(string $value, int $limit): string
    {
        $value = trim($value);

        if (strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(substr($value, 0, $limit - 3)) . '...';
    }
}
