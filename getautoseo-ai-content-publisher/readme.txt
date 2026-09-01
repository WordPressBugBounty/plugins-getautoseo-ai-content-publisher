=== GetAutoSEO AI Tool ===
Contributors: autoseoai
Tags: seo, ai, content, automation, articles
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.107
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automate your SEO content creation and publishing with AI-powered tools. Generate high-quality articles and publish directly to WordPress.

== Description ==

GetAutoSEO AI Tool is a comprehensive WordPress plugin that seamlessly integrates with the AutoSEO platform to automate your content creation and publishing workflow. Generate high-quality, SEO-optimized articles and publish them directly to your WordPress site.

= Features =

* **AI-Powered Content Generation** - Generate SEO-optimized articles using advanced AI technology
* **Automatic Publishing** - Set up automatic publishing or manual review workflows
* **Search Term Optimization** - Include target search terms and optimize for search engines
* **Content Scheduling** - Schedule articles for future publication
* **Bulk Operations** - Manage multiple articles with bulk actions
* **Real-time Sync** - Sync articles from AutoSEO platform instantly
* **Category Management** - Automatically assign categories and tags
* **Author Assignment** - Set default authors for published content

= How It Works =

1. Install and activate the GetAutoSEO AI Tool plugin
2. Plugin automatically connects to your AutoSEO account (no API key needed!)
3. Articles sync immediately and publish to your WordPress site
4. Optionally configure publishing settings (category, author, etc.)

Note: If automatic connection fails, you can still enter your API key manually from your AutoSEO dashboard.

= Service Integration =

This plugin connects to the AutoSEO service (a third-party SaaS platform) to:
* Sync AI-generated articles to your WordPress site
* Retrieve article content, search terms, and metadata
* Manage your content publishing workflow

An AutoSEO account and active subscription are required to use this plugin. By using this plugin, you agree to the AutoSEO Terms of Service and Privacy Policy available at getautoseo.com.

= Privacy & Data =

This plugin communicates with the AutoSEO API to sync content. The following data is transmitted:
* Your API key (for authentication)
* WordPress site URL (for verification)
* Article metadata (when syncing)

No user data or visitor information is tracked or transmitted without your explicit consent.

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to **Plugins > Add New**
3. Search for "GetAutoSEO"
4. Click **Install Now** and then **Activate**

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress admin panel
3. Navigate to **Plugins > Add New > Upload Plugin**
4. Upload the ZIP file and click **Install Now**
5. Click **Activate Plugin**

= Configuration =

1. After activation, go to **AutoSEO > Settings**
2. Enter your AutoSEO API key (get this from your AutoSEO dashboard)
3. Configure publishing settings (category, author)
4. Click **Save Settings**
5. Go to **AutoSEO > Dashboard** and click **Sync Articles**

== Frequently Asked Questions ==

= Do I need an AutoSEO account to use this plugin? =

Yes, you need an active AutoSEO account with an API key. Visit getautoseo.com to sign up.

= Do I need to enter an API key? =

Usually no! The plugin automatically connects to your AutoSEO account when activated. If automatic verification fails (e.g., for localhost development), you can enter your API key manually from your AutoSEO dashboard under Settings > API Keys.

= Can I edit synced articles in WordPress? =

Articles synced from AutoSEO are managed on the AutoSEO platform. To edit them, make changes in your AutoSEO dashboard and re-sync.

= How often do articles sync? =

Articles sync when you click the "Sync Articles" button. You can also configure automatic syncing based on your needs.

= What happens if I deactivate the plugin? =

Already published articles will remain on your site. Synced but unpublished articles will stay in your database. If you uninstall the plugin, all GetAutoSEO data will be removed.

= Is my content secure? =

Yes, all communication with the AutoSEO API uses secure HTTPS connections and authentication tokens.

== Screenshots ==

1. GetAutoSEO Dashboard - Overview of your synced articles
2. Settings Page - Configure API key and publishing options
3. Articles List - Manage and publish your content
4. Article Preview - Review content before publishing

