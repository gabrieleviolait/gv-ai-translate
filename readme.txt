=== GV AI Translate ===
Contributors: gabrieleviola
Tags: translation, ai translation, multilingual, language switcher, content translation
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Plugin URI: https://www.gabrieleviola.it/gv-ai-translate/
Author: Gabriele Viola
Author URI: https://www.gabrieleviola.it
Tested up to: 6.8

Adds an AI-powered language selector and frontend text translation with configurable providers and local cache.

== Description ==

GV AI Translate adds a configurable language selector to WordPress and can translate visible frontend text through selected AI translation providers.

Features include:

* Shortcode `[traduttore_translate]` with legacy alias `[gv_translate]`.
* Dropdown or button language selector.
* Optional floating language selector.
* Configurable providers: Groq, OpenAI, Anthropic and Google Gemini.
* Optional `google_free` fallback, available only when the administrator adds it to the provider order.
* Local transient cache to reduce repeated translation requests.
* Configurable languages, cache duration and text limits.

Frontend auto-translation is disabled by default. When enabled by the site administrator, visible page text can be translated through the configured provider order.

== External services ==

This plugin can connect to external translation and AI services only when configured or enabled by the site administrator.

Depending on the configured provider order, the plugin may send visible page text strings, source language, target language and model settings to one or more of the following services:

* Groq API - used for AI translation when a Groq API key is configured.
  Terms: https://groq.com/terms/
  Privacy: https://groq.com/privacy-policy/

* OpenAI API - used for AI translation when an OpenAI API key is configured.
  Terms: https://openai.com/policies/terms-of-use/
  Privacy: https://openai.com/policies/privacy-policy/

* Anthropic API - used for AI translation when an Anthropic API key is configured.
  Terms: https://www.anthropic.com/legal/consumer-terms
  Privacy: https://www.anthropic.com/legal/privacy

* Google Gemini API - used for AI translation when a Google API key is configured.
  Terms: https://policies.google.com/terms
  Privacy: https://policies.google.com/privacy

* Google Translate unofficial endpoint - used only when the `google_free` fallback is explicitly added to the provider order.
  This fallback sends individual text strings, source language and target language to Google Translate endpoints.
  Terms: https://policies.google.com/terms
  Privacy: https://policies.google.com/privacy

The plugin does not send content to these services unless translation is requested through the frontend translation flow or configured by the site administrator. API keys are stored in the WordPress options table and are not provided by the plugin author.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/gv-ai-translate` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Settings > GV AI Translate.
4. Configure available languages, provider order and any API keys you want to use.
5. Add `[traduttore_translate]` where you want to show the selector, or enable the floating selector.
6. Enable frontend auto-translation only if you want visible page text to be sent to the configured providers for translation.

== Frequently Asked Questions ==

= Does the plugin translate content automatically by default? =

No. Frontend auto-translation is disabled by default and must be enabled by the site administrator.

= Are API keys included with the plugin? =

No. API keys must be created and entered by the site administrator.

= What text is sent to external services? =

When frontend translation is requested, the plugin may send visible page text strings, source language, target language and model settings to the configured provider.

= Can I use the plugin without API keys? =

The official provider integrations require API keys. The optional `google_free` fallback can be added manually to the provider order, but it uses an unofficial Google Translate endpoint and is not enabled by default.

= Does the plugin store translations? =

Yes. Translations can be cached in WordPress transients according to the configured cache duration.

== Screenshots ==

1. Plugin settings screen.
2. Frontend language selector.

== Changelog ==

= 1.0.10 =
* Initial public release.