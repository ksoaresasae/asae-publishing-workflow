# ASAE Publishing Workflow — Plugin Specification

## Overview

This is a WordPress plugin called **ASAE Publishing Workflow** (slug: `asae-publishing-workflow`). It provides a content ownership and editorial workflow system where users are assigned to specific content areas (defined by a custom taxonomy) and can only create, edit, and publish content within their assigned areas. It enforces a simple two-step workflow: Editors create and modify content, then submit it to Publishers for approval. Publishers can approve (publish) or reject (with comments) submitted content.

This plugin lives within the existing ASAE plugin ecosystem. It registers a submenu item labeled **Publishing Workflow** under the existing top-level **ASAE** admin menu. It follows the same structured directory conventions, coding standards, accessibility patterns, and GitHub auto-push update mechanism as the other ASAE plugins.

---

## Directory Structure

```
asae-publishing-workflow/
├── asae-publishing-workflow.php          # Main plugin file (bootstrap only)
├── uninstall.php                         # Clean uninstall logic
├── README.md
├── includes/
│   ├── class-asae-pw-roles.php           # Role creation, capability management
│   ├── class-asae-pw-taxonomy.php        # Custom taxonomy registration
│   ├── class-asae-pw-assignments.php     # User-to-content-area assignment logic
│   ├── class-asae-pw-permissions.php     # map_meta_cap, user_has_cap filters (THE critical file)
│   ├── class-asae-pw-workflow.php        # Submit/approve/reject state machine
│   ├── class-asae-pw-notifications.php   # Email notifications to publishers/editors
│   ├── class-asae-pw-activity-log.php    # Audit trail logging
│   ├── class-asae-pw-settings.php        # Plugin settings including XML-RPC toggle
│   ├── class-asae-pw-trash.php           # Trash request/approval workflow
│   ├── class-asae-pw-cache.php           # Cache purge on status transitions
│   └── class-asae-pw-updater.php         # GitHub-based plugin update mechanism
├── admin/
│   ├── class-asae-pw-admin.php           # Admin menu registration, page routing
│   ├── class-asae-pw-admin-dashboard.php # Main dashboard/overview screen
│   ├── class-asae-pw-admin-assignments.php # UI for managing user assignments
│   ├── class-asae-pw-admin-submissions.php # UI for reviewing pending submissions
│   ├── class-asae-pw-admin-activity.php  # UI for viewing audit log (search-first)
│   ├── class-asae-pw-admin-settings.php  # UI for plugin settings
│   └── class-asae-pw-meta-boxes.php      # Post editor meta boxes (submit, status, activity history)
├── assets/
│   ├── css/
│   │   └── asae-pw-admin.css             # Admin styles
│   └── js/
│       └── asae-pw-admin.js              # Admin scripts (meta box interactions, AJAX)
└── templates/
    └── emails/
        ├── submission-notify.php          # Email to publishers on new submission
        ├── approved-notify.php            # Email to editor on approval
        ├── rejected-notify.php            # Email to editor on rejection
        └── trash-request-notify.php       # Email to admins on trash request
```

---

## Custom Taxonomy: Content Area

Register a custom taxonomy called `asae_content_area` (label: "Content Area"). It should be hierarchical (like categories), applicable to both `post` and `page` post types, and any other public custom post types registered on the site. It should appear in the admin UI for Admins only — Editors and Publishers should see their assigned Content Area terms displayed as read-only information on the post editor, not as an editable taxonomy metabox.

Terms in this taxonomy represent navigational or organizational areas of the site, such as "Professional Development," "About Us," "Meetings," "Advocacy," etc. Admins create and manage these terms.

### Content Area changes require approval

Content Area term assignment changes are a publishable action. If a Publisher or Admin changes the Content Area on a post, it takes effect immediately. If an Editor changes it, the change must go through the approval workflow just like any other content modification. Implement this by storing proposed taxonomy changes as post meta on the pending revision, not by actually changing the term assignment until a Publisher approves.

---

## Custom Roles

Create two new WordPress roles on plugin activation. These roles start with **minimal capabilities** — closer to Subscriber than Editor. The plugin dynamically grants additional capabilities through filters based on content area assignments. This means if the plugin is deactivated, these users can do almost nothing (fail closed).