== Changelog ==

= 1.3.106 =
* FIXED: Plugin could cause HTTP 500 status on public blog post pages while the page HTML still rendered. The output-buffer callback ran a Unicode regex on the full page HTML, which exhausted the PCRE JIT stack on large pages and emitted a PHP Warning that certain servers (LiteSpeed, strict PHP-FPM configs) escalated to a 500 response.
* FIXED: All `the_content` filter callbacks now guard against null propagation — a PCRE failure in one filter no longer cascades null through subsequent filters.
* FIXED: The theme-fallback output buffer now flushes explicitly before PHP shutdown, preventing late warnings from affecting the HTTP status code.

= 1.3.105 =
* FIXED: Some live articles opened as a blank white page (no header, no menus, no article) after 1.3.104. The theme-fallback repair now leaves the page alone when the article is already visible, and it can no longer wipe the HTML if a regex or cache plugin fails.

= 1.3.104 =
* FIXED: Articles could show as live in AutoSEO but appear empty on the website when the theme reads Advanced Custom Fields (or a similar brick/flexible layout) instead of the WordPress post body. The plugin now copies content into empty ACF fields when possible, keeps the WordPress content editor visible for AutoSEO posts, and injects the article into empty theme containers so the live page shows the full text.

= 1.3.103 =
* FIXED: Syncing could fail forever with "Table 'wp_autoseo_articles' doesn't exist" if the plugin's article table went missing (a failed activation, a database restore, or a host migration). The table now rebuilds itself automatically, so articles start syncing again without a reinstall.

= 1.3.102 =
* FIXED: Syncing could stop indefinitely after an interrupted sync. The lock left behind is now released automatically, and any lock stranded by an earlier version is cleared on update.
* FIXED: A skipped sync is no longer reported as a successful one, so "Sync Now" and AutoSEO tell you when nothing was published instead of appearing to work.
* FIXED: Restoring missing FAQ structured data no longer rewrites the whole article, which could use up the available time on slower hosting before new articles were published.
* IMPROVED: Large sync backlogs are now spread across several runs instead of trying to finish in one, and AutoSEO retries an article automatically when a sync was already running.

= 1.3.101 =
* IMPROVED: Increased the authenticated AutoSEO sync request limit to handle short publishing bursts without interrupting syncs.

= 1.3.99 =
* FIXED: Published AutoSEO articles missing FAQ structured-data metadata are now refreshed during sync, restoring their FAQPage JSON-LD without changing the article body.

= 1.3.98 =
* FIXED: AutoSEO no longer forces light backgrounds, borders, padding, or spacing onto Key Takeaways and Table of Contents blocks, allowing each WordPress theme to control their appearance.
* FIXED: Manually edited and page-builder articles no longer receive a second runtime infographic when the user has cropped, replaced, or positioned the original image.

= 1.3.97 =
* FIXED: Eliminated recurring MySQL "Deadlock found when trying to get lock" errors that could fill the site error log on high-traffic sites. The plugin's hourly schema check no longer relies on an expiring transient (which auto-deleted the same option rows on every request and let concurrent requests collide); it now uses a lightweight cached timestamp instead. No impact on publishing or content.
* FIXED: Polylang exposes a WPML-compatible `icl_object_id()` function, which previously caused AutoSEO to use the WPML integration instead of Polylang’s native API. Translated posts now receive their configured Polylang language and publish under the appropriate language URL, such as `/de/`.

= 1.3.96 =
* ADDED: Polylang support. Translated articles are now assigned the correct language and linked to their source-language post, so they publish under the right language directory (e.g. /de/) with proper hreflang and language-switcher links instead of landing in the primary/default language section. WPML sites continue to work as before.

= 1.3.95 =
* FIXED: Key Takeaways headings could render as vertical text in some WordPress themes after recent article formatting changes. Legacy anchor-wrapped headings are now normalized at render time, and frontend CSS ensures horizontal layout for Key Takeaways and Table of Contents blocks.

