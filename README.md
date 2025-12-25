# 40Q SEO Assistant

Adds an editor sidebar for Gutenberg that suggests SEO metadata from the current post content and applies it to The SEO Framework fields. Now ships as a WordPress plugin so it can be toggled and configured.

## What it does
- Analyzes the post title and in-editor content to propose meta title/description plus Open Graph/Twitter variants.
- Shows suggestions in a modal launched from a new "SEO Assistant" sidebar panel.
- On apply, updates The SEO Framework meta keys (`_genesis_title`, `_genesis_description`, `_open_graph_*`, `_twitter_*`).
- Supports AI model selection (OpenAI config field provided) and a heuristic fallback. Titles stay aligned with the page title; AI focuses mainly on descriptions.

## Usage
1. Activate **40Q SEO Assistant** in wp-admin → Plugins.
2. Ensure The SEO Framework plugin is active (currently the supported target).
3. Optionally visit Settings → SEO Assistant to pick the AI model (heuristic by default) and SEO plugin target (TSF supported today). For OpenAI, add your API key and preferred model (e.g. `gpt-4o-mini`).
4. Open the block editor for a post/page and save at least once (the assistant needs a post ID).
5. Open the "SEO Assistant" panel in the editor sidebar and click "Suggest metadata".
6. Review/edit the suggestions in the modal, then choose "Apply to The SEO Framework".

## Local development notes
- Package lives at `packages/40q-seo-assistant` and is auto-loaded via `composer.json` PSR-4. The WordPress plugin bootstrap is at `public/content/plugins/40q-seo-assistant/40q-seo-assistant.php` and registers the provider when active.
- If you want Composer to track it as a dependency, run `composer update 40q/40q-seo-assistant --no-scripts` when network access is available to refresh `composer.lock`.