### ASAE PW Editor (`asae_pw_editor`)

**Base capabilities (hardcoded into role):**

- `read`
- `upload_files`
- `edit_posts` (needed for WP to show the post editor at all)

That is all. No `publish_posts`, no `publish_pages`, no `edit_others_posts`, no `delete_others_posts`, no `manage_categories`, no `edit_pages`, no `edit_others_pages`. None of the capabilities for plugins, themes, menus, settings, tools, options, users, or any other admin function.

**Dynamically granted by the plugin via `user_has_cap` filter, scoped to assigned content areas only:**

- `edit_others_posts` / `edit_others_pages` — only for posts/pages within their assigned Content Area terms
- `edit_published_posts` / `edit_published_pages` — only within assigned areas
- `edit_private_posts` / `edit_private_pages` — only within assigned areas

**Never granted:**

- `publish_posts` / `publish_pages` — Editors can never publish, period
- `delete_others_posts` / `delete_others_pages` — Editors cannot trash; they can only request trashing
- Any capabilities outside the content editing scope

### ASAE PW Publisher (`asae_pw_publisher`)

**Base capabilities (hardcoded into role):**

- `read`
- `upload_files`
- `edit_posts`

**Dynamically granted by the plugin, scoped to assigned content areas only:**

- Everything the Editor gets, plus:
- `publish_posts` / `publish_pages` — only within assigned areas
- `edit_published_posts` / `edit_published_pages` — within assigned areas

**Never granted:**

- `delete_others_posts` / `delete_others_pages` — Publishers can request trashing but not execute it
- Any capabilities outside content editing/publishing scope

### Admin / Super Admin behavior

Admins retain all standard WordPress Admin capabilities. They are treated as de facto Publishers for all content areas. They can also approve trash requests. The plugin does not modify the Administrator role in any way. It simply recognizes Admins as having implicit access to all workflow functions.

---

## Admin UI Lockdown

Editors and Publishers should see an extremely limited WordPress admin. They should have access to:

- The WordPress Dashboard (homepage only)
- Posts (filtered to their assigned content areas)
- Pages (filtered to their assigned content areas)
- Media (full library browse, limited edit/delete — see Media section below)
- The ASAE Publishing Workflow submenu items relevant to their role
- Their own Profile

They should NOT see or access: Appearance, Plugins, Settings, Tools, Users, Comments (unless needed), or any other admin menu items. Accomplish this by hooking into `admin_menu` to remove unauthorized menu items, and by hooking into `admin_init` to redirect unauthorized admin page accesses back to the dashboard. Do not rely solely on hiding menu items — enforce access at the capability check and redirect levels.

---

## Media Library Access

Editors and Publishers should have **full read access to the entire Media Library**. They can browse, search, and insert any existing media into their content. This is essential for a large site where shared assets (logos, stock photos, infographics, headshots) are used across content areas.

### What Editors and Publishers CAN do

- Browse and search the full Media Library
- Insert any media item into posts/pages they are editing
- Upload new media files (which are attributed to them as the uploader)
- Edit their own uploads' metadata (title, alt text, caption, description) since they may need to set alt text when uploading new images

### What Editors and Publishers CANNOT do

- Delete any media files, including their own uploads. Deleting media can break other content that references it. Map `delete_posts` for attachments to `do_not_allow` for both roles. If they need something deleted, they contact an Admin.
- Edit media files uploaded by others — they cannot change the title, alt text, caption, or description of someone else's upload, nor can they replace the file. This prevents accidental overwrites of shared assets.

### Implementation

Grant `upload_files` in the base role (already specified). For the Media Library view, do NOT filter `pre_get_posts` on the `upload.php` screen — let them see everything. Instead, control edit/delete actions via `map_meta_cap`: on `edit_post` for the `attachment` post type, allow only if the current user is the uploader (`post_author`) or is an Admin. On `delete_post` for attachments, map to `do_not_allow` for both Editor and Publisher roles. In the Media Library grid view, visually suppress the "Delete Permanently" link for non-Admins via CSS and enforce via capability check. In the list view, remove the "Delete" bulk action for these roles.

---

## Content Area Assignments (the core data model)

### Database Table: `{prefix}asae_pw_assignments`