= 1.3.93 =
* FIXED: Explicit force republish can now refresh stale AutoSEO-managed post bodies that were blocked by page-builder metadata, so updated author boxes and social links can be pushed to older posts.

= 1.3.92 =
* FIXED: Test Connection now validates the saved API key against the AutoSEO API instead of always showing success.
* IMPROVED: A successful connection test now starts the initial fast sync schedule so new installs begin pulling articles promptly.

= 1.3.89 =
* FIXED: Articles published to sites using the Enfold theme could appear blank on the front end. The Advanced Layout Builder was being flagged active without any layout data, so the theme rendered nothing instead of the article. The plugin now clears empty Enfold layout meta so posts display correctly, while still preserving real layouts created by users.
* IMPROVED: Articles previously stored without paragraph formatting are automatically refreshed with properly formatted content on the next sync.

= 1.3.79 =
* FIXED: WordPress subdirectory installs now report the public site URL for sync and verification, preventing 404 errors when publishing articles.

= 1.3.74 =
* FIXED: YouTube video embeds now display at full responsive size instead of being tiny (WordPress KSES was stripping inline positioning styles)

= 1.3.73 =
* FIXED: Unchanged articles now retry missing hero image and infographic downloads instead of skipping forever after a failed first download.

= 1.3.71 =
* FIXED: Author box social link SVG icons could be stripped when saving an AutoSEO article from the WordPress admin editor (e.g. to add a Rank Math meta title). Content protection now guards against all save paths (Classic Editor, REST API, AJAX), not just the Classic Editor form submission.
* FIXED: User edits made with page builders (Elementor, Divi, WPBakery, Beaver Builder, Brizy, Oxygen) are no longer overwritten when the plugin re-syncs articles. The plugin now detects active page-builder data and preserves user content while still updating SEO metadata and tags.

= 1.3.69 =
* FIXED: Duplicate og:/twitter: meta tags on articles when SEOPress, AIOSEO, or The SEO Framework was active. The plugin previously only detected Yoast and Rank Math, so sites using other SEO plugins emitted two sets of social meta tags.
* ADDED: SEOPress support. Article title, meta description, focus keyword, and Facebook/Twitter image are now written to SEOPress's native fields on publish, so SEOPress users no longer have to re-enter SEO data for every article.

= 1.3.51 =
* FIXED: Sync lock stuck permanently on sites where settings table lacked created_at column
* Sync lock now embeds timestamp in lock value for robust expiry without relying on DB columns
* Legacy locks (without embedded timestamp) are cleared unconditionally on next sync attempt
* Added settings table migration to add created_at column and clear any stuck locks

= 1.3.40 =
* FIXED: Race condition that caused duplicate WordPress posts when concurrent sync operations ran simultaneously
* Added sync lock to prevent overlapping sync operations (cron + REST API trigger)
* Added atomic publish claim to prevent two processes from creating the same post
* Removed redundant second publish pass from trigger-sync endpoint

= 1.3.31 =
* FIXED: Explicitly set post_name (URL slug) when creating posts to prevent WordPress from sometimes failing to auto-generate SEO-friendly permalinks
* This fixes rare cases where posts would get ?p=ID format URLs instead of proper slugs

= 1.3.41 =
* FIXED: Featured images and infographics not re-downloading when media attachments are deleted or missing
* IMPROVED: Attachment validation now checks file existence before skipping image downloads

= 1.3.30 =
* NEW: Zero-click automatic setup - plugin now connects to AutoSEO immediately on activation
* NEW: No API key entry required - plugin verifies automatically using secure handshake
* NEW: Initial article sync runs automatically after successful verification
* NEW: "Connected automatically!" success message on dashboard after auto-setup
* IMPROVED: Seamless onboarding experience with no manual configuration needed
* FALLBACK: Manual API key entry still available if auto-verification fails

