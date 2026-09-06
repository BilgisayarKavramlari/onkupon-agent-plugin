# OnKupon Autonomous Commerce Content Agent

Current plugin version: **0.3.12**.

OnKupon Autonomous Commerce Content Agent is a source-only WordPress/WooCommerce plugin that keeps a marketplace/content-commerce site fresh by scanning active products, researching trusted sources, generating strict JSON editorial article plans through an OpenAI-compatible provider, validating content, publishing approved articles automatically, queueing social posts, collecting analytics, and updating learning weights.

The runtime plugin **never** calls Codex CLI, shells out to ChatGPT/Codex, executes arbitrary model-generated commands, or dynamically writes PHP code. All AI, research, and social integrations sit behind provider interfaces.

## Installation

1. Ensure WordPress runs PHP 8.1+ and WooCommerce is installed.
2. From this repository, run `bash onkupon-autonomous-commerce-agent/bin/build-zip.sh`.
3. Upload the generated `onkupon-autonomous-commerce-agent.zip` through **Plugins → Add New → Upload Plugin**.
4. Activate the plugin and open **OnKupon Agent → Settings**.
5. Configure status, safe mode, OpenAI-compatible provider, RSS sources, quotas, validation thresholds, and optional social/review settings.

## Source-only repository policy

Generated artifacts are intentionally excluded from git. Do not commit plugin ZIP files, `dist/`, `vendor/`, or `node_modules/`. The build script can produce a ZIP locally whenever needed.

## Dashboard

The plugin adds a top-level **OnKupon Agent** menu with:

- Overview
- Control Center
- Scheduler Health
- Content Timeline
- Product Intelligence
- Social Queue
- Analytics
- Learning
- Integrations
- Logs
- Review Integrity
- Settings

Control Center actions require admin capability, nonce verification, and audit logging. Available controls include start, pause, resume, stop, emergency stop, run now, synchronous content generation debug, test social queue, collect metrics, recalculate scores, clear locks, and safe mode toggle.

## Versioning rules

Every PR that changes plugin runtime behavior must bump the plugin version in the main plugin header, `ONKUPON_AGENT_VERSION`, package metadata, README, and CHANGELOG.

## Duplicate admin menus

The admin menu registration is idempotent and the top-level submenu is labeled **Overview**. If menus still appear duplicated, multiple plugin copies may be active; keep only one `onkupon-autonomous-commerce-agent` plugin folder active.

## Action Scheduler

When Action Scheduler is available, activation registers recurring jobs:

- Product scan every 6 hours
- Research every 2 hours
- Content generation every 4 hours
- Publishing hourly
- Social publishing every 15 minutes
- Metrics every 6 hours
- Learning daily
- Review requests daily
- Cleanup weekly

If Action Scheduler is missing, one-off queued operations gracefully fall back to WordPress cron where practical.

## OpenAI-compatible provider

Set secrets through constants or environment variables where possible:

```php
define( 'ONKUPON_AGENT_OPENAI_API_KEY', 'sk-...' );
```

Settings support base URL, model, temperature, max tokens, timeout, and daily budget. The provider uses WordPress HTTP APIs and records estimated token costs.

## Research providers

`ResearchProviderInterface` defines search/fetch/normalize methods. `RssResearchProvider` safely reads configured RSS feeds and respects allow/block lists. `SearchProviderInterface` is available for future official search APIs and must not be implemented by scraping Google Search.

## Publishing and validation

Articles are generated as strict JSON and must pass schema, required-field, quality, risk, thin-content, unsafe-claim, keyword-stuffing, and HTML sanitization checks before publishing. Invalid content is logged, rejected, and persisted to the Content Timeline with diagnostics and a sanitized preview.

## Social publishing

The social queue stores queued and processed posts. LinkedIn and X use their official OAuth and publishing APIs; Facebook Page and Instagram remain optional provider integrations. Quora suggestions are stored for manual review and are not auto-posted.

## Review integrity policy

The plugin does not create fake customer reviews, fake ratings, fake 5-star claims, customer impersonation, or fake social proof. Review Integrity supports verified-buyer review request workflows and clearly labeled editorial/AI-assisted product insights only.


## Troubleshooting: Content Timeline is empty

If article generation appears to fail but **OnKupon Agent → Content Timeline** is empty, first confirm that the content generation job actually ran. The **Run Now** button queues product scan, research, and content generation actions; after clicking it, the admin notice shows how many actions were queued and whether Action Scheduler is available.

Check **WooCommerce → Status → Scheduled Actions** for pending, running, failed, or completed OnKupon actions. If Action Scheduler is unavailable, the plugin falls back to WP-Cron single events, so make sure WordPress cron is running.

Rejected article candidates are saved to the timeline with `rejected` status, validation notes, word count, quality/risk scores, and a short sanitized preview.

## Troubleshooting: Action Scheduler pending jobs