```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_id         BIGINT UNSIGNED NOT NULL
role            VARCHAR(20) NOT NULL  -- 'editor' or 'publisher'
term_id         BIGINT UNSIGNED NOT NULL  -- references asae_content_area taxonomy term
assigned_by     BIGINT UNSIGNED NOT NULL
assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

- Key on `(user_id, role, term_id)` with a unique constraint to prevent duplicates.
- A user can have multiple assignments (multiple content areas).
- A user could theoretically be an Editor for one area and a Publisher for another, though this would be unusual.
- When checking permissions, always query this table — do not cache assignments in user meta or transients in a way that could go stale. Use object caching within a single request only.

### Assignment Admin UI

Under the ASAE > Publishing Workflow submenu, provide an **Assignments** screen. This is the single source of truth for who owns what. It should display:

- A table of all current assignments, sortable and filterable by user, role, and content area
- An "Add Assignment" form: select a user, select a role (Editor or Publisher), select one or more Content Area terms
- Bulk actions: remove selected assignments
- Only Admins can access this screen

This is the interface an Admin uses to say "Sarah is an Editor for Professional Development and Meetings" or "James is a Publisher for Professional Development." It should be immediately clear from this screen who has access to what.

---

## Permission Enforcement (class-asae-pw-permissions.php)

**This is the most critical file in the plugin.** Every permission check across every WordPress entry point flows through here. It must be airtight.

### Hook: `user_has_cap` filter (priority 10)

This filter dynamically grants capabilities to ASAE PW Editor and ASAE PW Publisher roles based on their content area assignments. It fires on every `current_user_can()` check.

Logic:

1. If the user is an Admin, return early — do not modify.
2. If the user is not an ASAE PW Editor or ASAE PW Publisher, return early.
3. For capability checks that are post-specific (the `$args` array contains a post ID), look up the post's Content Area terms and check whether the user has an assignment for any of those terms.
4. If yes and the user is an Editor, grant `edit_post` (but NOT `publish_post`).
5. If yes and the user is a Publisher, grant `edit_post` AND `publish_post`.
6. If the post has no Content Area terms assigned, deny access to both roles (orphaned content is Admin-only). This is important — it prevents the "untagged content is editable by everyone" hole.
7. For non-post-specific capability checks (like `edit_posts` which controls whether the menu appears), grant it if the user has any assignments at all.

### Hook: `map_meta_cap` filter (priority 10)

This is the lower-level capability mapping. When WordPress checks `edit_post` for a specific post, it maps it to primitive capabilities via `map_meta_cap`. Hook here to:

1. For `edit_post`, `delete_post`, `publish_post` — check the post's Content Area against the user's assignments.
2. For `delete_post` specifically — always map to `do_not_allow` for Editors and Publishers. They cannot delete, only request trashing.
3. For `publish_post` — map to `do_not_allow` for Editors, regardless of assignments. Editors never publish.

### Hook: `wp_insert_post_data` filter (priority 10)

**This is where you prevent unauthorized publishes BEFORE the database write.** This is critical — `save_post` fires after the write, which is too late.

Logic:

1. If the current user is an ASAE PW Editor and the incoming `post_status` is `publish` or `future` (scheduled), force it back to `pending`.
2. If the current user is an ASAE PW Publisher, check their content area assignments against the post's Content Area terms. If they don't have an assignment for this post's area, force the status back to whatever it was before.
3. Log any forced status changes to the activity log.
4. Handle taxonomy changes here too: compare the incoming Content Area term assignments (from `$_POST` or REST request data) against the current terms. If an Editor is changing them, store the proposed change as post meta (`_asae_pw_proposed_content_area`) and preserve the original terms. Do not actually change the taxonomy assignment.

### Hook: `rest_pre_insert_post` filter

Apply identical logic to REST API requests (Gutenberg saves). The `wp_insert_post_data` filter should catch most cases, but add this as a belt-and-suspenders safeguard since the REST API can bypass some traditional hooks depending on the request flow.

### Hook: `wp_ajax_inline-save`

Intercept Quick Edit saves. Before WordPress processes the inline save, validate that the current user has permission for the target post and that the status change is allowed. If an Editor is trying to set status to `publish` via Quick Edit, deny it. If a Publisher is trying to publish content outside their area, deny it.

### Bulk Edit

Similarly intercept `wp_ajax_bulk-edit-posts`. Apply the same status and area checks to every post in the bulk operation. If even one post in the batch is outside the user's area, reject the entire batch and display an error.

### Post List Filtering

Hook into `pre_get_posts` to filter the Posts and Pages list screens. Editors and Publishers should only see posts that belong to their assigned Content Areas. Do not just hide the rows — modify the query itself so that the post counts are accurate and there is no way to access a post by manually entering a URL with a post ID outside their area. Also hook into `redirect_post_location` or `load-post.php` to block direct URL access to the post editor for posts outside a user's area.

---

## Workflow State Machine (class-asae-pw-workflow.php)

### Database Table: `{prefix}asae_pw_submissions`

```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
post_id         BIGINT UNSIGNED NOT NULL
submitted_by    BIGINT UNSIGNED NOT NULL
reviewed_by     BIGINT UNSIGNED DEFAULT NULL
status          VARCHAR(20) NOT NULL DEFAULT 'pending'  -- pending, approved, rejected, cancelled
submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
reviewed_at     DATETIME DEFAULT NULL
submit_note     TEXT DEFAULT NULL
review_note     TEXT DEFAULT NULL
```

Key on `(post_id, status)` for fast lookups of pending submissions.

### Workflow States

A post in this system has a combined state of its WordPress `post_status` plus its workflow submission status:

- **Draft** — Editor is working on it. No submission exists or previous submission was rejected.
- **Pending Review** — Editor has submitted it. A `pending` submission record exists. WordPress `post_status` = `pending`.
- **Published** — A Publisher approved the submission. WordPress `post_status` = `publish`.
- **Rejected** — A Publisher rejected the submission. WordPress `post_status` reverts to `draft`. The submission record status = `rejected`. Editor gets notified with the Publisher's comments.

### Editor Actions

- **Create** new content (as draft) within their assigned Content Areas.
- **Edit** draft or rejected content in their areas.
- **Edit published content** in their areas — but the changes must be saved as a pending revision, NOT applied directly. Use a shadow draft approach (see below).
- **Submit for Review** — creates a submission record and notifies all Publishers assigned to the post's Content Area(s), plus all Admins.
- **Request Trash** — creates a trash request (see Trash section below) and notifies Admins.

### Shadow Draft System for Editing Published Content

When an Editor edits a published post, the live published content must not change. Instead, create a hidden "shadow" draft — a copy of the published post saved as a draft with a post meta flag linking it back to the original.

- Add a post meta key `_asae_pw_shadow_of` on the shadow draft pointing to the original published post ID.
- Add `_asae_pw_has_shadow` on the published post pointing to the shadow draft ID.
- The Editor edits the shadow draft using the normal post editor.
- When a Publisher approves, the shadow draft's content overwrites the published post and the shadow is deleted.
- Filter the post list so Editors see the shadow draft instead of (or alongside) the published original, clearly labeled (e.g., "Professional Development Guide — Pending Changes").
- The shadow draft should copy over: post content, post title, post excerpt, featured image, custom fields, and any proposed Content Area taxonomy changes (stored as post meta, not applied).

This approach is more robust than storing changes as serialized post meta because it uses the native post editor normally, preserves revision history on the shadow, and doesn't require custom rendering logic in Gutenberg.

### Publisher Actions

- **All Editor actions** within their assigned areas, except Publishers can also publish directly (no submission required for their own work).
- **Review submissions** — see a list of pending submissions for content in their assigned areas.
- **Approve** — publishes the content (or for shadow drafts: copies shadow content to the published post and deletes the shadow). Creates an `approved` submission record. Notifies the submitting Editor.
- **Reject** — reverts the post to draft (or keeps the shadow draft as a draft). Requires a comment explaining what needs to change. Creates a `rejected` submission record. Notifies the submitting Editor with the comment.

### Admin Actions

- All Publisher actions across all Content Areas.
- Additionally: approve trash requests, manage assignments, manage Content Area terms, access settings.

### Concurrent Approval Safeguard

If two Publishers are assigned to the same area and both try to approve the same submission, the second approval must gracefully no-op, not create a duplicate publish event. Check submission status immediately before processing approval — if it is no longer `pending`, display a notice explaining it has already been reviewed.

---

## Trash Request Workflow (class-asae-pw-trash.php)

### Database Table: `{prefix}asae_pw_trash_requests`

```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
post_id         BIGINT UNSIGNED NOT NULL
requested_by    BIGINT UNSIGNED NOT NULL
reviewed_by     BIGINT UNSIGNED DEFAULT NULL
status          VARCHAR(20) NOT NULL DEFAULT 'pending'  -- pending, approved, denied
reason          TEXT NOT NULL
review_note     TEXT DEFAULT NULL
requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
reviewed_at     DATETIME DEFAULT NULL
```

When an Editor or Publisher clicks "Request Trash" (which replaces the normal "Move to Trash" link in their UI), it creates a record here and sends an email notification to all Admins. Admins see pending trash requests on the Publishing Workflow dashboard and can approve (which actually trashes the post) or deny (with a comment back to the requester).

The normal WordPress "Trash" action must be completely blocked for Editors and Publishers via `map_meta_cap`. Remove the "Move to Trash" link from their post list and post editor. Replace it with "Request Trash" which opens a modal requiring a reason.

---

## Activity Log (class-asae-pw-activity-log.php)

### Database Table: `{prefix}asae_pw_activity_log`

```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
post_id         BIGINT UNSIGNED NOT NULL
user_id         BIGINT UNSIGNED NOT NULL
action          VARCHAR(50) NOT NULL
detail          TEXT DEFAULT NULL
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