= 1.3.29 =
* NEW: Automatic plugin verification via secure handshake protocol
* NEW: Backend callback verification to prevent URL spoofing
* NEW: Keyless authentication for verified WordPress installations
* IMPROVED: Setup wizard with real-time verification progress
* IMPROVED: Skip option for manual API key entry during setup

= 1.3.27 =
* FIXED: Publication date bug - WordPress posts now use the correct intended publication date from AutoSEO instead of the sync time
* NEW: Articles synced in batches no longer get identical timestamps
* IMPROVED: Delayed publish articles now retain their scheduled publication date

= 1.3.26 =
* FIXED: .md URL handling for WordPress subdirectory installations (e.g., /blog/)

= 1.3.25 =
* FIXED: Database migration now runs reliably on every admin load (idempotent migrations)
* FIXED: content_markdown column creation that was skipped in some upgrade scenarios

= 1.3.24 =
* NEW: LLM-friendly markdown URLs - Access any AutoSEO article in markdown format by appending .md to the URL
* NEW: Follows llms.txt specification for AI/LLM content discovery
* NEW: Markdown output includes title, meta description, search terms, and original markdown content
* IMPROVED: Articles now sync with original markdown content alongside HTML

= 1.3.23 =
* FIXED: Duplicate post creation when editing article titles - articles with changed titles now correctly update the existing WordPress post instead of creating a new one
* IMPROVED: Sync logic now properly identifies already-published articles and updates them directly by post ID
* IMPROVED: Status handling prevents unnecessary re-publishing of already published content

= 1.3.22 =
* FIXED: Infographic now appears in the middle of articles (before middle H2) matching the dashboard preview
* NEW: Adaptive sync schedule - syncs every minute for first 10 min, every 5 min for first hour, then hourly

= 1.3.21 =
* FIXED: YouTube video embeds now display correctly (iframes no longer stripped by WordPress)
* NEW: Remote sync trigger endpoint - AutoSEO can now trigger immediate article syncs
* NEW: Plugin now reports WordPress site URL for subdirectory installs
* NEW: Plugin version tracking for better compatibility management

= 1.3.13 =
* IMPROVED: Sync webhook now includes error details for remote diagnosis
* IMPROVED: Error messages from failed publish/update attempts sent to AutoSEO
* IMPROVED: Manual sync also sends completion webhook for better tracking
* Added local error logging during auto-sync for easier debugging

= 1.3.12 =
* FIXED: Critical bug where article updates failed during cron/scheduled sync
* FIXED: Post author validation during automatic syncs (prevents author=0 errors)
* FIXED: Silent failures on wp_insert_post now properly reported
* FIXED: Linked articles now properly update content instead of just linking
* IMPROVED: Added input validation for empty content and invalid posts
* IMPROVED: Added category validation with fallback to default
* IMPROVED: Trashed posts now handled gracefully (creates new post instead)
* IMPROVED: Enhanced debug logging for troubleshooting sync issues

= 1.3.11 =
* Minor stability improvements
* Compatibility updates

= 1.3.10 =
* Bug fixes and improvements

= 1.3.9 =
* FIXED: Added phpcs:ignore comments for all $wpdb queries with interpolated table names
* FIXED: All database queries now properly documented for WordPress coding standards
* Comprehensive WordPress Plugin Check compliance improvements

= 1.3.8 =
* FIXED: Added translators comments for all translatable strings with placeholders
* FIXED: Use ordered placeholders (%1$s, %2$s) for multi-placeholder strings
* FIXED: Use wp_parse_url() instead of parse_url() for WordPress compatibility
* FIXED: Use wp_delete_file() instead of unlink() for proper file deletion
* Added phpcs:ignore comments for intentional code patterns
* WordPress coding standards compliance improvements

= 1.3.7 =
* FIXED: Replaced deprecated get_page_by_title() with WP_Query for WordPress 6.2+ compatibility
* FIXED: Improved SQL query security in admin class with proper prepared statements
* Updated tested up to WordPress 6.9