If jobs remain pending, check **WooCommerce → Status → Scheduled Actions** and filter for `onkupon_agent_*` hooks. Confirm WP-Cron or a real server cron is running due events. A recommended cron command is shown below.

## Troubleshooting: Article rejected: Content is too thin

If the admin log shows `warning | validation | Article rejected` with `Content is too thin`, the generated article body did not meet the configured word-count threshold. The validator now uses Unicode-aware word counting for Turkish and other non-English content, so words with Turkish characters are counted correctly.

Recommended fixes:

- Increase `openai_max_tokens` so the model has enough output budget for a long-form article.
- Lower `min_article_words` temporarily for testing; the setting defaults to `800` and the UI enforces a minimum of `100`.
- Ensure WooCommerce has active product data so the prompt can build product-aware sections.
- Ensure the prompt asks for a long-form body; the built-in prompt explicitly requires at least `min_article_words`, natural Turkish paragraphs with headings, concise answer, FAQ, product-aware sections, and CTA.
- Review the validation log context, which includes word count, minimum required words, body character length, title, scores, related product IDs, a sanitized body preview, and rejection reasons.
- If the only rejection reason is thin content, the content job retries with `ArticleExpansionService` up to two times before the final rejection is persisted.

To inspect scheduled jobs in WordPress Admin, open **WooCommerce → Status → Scheduled Actions** and search for `onkupon_agent_content`, `onkupon_agent_research`, or `onkupon_agent_product_scan`.

## WP-CLI

```bash
wp onkupon-agent status
wp onkupon-agent start
wp onkupon-agent pause
wp onkupon-agent resume
wp onkupon-agent stop
wp onkupon-agent emergency-stop
wp onkupon-agent run --job=product-scan|research|content|publish|social|metrics|learning|affiliate|seo|all
wp onkupon-agent collect-metrics
wp onkupon-agent reset-locks
wp onkupon-agent build-report
wp onkupon-agent seo_plan
```

## Recommended cron

```cron
* * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
```

## Testing

```bash
bash onkupon-autonomous-commerce-agent/bin/run-tests.sh
```

The current test script performs PHP syntax verification across source files. PHPUnit stubs are included for the core pure-logic modules and should be expanded with a WordPress test-suite bootstrap.


## Build ZIP with GitHub Actions

You can build the installable plugin ZIP without Codespaces or a local PHP setup:

1. Go to the repository on GitHub and open **Actions**.
2. Select **Build WordPress Plugin ZIP**.
3. Click **Run workflow**.
4. When the run completes, download the artifact named `onkupon-autonomous-commerce-agent`.
5. Upload `onkupon-autonomous-commerce-agent.zip` to **WordPress Admin → Plugins → Add New → Upload Plugin**.

The workflow lints PHP source files, builds the ZIP from the inner plugin folder only, excludes generated/dependency files, and uploads the ZIP as a workflow artifact. The generated ZIP must not be committed to this repository.

## Build

```bash
bash onkupon-autonomous-commerce-agent/bin/build-zip.sh
```

The ZIP is generated locally in the repository root and ignored by git.

## Scheduler Health

Open **OnKupon Agent → Scheduler Health** to inspect Action Scheduler availability, WP-Cron status, registered callbacks, pending/complete/failed/canceled job counts, next scheduled times, fallback WP-Cron events, and recommended fixes.

### Why OnKupon jobs may not appear in Scheduled Actions

OnKupon jobs can be missing if the plugin was activated before Action Scheduler loaded, WooCommerce is disabled, or scheduled actions were manually cleared. The plugin now runs a lightweight admin self-healing check and repairs missing recurring jobs when Action Scheduler is available. Use **Control Center → Reschedule All Jobs** to force registration.

### How to reschedule all jobs

Go to **OnKupon Agent → Control Center** and click **Reschedule All Jobs**. This clears OnKupon scheduled actions and recreates defaults without duplicating existing hooks.

### WP-Cron fallback

If Action Scheduler functions are unavailable, immediate job requests use WP-Cron single-event fallback. If `DISABLE_WP_CRON` is true, configure real cron to process due events.

## Article formatting

Generated articles are requested as structured JSON and formatted into WordPress/Gutenberg-compatible blocks: headings, paragraphs, tables, FAQ sections, CTA blocks, and product-card shortcode blocks. Raw Markdown headings, Markdown links, and fenced code blocks are rejected before publishing.

## Long-form article configuration

Defaults are tuned for long-form commerce content: `min_article_words=800`, `target_article_words=1400`, `max_article_words=2200`, `heading_count=7`, `faq_count=5`, `product_link_count=5`, `product_card_count=3`, comparison tables enabled, and viral-title guidance enabled. Adjust these in **OnKupon Agent → Settings**.

## Category/tag/author/featured-image management

The publisher assigns post categories through `CategoryManager`, tags through `TagManager`, author through `AuthorManager`, and featured images through `FeaturedImageManager`. Configure default category, allowlisted categories, default author, and default featured image from settings.