Add indexes on: `post_id` (for the per-post meta box queries, which will be the most frequent access pattern), a composite index on `(user_id, created_at)` for user-filtered searches, and `created_at` for date range queries.

### Logged Actions

Log every meaningful action:

- `created` — new post created
- `edited` — post content modified
- `submitted` — submitted for review
- `approved` — submission approved by publisher
- `rejected` — submission rejected with comment
- `published` — post status changed to publish
- `status_changed` — any post status transition, with old and new status in detail
- `taxonomy_changed` — Content Area assignment changed (by Publisher or Admin)
- `taxonomy_change_proposed` — Editor proposed a Content Area change (pending approval)
- `taxonomy_change_approved` — proposed Content Area change was approved
- `trash_requested` — trash request created
- `trash_approved` — trash request approved by admin
- `trash_denied` — trash request denied by admin
- `shadow_created` — shadow draft created for editing published content
- `shadow_merged` — shadow draft content merged into published post on approval
- `notification_sent` — email notification sent, with recipient and subject in detail

### Access Point 1: Post/Page Edit Screen — Inline Activity History

Add a meta box at the **bottom** of every post and page edit screen (below the content editor, not in the sidebar) titled **"Publishing Activity"**. This displays the complete activity history for just that one post, in reverse chronological order (newest first). Each entry shows:

- Date and time
- User display name
- Action (as a human-readable label, e.g., "Submitted for review," "Approved by," "Rejected — see comments," "Content Area change proposed," etc.)
- Detail/comment if applicable (e.g., rejection comments displayed inline)

This meta box is visible to anyone who can edit the post — Editors see it for posts in their areas, Publishers see it for posts in their areas, Admins see it everywhere. It is not editable — it is purely informational display. Style it as a timeline/log format, visually distinct from the editable content above.

The meta box should load the most recent 20 entries by default, with a "Load more" button (AJAX) if there are older entries. For posts with no activity history (e.g., pre-existing content from before the plugin was installed), display "No publishing activity recorded for this item."

### Access Point 2: Post/Page List Screen — Activity Log Link

In the Posts and Pages admin list tables, add a row action link labeled **"Activity"** alongside the existing Edit, Quick Edit, Trash/Request Trash links. Clicking this link navigates to the admin Activity Log search page with the post ID pre-filled as a filter parameter, showing only activity for that specific post. The URL format:

```
admin.php?page=asae-pw-activity-log&post_id=123
```

This gives users a quick way to jump to a full-page view of a single post's history from the list screen without opening the post editor.

### Access Point 3: Admin Search Page — Search-First Interface

Under ASAE > Publishing Workflow > Activity Log, provide a **search-first interface**. When the page loads, it should **not display any log entries by default**. Instead, display a search/filter form at the top with the following fields:

- **Post** — a search-as-you-type field that finds posts/pages by title. Selecting one filters to that item's activity. If the page was reached via the list table "Activity" link, this field is pre-populated and results display immediately.
- **User** — dropdown of all users who have activity log entries, or search-as-you-type.
- **Action type** — dropdown: All, Created, Edited, Submitted, Approved, Rejected, Published, Status Changed, Taxonomy Changed, Trash Requested, Trash Approved, Trash Denied, Shadow Created, Shadow Merged.
- **Content Area** — dropdown of all Content Area terms.
- **Date range** — from/to date pickers.

Below the filter form, display a message: "Use the filters above to search the activity log." Once the user applies at least one filter, display results in a paginated table (25 per page) with columns: Date, User, Post Title (linked to the post editor), Action, Content Area, Detail.

**Visibility rules for the search page:**

- Admins can search all activity across all content areas, all users, all posts.
- Publishers can search activity only for posts within their assigned Content Areas. The User and Post filters should only show users/posts relevant to their areas.
- Editors can search activity only for their own actions. The User filter is locked to themselves. The Post filter only shows posts in their assigned areas.

**Export:** Include a "Download CSV" button that exports the current filtered result set. Available to Admins only.

---

## Notifications (class-asae-pw-notifications.php)

Use `wp_mail()`. All notification emails should be professional, concise, and include a direct link to the relevant post in the WordPress admin.

### Notification Triggers

- **Editor submits for review** → email all Publishers assigned to the post's Content Area(s), plus all Admins
- **Publisher approves** → email the Editor who submitted
- **Publisher rejects** → email the Editor who submitted, include the rejection comment prominently
- **Editor or Publisher requests trash** → email all Admins
- **Admin approves trash** → email the requester
- **Admin denies trash** → email the requester with the denial comment
- **Editor proposes Content Area change** → email all Publishers for both the current and proposed Content Areas, plus all Admins

All notifications should be logged to the activity log with the action `notification_sent` and detail including recipient and subject.

---

## Settings (class-asae-pw-settings.php)

Register a settings page under ASAE > Publishing Workflow > Settings. Only Admins can access it.

### Settings Options

**Disable XML-RPC** — checkbox, default OFF. When enabled, the plugin adds a filter: `add_filter('xmlrpc_enabled', '__return_false');` and also hooks into `xmlrpc_methods` to return an empty array. Display a notice explaining that this disables all XML-RPC functionality site-wide, including the WordPress mobile app's legacy connection method.

**Notification sender name** — text field. The "From" name on notification emails. Default: site name.

**Notification sender email** — email field. The "From" address on notification emails. Default: admin email.

**Post types** — checkboxes for which post types are covered by the workflow. Default: Posts and Pages. Allow Admins to extend to custom post types.

**Orphaned content behavior** — radio buttons:

- "Only Admins can edit content with no Content Area assigned" (default, recommended)
- "All Editors and Publishers can edit content with no Content Area assigned" (less safe, but useful during migration/setup)

---

## Cache Purge (class-asae-pw-cache.php)

Hook into `transition_post_status` (priority 10). Whenever a post status changes (in any direction — publish to draft, pending to publish, etc.):

1. Call `clean_post_cache($post_id)` to clear WordPress's internal object cache for the post.
2. Fire `do_action('asae_pw_post_status_changed', $post_id, $new_status, $old_status)` so external caching plugins can hook in.
3. If the function `wp_cache_flush_group` exists (object caching), flush the post's cache group.
4. If the site is using a known caching plugin (detect by class existence), call its purge function for the specific post URL. Support: WP Super Cache (`wpsc_delete_post_cache`), W3 Total Cache (`w3tc_flush_post`), WP Rocket (`rocket_clean_post`), LiteSpeed Cache (`LiteSpeed_Cache_Purge::purge_post`).

---

## GitHub Auto-Update (class-asae-pw-updater.php)

Follow the same pattern as the other ASAE plugins. This class should:

- Check a GitHub repository for new releases (via GitHub Releases API)
- Compare the remote version tag against the local plugin version constant
- Hook into WordPress's `pre_set_site_transient_update_plugins` and `plugins_api` filters to inject update information
- Support the standard WordPress plugin update flow (notification badge, one-click update from Plugins screen)
- Use the same GitHub organization/repo naming convention as the other ASAE plugins

