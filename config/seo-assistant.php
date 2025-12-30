<?php

use FortyQ\SeoAssistant\Support\OpenAiClient;

return [
    'ai_model' => env('SEO_ASSISTANT_MODEL', 'heuristic'),
    'seo_plugin' => env('SEO_ASSISTANT_PLUGIN', 'tsf'),
    'openai' => [
        'api_key' => env('SEO_ASSISTANT_OPENAI_KEY', ''),
        'model' => env('SEO_ASSISTANT_OPENAI_MODEL', 'gpt-4o-mini'),
        'prompt' => OpenAiClient::defaultPrompt(),
        'user_prompt' => OpenAiClient::defaultUserPrompt(),
    ],
    'social_image' => [
        'service_url' => env('SEO_ASSISTANT_SOCIAL_SERVICE_URL', 'https://og-gen-pjqz.onrender.com/screenshot'),
        'target_override' => env('SEO_ASSISTANT_SOCIAL_TARGET', ''),
        'timeout' => (int) env('SEO_ASSISTANT_SOCIAL_TIMEOUT', 30),
    ],
];
