# Changelog

## 0.3.13
- Protect PartnerStack editorial content automatically after an authorized manual WooCommerce product save.
- Ignore autosaves, revisions, AJAX, WP-CLI and invalid/nonced requests so background synchronization cannot accidentally mark raw imports as editorially verified.

## 0.3.12
- Add a source-backed commercial SEO planner that prioritizes five high-intent comparison topics from active PartnerStack products.
- Keep the existing one-article-per-day safety gate while selecting the next unused comparison plan before the general product rotation.
- Require every comparison to cover every planned product and cite only the official product sources stored during editorial verification.
- Persist comparison provenance, render official sources, prevent duplicate comparison plans and expose the next eligible plan through WP-CLI and the SEO Health page.
- Render a deterministic affiliate disclosure on every commercial comparison page.
- Accept the WordPress core sitemap endpoint as a health fallback when an SEO-plugin sitemap alias is temporarily unavailable.

## 0.3.11
- Add a scheduled revenue-link audit for Impact, Udemy, Apify, HeyGen, QuillBot and Instantly tracking structures.
- Wrap verified legacy affiliate destinations with privacy-safe local aggregate click tracking without following links or generating artificial affiliate traffic.
- Record provider, confidence, evidence hash, audit time and review status while avoiding unsupported claims about contract activity or guaranteed commission.
- Run PartnerStack synchronization every six hours so newly approved partnerships are discovered more quickly.

## 0.3.10
- Match a unique unmanaged external product by exact affiliate destination before title matching, preventing long editorial titles from producing duplicate PartnerStack drafts.
- Add guarded brand-and-host matching for unique candidates while refusing ambiguous matches such as multiple product pages from the same vendor.
- Preserve adopted or editorially enriched product titles, descriptions, categories and SEO metadata during later PartnerStack synchronizations.

## 0.3.9
- Notify the configured WordPress email address when PartnerStack products are created, hidden, or restored, including direct page and edit links.
- Immediately hide explicitly inactive partnerships and require three consecutive complete snapshots before hiding a partnership missing from the API response.
- Restore eligible returning partnerships safely and expose agreement state, review links, and notification status in the affiliate dashboard.

## 0.3.8
- Generate original OnKupon-branded featured-image cards for PartnerStack products that do not provide usable media, without copying vendor logos.
- Attach generated PNG assets through the WordPress media library and reuse them on future affiliate syncs.

## 0.3.7
- Reuse an exact-title external product when its current destination host matches the PartnerStack referral host, preventing duplicate affiliate products.
- Preserve the status and established editorial descriptions of adopted catalog products while adding managed PartnerStack tracking metadata.

## 0.3.6
- Add encrypted-at-rest secret storage for integration credentials and OAuth tokens, with migration away from legacy plaintext social-token fields.
- Add usable LinkedIn, X and PartnerStack credential forms with readiness gates and one-time OAuth connection actions.
- Implement X OAuth 2.0 PKCE, official token endpoints and automatic refresh-token handling for X and eligible LinkedIn applications.
- Migrate LinkedIn publishing from the retired `ugcPosts` endpoint to the versioned Posts API.
- Recursively redact credential-like values from structured logs and stop logging OAuth token responses.

## 0.3.5
- Select a semantically coherent product cluster from the scored rotation pool before generating an article, reducing unrelated recommendations and featured images.
- Tell the content model to use the shared product subject and choose the strongest topical featured-image product.

## 0.3.4
- Preserve JSON post metadata through WordPress slashing so FAQ and secondary-keyphrase Unicode data remain valid.
- Repair invalid FAQ metadata on existing agent articles from their rendered heading structure and rebuild invalid secondary keyphrases from assigned tags.

## 0.3.3
- Corrected the dashboard scheduler card to show only pending OnKupon jobs instead of summing pending and historical terminal statuses.

## 0.3.2
- Fixed production robots visibility detection so normal path-specific exclusions such as `/wp-admin/` are not misclassified as a site-wide `Disallow: /` directive.

## 0.3.1

* Report search visibility from both the WordPress setting and robots.txt disallow-all state

## 0.3.0

* Score the full published catalog instead of only the first 50 products
* Learn product priorities from WooCommerce sales, affiliate clicks and product scores
* Learn publishing-hour weights from 90 days of recognized WooCommerce orders and revenue
* Generate platform-specific organic social copy with per-platform UTM attribution
* Add a scheduled SEO health agent for permalinks, REST, robots, sitemaps, AIOSEO and product inventory
* Add optional throttled rewrite auto-repair, disabled by default

## 0.2.0

* Add official PartnerStack Partner API synchronization with bearer authentication and pagination
* Import active programs idempotently as WooCommerce external products, draft-first by default
* Add privacy-safe aggregate affiliate click tracking and redirect handling
* Add affiliate dashboard, settings, scheduler, control-center and WP-CLI integration
* Add pure fixture tests for PartnerStack partnership normalization

## 0.1.6

* Localize product-card calls to action and comparison-table column instructions for Turkish content

## 0.1.5

* Render formatter-owned labels in Turkish when the content language is Turkish

## 0.1.4

* Convert OnKupon URLs in comparison tables into safe WordPress links instead of rejecting them as raw Markdown
* Avoid nested paragraph markup when formatting multi-paragraph section bodies
* Reinforce the no-raw-URL rule during thin-content expansion

## 0.1.3

* Run settings migrations before persisting the new database version so activation cannot skip the 5,000-token upgrade

## 0.1.2

* Use strict Structured Outputs for article JSON generation
* Detect token truncation, refusals, HTTP errors, and empty AI responses
* Raise the long-form JSON output budget from 2,500 to 5,000 tokens
* Enforce the configured daily AI cost and article publication limits
* Validate nested article JSON fields before publishing
* Reject hallucinated product IDs that were not present in the supplied catalog set
* Default new installations to Turkish content
* Collect privacy-safe WooCommerce order and revenue aggregates for 1, 7, and 30 days
* Queue only enabled social platforms and prevent failed API calls from being marked published
* Enforce the configured daily social publication limit
* Expose social enable switches and activate a provider after successful OAuth
* Rotate content toward products that have not recently received an article
* Supply product type, summary and categories to the editorial prompt
* Add unit coverage for nested JSON-schema validation and internal metadata

## 0.1.1

* Fix duplicate admin menu registration
* Improve article formatting and content length controls
* Add category/tag/SEO improvements
* Add social OAuth integration scaffolding
* Add scheduler diagnostics improvements
# 0.4.2
- Prioritize the article's validated related-product set before behavioral popularity signals.
- Increase semantic relevance weight and remove generic commerce terms from matching.

# 0.4.1
- Treat not-yet-observed CRO impression and click metrics as zero without PHP notices.

# 0.4.0
- Add a fully autonomous in-article recommendation engine ranked by semantic relevance, aggregate 30-day interest, smoothed CTR, and exploration.
- Attribute recommendation impressions and downstream affiliate clicks without storing personal identifiers.
- Publish a self-updating, methodology-labelled popular tools page backed by OnKupon first-party aggregate interest data.
- Add IndexNow ownership verification and batched submission for new or materially updated posts, pages, and products.
- Qualify managed affiliate loop links with sponsored and nofollow relationship values.

# 0.3.14
- Prepare disclosure-labelled LinkedIn and X promotion posts for newly published PartnerStack products.
- Seed the five most recent managed PartnerStack products into an awaiting-connection social queue.
- Keep social items pending without retry churn until the platform is explicitly enabled and connected.