If posts appear as **Uncategorized**, configure `default_post_category_id`, `allowed_category_ids`, or enable `allow_create_categories` with the `create_if_allowed` strategy. When creation is enabled, the plugin can create sanitized recommended categories such as `Yapay Zeka`, `Otomasyon`, `E-Ticaret Araçları`, `Dijital Pazarlama`, `Eğitim ve Verimlilik`, and `OnKupon Rehberleri`.

## AIOSEO integration

The plugin detects All in One SEO defensively. When available, it writes common AIOSEO meta fields for SEO title, description, Open Graph, Twitter/X metadata, and focus keyphrase. It always writes generic OnKupon SEO metadata as fallback.

The scheduled SEO health agent checks permalinks, the REST API, robots.txt, common sitemap routes, AIOSEO availability and product inventory every 12 hours. Optional hard rewrite repair is throttled to once per day and is disabled by default.

The commercial SEO planner gives priority to a controlled queue of source-backed, high-intent comparison articles. A plan is eligible only when every product is published, active in PartnerStack, protected by verified editorial content and has a stored official source. The same daily article and API-budget limits remain in force, and persistent comparison keys prevent duplicate publication. Use `wp onkupon-agent seo_plan` to preview the next eligible plan without publishing it.

## LinkedIn OAuth setup

LinkedIn posting uses official 3-legged OAuth and the versioned Posts API. Enter the Client ID, Client Secret, posting mode and author URN in **Integrations**, then use the one-time **Connect** action. Request `w_member_social` for member posting or `w_organization_social` for organization posting. Browser-login automation, passwords, and cookies are not supported. Tokens are encrypted at rest and refreshed automatically when the provider issues a refresh token.

## X OAuth setup

X posting uses the official OAuth 2.0 Authorization Code Flow with PKCE and `POST /2/tweets`. Enter the Client ID and optional confidential-app Client Secret in **Integrations**, register the displayed redirect URI in the X developer console, then use **Connect** once. Tokens are encrypted at rest and refreshed automatically through `offline.access`.

## Quora manual suggestions

Quora remains manual suggestion mode unless a compliant official organic posting API is configured. The plugin can generate answer suggestions for manual copy/review, but it does not automate browser posting.

## PartnerStack affiliate sync

Save the Partner API key through the encrypted field in **Integrations** or define `ONKUPON_AGENT_PARTNERSTACK_API_KEY` as a server-side constant/environment variable, then enable `partnerstack_enabled` in Settings. The daily sync reads the official Partner API with Bearer authentication, imports only active programs with HTTPS referral links, and creates idempotent WooCommerce external products. New affiliate products remain drafts unless auto publish is enabled while the agent is running outside Safe Mode. Public clicks pass through a privacy-safe aggregate counter before redirecting to the stored PartnerStack destination.

Enable PartnerStack change notifications to email the configured notification address, or the WordPress administrator address when the field is empty, whenever products are created, hidden, or restored. Explicitly inactive, archived, cancelled, declined, disabled, or rejected partnerships are immediately moved to draft and hidden from the WooCommerce catalog. A product missing from the API is hidden only after the configured number of consecutive successful complete snapshots (three by default), preventing a partial API response from causing a false deactivation. If an eligible partnership returns, the agent restores its previous published state when publishing is allowed.

## Revenue link audit

The scheduled revenue-link audit classifies recognized HTTPS tracking structures for Impact, Udemy, Apify, HeyGen, QuillBot and Instantly without following the destination and generating artificial affiliate clicks. Verified structures are wrapped with the same privacy-safe local aggregate click counter used by PartnerStack. Structural verification confirms that the URL has a recognized revenue-attribution form; it does not claim that the underlying partner contract is active or guarantee commission. Previously managed links that lose their recognized structure are marked for review rather than being silently redirected or hidden.

## Social dry-run testing

Use **OnKupon Agent → Control Center → Test Social Queue** after a successful article publish. The plugin creates dry-run LinkedIn, X, Facebook, and Quora suggestion queue rows for the latest OnKupon Agent post without calling external APIs.

## Troubleshooting: Run Now logs audit only

If **Run Now** only creates an audit log, check the notice for queued action count and scheduler availability, then open **WooCommerce → Status → Scheduled Actions** and filter `onkupon_agent_*`. For immediate debugging, use **Run Content Generation Now (synchronous debug)** from Control Center.

## Troubleshooting: raw markdown links or headings

If an AI response contains `### Heading`, `[title](url)`, or fenced code blocks, the formatter rejects it before publishing. Increase `openai_max_tokens`, use the structured prompt schema, and inspect Content Timeline rejection previews.

## Troubleshooting: no scheduled actions

Open **Scheduler Health**. If Action Scheduler is unavailable, confirm WooCommerce/Action Scheduler is active. If callbacks exist but actions are missing, click **Reschedule All Jobs**. If WP-Cron is disabled, configure the recommended server cron.