---

## Plugin Lifecycle

### Activation (`register_activation_hook`)

1. Create the four database tables (`asae_pw_assignments`, `asae_pw_submissions`, `asae_pw_trash_requests`, `asae_pw_activity_log`) using `dbDelta()`.
2. Register the `asae_content_area` taxonomy (also registered on `init`, but do it here to ensure it exists before role creation).
3. Create the two custom roles (`asae_pw_editor`, `asae_pw_publisher`) with their minimal base capabilities. If roles already exist (reactivation), update their capabilities to match the current spec.
4. Flush rewrite rules.
5. Set a default options array in `wp_options` for the plugin settings.

### Deactivation

Flush rewrite rules. Do NOT remove roles or tables — the Admin may reactivate.

### Uninstall (uninstall.php)

On uninstall (deletion from Plugins screen):

1. Remove the two custom roles.
2. Drop the four database tables.
3. Delete all plugin options from `wp_options`.
4. Delete all post meta keys prefixed with `_asae_pw_`.
5. Optionally: unregister the taxonomy and clean up term relationships. (Include an "are you sure" option in settings for whether uninstall should delete Content Area terms and their assignments, since those affect content organization.)

---

## Accessibility

Follow the same accessibility standards as the other ASAE plugins:

- All admin UI elements must be keyboard navigable.
- Form fields must have associated `<label>` elements.
- Status indicators must not rely solely on color — use text labels and/or icons alongside color.
- All interactive elements must have visible focus states.
- Admin notices and error messages must use WordPress's standard `admin_notices` hook with appropriate `notice-success`, `notice-error`, `notice-warning` classes.
- Modal dialogs (for submission notes, rejection comments, trash request reasons) must trap focus, be dismissible with Escape, and return focus to the triggering element on close.
- Data tables must use proper `<th>` headers with `scope` attributes.

---

## Security

- All database queries must use `$wpdb->prepare()` — no exceptions.
- All form submissions must include and verify a nonce.
- All AJAX handlers must verify nonces and check `current_user_can()` before processing.
- All output must be escaped with `esc_html()`, `esc_attr()`, `esc_url()`, or `wp_kses_post()` as appropriate.
- Direct file access must be blocked with `if (!defined('ABSPATH')) exit;` at the top of every PHP file.
- The REST API endpoints (if any are added) must include `permission_callback` functions that verify content area assignments, not just basic role checks.

---

## Key Implementation Risks to Test

These are specific scenarios that must be verified in the development environment:

1. **Editor tries to publish via Gutenberg REST API** — must be blocked by `wp_insert_post_data` and/or `rest_pre_insert_post`.
2. **Editor tries to publish via Quick Edit** — must be blocked by AJAX intercept.
3. **Editor tries to schedule a post (future status)** — must be blocked identically to publish.
4. **Editor removes Content Area taxonomy from a post** — must be intercepted and treated as a proposed change, not applied directly.
5. **Plugin is deactivated** — Editors and Publishers should be unable to do anything meaningful (cannot publish, cannot edit others' posts, cannot access admin screens beyond dashboard and profile).
6. **Autosave race condition** — status changes via workflow must not collide with WordPress autosave; never overwrite `post_content` during a status transition.
7. **Post with no Content Area assigned** — must be inaccessible to Editors and Publishers (per default setting).
8. **Shadow draft approval** — content must transfer cleanly from shadow to published post without losing formatting, custom fields, or featured image.
9. **Cache purge on rejection** — if a published post is reverted to draft, the cached public version must be invalidated.
10. **Concurrent Publisher approval** — if two Publishers try to approve the same submission, the second must gracefully no-op.
11. **Direct URL access** — an Editor manually entering `post.php?post=123&action=edit` for a post outside their area must be redirected, not shown a broken editor.
12. **Bulk Edit status change** — an Editor selecting multiple posts and bulk-changing status to Published must be blocked entirely.
13. **Media deletion attempt** — an Editor or Publisher attempting to delete media (via any method) must be denied.
14. **Shadow draft with Content Area change** — when a shadow draft includes a proposed taxonomy change, approval must apply both the content changes and the taxonomy change atomically.