= 1.3.1 =
* FIXED: Article content updates now properly sync to WordPress (e.g., when backlinks are inserted)
* Plugin now automatically updates existing WordPress posts when article content changes
* Enhanced publisher to handle both new articles and updates to existing posts
* Improved sync behavior to seamlessly handle article modifications
* Better logging for update operations

= 1.2.8 =
* NEW: Smart duplicate prevention - automatically detects and prevents duplicate articles on first sync
* Checks for existing WordPress posts with exact same title before publishing
* Links existing posts to AutoSEO records instead of creating duplicates
* Perfect for plugin reinstallations - no more duplicate content!
* Enhanced logging for duplicate prevention actions
* Improved article tracking with 'linked' status for existing posts

= 1.2.4 =
* Fixed contributor username to autoseoai for WordPress.org compliance
* Removed inline CSS style tag from email templates (moved to inline attributes)
* Improved security: removed redirect-based edit protection function as recommended
* Enhanced security: simplified admin notice system to avoid GET parameter dependencies
* Fixed escaping of PHP_VERSION output in settings page
* WordPress.org plugin review compliance improvements

= 1.2.3 =
* Fixed CSS loading issues by moving all styles to external admin.css file
* Removed inline CSS from dashboard template
* Improved plugin performance and caching compatibility

= 1.2.2 =
* Redesigned Quick Actions section with modern card-based layout
* Enhanced API Configuration settings page with gradient header
* Updated Publishing Settings tab with consistent design
* Added help sections with documentation and support links
* Improved responsive design for mobile devices

= 1.2.0 =
* Added published URL tracking for articles
* Plugin now sends actual WordPress permalink back to AutoSEO system
* Improved backlink target URL accuracy
* Enhanced article tracking and management
* Better integration with AutoSEO backlink exchange system

= 1.1.7 =
* Renamed plugin folder to getautoseo-ai-content-publisher
* Updated text domain to getautoseo-ai-content-publisher
* Improved plugin slug for better clarity
* All text domain references updated consistently

= 1.1.6 =
* Fixed text domain to match plugin folder name (getautoseo-ai-content-publisher)
* Fixed AutoSEO article identification on posts list page
* Added rocket emoji icon for AutoSEO articles
* Improved visual styling for managed articles
* WordPress.org compliance improvements

= 1.1.5 =
* Renamed plugin to GetAutoSEO AI Tool for trademark compliance
* Updated text domain to getautoseo-ai-tool
* Implemented nonce verification for all AJAX requests
* Converted inline styles/scripts to wp_enqueue functions
* Enhanced security and WordPress.org compliance
* Bug fixes and performance improvements

= 1.0.8 =
* WordPress.org submission preparation
* Removed external CDN dependencies
* Improved inline styling for content
* Enhanced admin interface
* Bug fixes and performance improvements

= 1.0.0 =
* Initial release
* Basic article sync and publishing
* Admin dashboard and settings
* Bulk operations support
* Category and author management
* Internationalization support

== Upgrade Notice ==

= 1.2.0 =
Improved article URL tracking and backlink system integration. Recommended update for better article management.

= 1.1.7 =
Plugin folder and text domain renamed for better clarity. Recommended update for WordPress.org compliance.

= 1.1.6 =
Text domain fix and article identification improvements. Recommended update for WordPress.org compliance.

= 1.1.5 =
Important security and compliance updates. Plugin renamed to GetAutoSEO AI Tool. Recommended update for all users.

= 1.0.8 =
This version includes important updates for WordPress.org compliance and improved performance.

== Third-Party Service ==

This plugin relies on the AutoSEO service (https://getautoseo.com) to function:

* **Service Purpose**: AI-powered content generation and management
* **Data Transmitted**: API key, site URL, article metadata
* **Terms of Service**: Available at getautoseo.com/terms
* **Privacy Policy**: Available at getautoseo.com/privacy

The service is essential for the plugin's core functionality. Without an AutoSEO account, the plugin cannot operate.

