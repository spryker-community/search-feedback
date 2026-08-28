# Spryker Search Feedback

[![CI](https://github.com/spryker-community/search-feedback/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/spryker-community/search-feedback/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-2a6b2a)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

A voice-of-customer capture tool for the search results page (SRP): an authorized storefront customer can
file a free-text ticket about a set of search results — tied to the query term, active filters, page
number, and the SKUs actually shown on that page, plus a topic — and Zed back-office admins hold a
conversation about it from there. The Yves side is write-only: once a ticket is submitted there is no way
to read it, or any reply, back from the storefront. Everything past submission happens in Zed.

*Part of the [Search Relevance](https://search-relevance.dev/) project.*

> **Not an official Spryker project.** `spryker-community/*` is an independent, community-built
> package namespace with no affiliation to, sponsorship by, or endorsement from Spryker Systems GmbH.
> The name describes what these packages are (community contributions for Spryker Commerce OS), not who
> maintains them. The matching Packagist namespace is held by an unrelated GitHub organization, which is
> why installation goes through a VCS repository entry rather than a plain `composer require` — see
> [Installation](#installation).

## Contents

- [Why a separate package](#why-a-separate-package)
- [Status](#status)
- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Limitations](#limitations)
- [Testing and CI](#testing-and-ci)
  - [Automated checks](#automated-checks)
  - [Test suite](#test-suite)
  - [Known coverage gaps](#known-coverage-gaps)
  - [Browser (Presentation) suite](#browser-presentation-suite)
- [License](#license)

## Why a separate package

Structurally close to [spryker-community/search-ranking-optimizer](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking-optimizer)'s
SRP relevance-rating widget (Yves widget → Gateway-controller write → Zed persistence), but a different
bounded context: this is qualitative support/VoC ticketing, not a numeric ranking signal. It has no hard
dependency on `search-ranking`/`search-ranking-optimizer`'s tuning machinery — the optional integration
that lets a ticket's frozen snapshot also carry search-ranking's specificity-weighting result is a soft
`suggest`-only coupling (see [Installation](#installation)) that additionally only produces anything once
search-ranking's specificity weighting itself is turned on (off by default there — see its own README's
step 14c), the same shape `search-ranking` itself already uses toward `search-debug`. Kept standalone so a
shop can install it without any of those.

## Status

Feature-complete for its scope. Verified: 134 Codeception tests (Client, Zed, Zed GUI and Yves layers plus
both browser Presentation suites, including real-database integration coverage for the full submit → reply
→ status-change → list/find round trip), phpcs clean, and the package's public `SearchFeedbackFacade` at
100% method coverage. See [Testing and CI](#testing-and-ci) below for the measured numbers and the
(deliberate, documented) gaps.

## What it does

- **Yves**: a plain HTML form on the SRP (topic dropdown + free-text body), visible only to a customer
  holding `SubmitSearchFeedbackTicketPermissionPlugin`. Submitting redirects back to the SRP with a flash
  message — no AJAX, no new frontend component.
- **Zed**: a ticket grid + per-ticket conversation thread. Any Zed admin with access to the module can view
  every ticket and reply; changing a ticket's status (open/answered/closed) is its own controller action so
  it can be independently restricted to a "ticket worker" ACL group, leaving a "feedback admin" group able
  to view and reply but not triage. There is no per-submitter row-level scoping — Zed users and Yves
  customers are separate identity systems with no built-in link, so both Zed roles see the same full list.
  The grid has an optional Store/Locale filter (two dropdowns, "all" by default — this is a cross-scope
  triage view, not a per-market page); the ticket detail page shows the customer's email (resolved from
  their customer reference, cached per page load) instead of the raw reference, and a "View search results"
  link that reconstructs the exact SRP the ticket was filed from, in either dynamic-store-mode configuration.
- **Frozen replay.** Ranking isn't stable over time in a typical shop — nightly randomized tie-breaking,
  regularly-refreshed business scores, an optimizer retuning formula weights — so re-running the same query
  later often shows a different page than the one a ticket was filed about. When the optional wiring in
  [Installation](#installation) is registered, this package captures the raw Elasticsearch response (and,
  if `spryker-community/search-ranking` is installed AND has specificity weighting turned on — off by
  default, see its README's step 14c — its specificity-weighting result) at the moment a
  ticket is filed, and the "View search results" link replays that exact frozen response instead of running
  a live search — while still rendering it through today's live template/formatter code, so only the
  ranking data itself is frozen. A ticket filed before this feature existed, or on a shop that hasn't wired
  it, falls back to a live search exactly as before — this is additive, never a hard requirement.

The SRP ticket form (rendered below the product grid, outside the filter form — see step 5), the Zed
ticket grid (List of Tickets, sortable/searchable via DataTables), and a ticket's detail page (context +
full conversation thread + reply form + status actions):

![The storefront search results page with a "Not happy with these results?" box below the product grid: a Topic dropdown (Relevance/Missing results/Wrong order/Filters-facets/Other), a free-text body field, and a Send Feedback button](docs/screenshots/yves-ticket-form.png)

![The Zed ticket grid: ID, topic, search term, a colored status badge (orange Open / blue Answered / green Closed), filed-at timestamp, and a View action per row](docs/screenshots/zed-ticket-list.png)

![The Zed ticket detail page: Ticket Context (topic, search term, filters JSON, page, SKUs shown, store/locale, status with Mark answered/Mark closed actions, filed-at), and Conversation showing the customer's original message and a Zed admin's reply, with a reply form below](docs/screenshots/zed-ticket-detail.png)

## Requirements

- PHP >= 8.3
- Spryker (kernel/gui/acl/customer/company-user/store/permission-extension/propel-orm/transfer/user/
  zed-request — see `composer.json` for floors, verified by `composer check-floors`)
- **Elasticsearch/OpenSearch, via `spryker/search-elasticsearch`.** Unlike earlier versions of this
  package, it now also *captures* the raw search response for a ticket's frozen replay (see
  [What it does](#what-it-does)) — but it still never issues its own query. The only place this package
  talks to the search engine at all is reconstructing a previously-captured response from stored data
  (`Elastica\Response`/`Query`/`ResultSet\DefaultBuilder`), never a live call. Propel/MySQL remains the only
  real datastore.
- **B2B company-user accounts.** `CompanyUserPermissionAuthorizer` resolves "does this customer actually
  hold `SubmitSearchFeedbackTicketPermissionPlugin`" via their active `CompanyUser`, the same
  permission-granting mechanism the rest of a B2B shop already uses (same posture as the sibling
  `search-ranking-optimizer` package's own rating-permission check). A B2C-only shop with no
  `CompanyUser` module has nothing to grant the permission to.

### Search engine compatibility

Verified on **OpenSearch 1.3.4, 2.11, 3.5.0 and Elasticsearch 8.11**. This package issues no live query
and reads no engine-version-specific response shape — it only reconstructs a previously-captured
`Elastica\Response` from stored data — so the engine version is not load-bearing here. The OpenSearch 3.5
upgrade needed **no code change** in this package; see
[Migrating to OpenSearch 3.x](docs/opensearch-3.x-migration.md) for why the frozen-replay reconstruction is
engine-version-agnostic, plus the one upgrade-time schema trap every Spryker shop hits.

## Installation

1. This package lives in Spryker's community GitHub org at
   [`github.com/spryker-community/search-feedback`](https://github.com/spryker-community/search-feedback).
   It is not yet published on Packagist under the `spryker-community` vendor namespace, so until that
   lands, install from a VCS repository:
   ```json
   "repositories": [
       {
           "type": "vcs",
           "url": "https://github.com/spryker-community/search-feedback"
       }
   ]
   ```
   ```bash
   composer require spryker-community/search-feedback:^1.4
   ```
2. Register the `SprykerCommunity` core namespace: add it to `KernelConstants::CORE_NAMESPACES` in
   `config/Shared/config_default.php`. Spryker's `ClassResolver` only ever looks in the project namespace
   plus whatever's listed here — miss this and every class in the package fails to resolve, most visibly as
   `Can not resolve `SearchFeedbackFacade` in Business layer for your module `SearchFeedback`` the moment
   anything tries to use the facade, even though composer installed the package correctly and every
   DependencyProvider below is wired up right.
   ```php
   $config[KernelConstants::CORE_NAMESPACES] = [
       // ... existing entries
       'SprykerCommunity',
   ];
   ```
3. Register `Pyz\Client\SearchFeedback\SearchFeedbackDependencyProvider`,
   `Pyz\Yves\SearchFeedbackWidget\SearchFeedbackWidgetDependencyProvider`,
   `Pyz\Zed\SearchFeedback\SearchFeedbackDependencyProvider`, and
   `Pyz\Zed\SearchFeedbackGui\SearchFeedbackGuiDependencyProvider` as project-level overrides of the
   package's own.
4. Register `SubmitSearchFeedbackTicketPermissionPlugin` in both the Client and Zed
   `PermissionDependencyProvider::getPermissionPlugins()`, and grant it to whichever company role should
   see the SRP ticket form (via `company_role_permission.csv` or the Company Role GUI).
5. Register `SearchFeedbackWidgetRouteProviderPlugin` in the Yves `RouterDependencyProvider` and
   `SearchFeedbackWidgetTwigPlugin` in the Yves `TwigDependencyProvider`.
6. Include the ticket-form view in your SRP template, gated on `canSubmitSearchFeedbackTicket()`.
   **It must render OUTSIDE any enclosing `<form>` your SRP template already has** (e.g. the catalog
   page's own filter/sort/pagination form). HTML doesn't allow nested forms — the browser silently drops
   the inner one, and clicking submit posts the OUTER form instead (wrong endpoint, wrong fields, no CSRF
   validation). Confirmed live: this exact breakage, when the include first landed inside that form.

   **Building `submitUrl` under `SPRYKER_DYNAMIC_STORE_MODE=1`.** If your shop runs with dynamic store
   mode enabled, don't pass `path('search-feedback-widget/submit-ticket')` straight into the molecule's
   `submitUrl` — route generation fails for any non-default store with `RouteNotFoundException: None of
   the chained routers were able to generate route: 'search-feedback-widget/submit-ticket' not found`,
   even though the exact same route matched fine on the way in.
   `StorePrefixRouterEnhancerPlugin::afterMatch()` only returns matched request *attributes*; it never
   calls `RequestContext::setParameter('store', ...)`, so generation has no store to work with for any
   route this package (or any other community package) registers. Build the URL by hand from the current
   request instead:
   ```twig
   {% set requestStore = app.request.attributes.get('store') %}
   {% set storePrefix = (requestStore is not empty and app.request.getPathInfo() starts with ('/' ~ requestStore ~ '/'))
       ? ('/' ~ requestStore)
       : '' %}
   {% include molecule('search-feedback-ticket-form', 'SearchFeedbackWidget') with {
       data: {
           canSubmit: canSubmitSearchFeedbackTicket(),
           searchTerm: data.searchString,
           pageNumber: data.pagination.currentPage | default(1),
           skuList: data.products | default([]) | map((product) => product.abstract_sku),
           csrfToken: searchFeedbackTicketCsrfToken(),
           submitUrl: storePrefix ~ '/search-feedback-widget/submit-ticket',
           topics: getSearchFeedbackTicketTopics(),
           snapshotToken: data.searchFeedbackSnapshot.token | default(''),
       },
   } only %}
   ```
   Verified against `/DE/...`, `/AT/...`, and no-prefix requests — all resolve to the correct `action` URL.
   Shops that don't run dynamic store mode can use `path()` directly and skip this.
7. Copy the `<search-feedback-gui>` block from this package's `Communication/navigation.xml` into your
   project's `config/Zed/navigation.xml`. If your project already lists every top-level Zed nav group
   explicitly (rather than relying on Spryker's default full-merge, i.e. `ZedNavigationConfig::
   getMergeStrategy()` returns `BREADCRUMB_MERGE_STRATEGY`), a brand-new top-level group silently never
   renders — nest the block as a `<pages>` entry inside a top-level group instead, either an existing one
   (e.g. `merchandising`, next to the sibling `search-preferences` entry) or a new project-owned one you
   create yourself (e.g. a shared `search-toolbox` category grouping this package with
   `spryker-community/search-index-alias` — see that package's own README and this project's
   `config/Zed/navigation.xml` for a worked example). In that case **don't** also copy the package's own
   `<search-feedback-tickets>`/`<search-feedback-ticket-detail>` children into your root file — leave your
   root entry childless and let them merge in automatically from the package's own `navigation.xml`.
   Redeclaring them yourself causes `array_merge_recursive` to collide on the duplicate scalar leaves
   (same key, same string value) and turn them into arrays, which crashes the page with
   `Twig\Error\RuntimeError: ... ("Array to string conversion") in "@Gui/Partials/navigation.twig"`.
   Then run `console navigation:cache:remove` + `console navigation:build-cache` to pick up the change —
   the Zed nav tree is cached and does not re-read `navigation.xml` on every request.
8. **Warm the Zed Backoffice router cache**: `console router:cache:warm-up:backoffice`. Zed's nav renderer
   drops any item whose navigation-XML key isn't found in the *cached* Backoffice route collection
   (`BackofficeNavigationItemCollectionRouterFilter`) — with a stale cache the "Search Feedback" entry from
   step 7 is silently missing from the sidebar (no error, no log, it just isn't there) even though the
   page itself is reachable by typing the URL directly. Easy to miss because every *other* Zed page keeps
   working; only a newly-added bundle's own nav entry is affected.
9. Run `console transfer:generate`, `console propel:diff` + `console propel:migrate` (not
   `propel:sql:insert` — that reapplies the full schema dump), `console propel:model:build`, and
   `console dev:ide-auto-completion:generate`. `propel:model:build` is easy to skip since neither `diff` nor
   `migrate` builds PHP classes, only the database schema — missing it surfaces as `Class
   "Orm\Zed\SearchFeedback\Persistence\SpySearchFeedbackTicketQuery" not found` the first time anything
   touches the ticket table.
10. Warm up the Zed **BackendGateway** router: `console router:cache:warm-up:backend-gateway`. It's a
    separate cache from the Backoffice router's (step 8) — every other page can work fine while ticket
    submission alone 404s (`No route found for "POST .../search-feedback/gateway/submit-ticket"`) until
    this runs. Same gotcha the sibling `search-ranking-optimizer` package's own Gateway controller has.
    Also re-warm the **Yves** router cache — `yves router:cache:warm-up` — since step 5 adds a new Yves
    route; skipping it renders the SRP with a `RuntimeError: None of the chained routers were able to
    generate route: Route 'search-feedback-widget/submit-ticket' not found` the moment the ticket-form
    include tries to build its `submitUrl`. Standard practice for any package that adds a Yves route, not
    unique to this one, but easy to forget mid-walkthrough since nothing points at it explicitly.
11. **Frozen replay wiring** (optional but recommended — without it, "View SRP" on the Zed ticket detail
    page just re-runs a live search, which is the exact drift this feature exists to close; see
    [What it does](#what-it-does)):
    - Register `SearchFeedbackSnapshotResultFormatterPlugin` in the Client `CatalogDependencyProvider`'s
      `CATALOG_SEARCH_RESULT_FORMATTER_PLUGINS` list. **This alone is not enough if your SRP twig template
      whitelists which controller-returned fields it forwards into `data`** — SprykerShop's own
      `CatalogPage` search template does exactly that
      (`{% define data = {...} %}` in `Theme/default/views/search/search.twig`, reading from `_view.*`), and
      a project that copied/extended that template for other community packages (search-debug's
      `searchDebugTokens`, search-ranking's `randomImpactIsActive`, …) has to add this package's key the
      same way:
      ```twig
      searchFeedbackSnapshot: _view.searchFeedbackSnapshot | default,
      ```
      Confirmed live: without this line the plugin still runs and captures correctly every time (a session
      entry always appears), but `data.searchFeedbackSnapshot.token` is silently empty in the ticket-form
      include below — the hidden `snapshotToken` field never renders, every ticket saves with zero snapshot
      rows, and nothing anywhere errors. This is the single most notorious silent-failure point in this
      whole step; if `search-feedback:check-installation`'s frozen-replay section is green but tickets still
      have no snapshot, check this template mapping first.
    - Register `SearchFeedbackReplayContextEventDispatcherPlugin` in the Yves
      `EventDispatcherDependencyProvider::getEventDispatcherPlugins()`.
    - Register `ViewSearchFeedbackTicketReplayPermissionPlugin` in both the Client and Zed
      `PermissionDependencyProvider::getPermissionPlugins()`, and grant it to whichever company role should
      be able to review a replay — same mechanism as step 4, a separate, independently-grantable permission.
      **A brand-new permission plugin needs one extra step the Company Role GUI won't do for you**: Spryker
      only knows about a permission plugin once it's been synced into `spy_permission`, and that sync is not
      automatic on deploy. Visit `/permission/index/sync` in Zed once (or click "Sync permissions" under
      Maintenance in the sidebar) after registering the plugin. Skipping this doesn't just hide the
      permission from the grant checkbox — it makes the Company Role edit/create page throw a hard
      `ErrorException: Undefined array key "ViewSearchFeedbackTicketReplayPermissionPlugin"` for *every*
      company role, not just ones that would hold this permission, because that page always evaluates every
      registered permission plugin against `spy_permission`. Confirmed live. This is a generic Spryker
      gotcha, not specific to this package, but it bites hard the first time a project adds its first
      community-package permission plugin. Once synced, this package's `data/glossary.csv` already ships
      the `permission.name.ViewSearchFeedbackTicketReplayPermissionPlugin` label the Company Role GUI needs
      to render the checkbox (same `permission.name.*` convention `SubmitSearchFeedbackTicketPermissionPlugin`
      uses, step 4) — re-run `vendor/bin/console data:import glossary` (step 13) if that page instead throws
      `MissingTranslationException: Could not find a translation for key permission.name....`.
    - Add a project-level `Pyz\Client\SearchElasticsearch\SearchElasticsearchFactory` overriding
      `createSearchClient()` to wrap the real `Search` in `ReplayCapableSearch` — this is the seam that
      actually swaps a live ES call for the frozen snapshot; see the class's own docblock. First
      Factory-level (not just DependencyProvider-level) override this package needs — a new pattern if
      your project hasn't overridden a vendor Client Factory before, but a small one:
      ```php
      use Spryker\Client\Kernel\Locator; // NOT Spryker\Shared\Kernel\Locator — that class doesn't exist.
                                          // Locator is a separate concrete class per layer; only the
                                          // interface it implements is shared. Since this file is Client-
                                          // layer code, this is the Locator to use.

      class SearchElasticsearchFactory extends SprykerSearchElasticsearchFactory
      {
          public function createSearchClient(): SearchInterface
          {
              return new ReplayCapableSearch(
                  parent::createSearchClient(),
                  Locator::getInstance()->searchFeedback()->client(),
                  Locator::getInstance()->customer()->client(),
              );
          }
      }
      ```
    - Optional, only if `spryker-community/search-ranking` is also installed: register
      `SprykerCommunity\Client\SearchRanking\Plugin\SearchFeedback\SearchFeedbackTermVectorSnapshotProviderPlugin`
      in your project's `Pyz\Client\SearchFeedback\SearchFeedbackDependencyProvider::getTermVectorSnapshotProviderPlugins()`
      override, so a ticket's snapshot also carries the specificity-weighting result that scored it. That
      result is only ever non-null once search-ranking's specificity weighting is turned on too — it's
      **off by default** there, a project-level override of
      `Pyz\Client\SearchRanking\SearchRankingConfig::isSpecificityWeightingEnabled()` (see search-ranking's
      README, step 14c). Registering this plugin without that flag is harmless, just a no-op: every
      snapshot's `hasTermVectorSnapshot` flag stays `false`.
12. In the Zed ACL module, create your "ticket worker" and "feedback admin" groups and grant/deny access to
    `SearchFeedbackGui/Detail/changeStatus` accordingly — this package ships no ACL fixture data.
13. **Translations.** Two separate mechanisms, one per layer — Zed's `trans` filter does **not** read from
    the Yves-facing Glossary module, same split as the sibling `search-ranking`/`search-ranking-optimizer`
    packages:
    - **Zed GUI** (ticket list/detail, reply form, status labels): ships as
      `spryker/translator` CSV catalogs under [`data/translation/Zed/`](data/translation/Zed/). If your
      project already extended `Pyz\Zed\Translator\TranslatorConfig::getCoreTranslationFilePathPatterns()`
      with the `spryker-community/*` glob for a sibling package, this package is auto-discovered by the
      same glob — no extra step. Otherwise add it once:
      ```php
      $coreTranslationFilePathPatterns[] = APPLICATION_VENDOR_DIR . '/spryker-community/*/data/translation/Zed/[a-z][a-z]_[A-Z][A-Z].csv';
      ```
    - **Yves widget** (ticket form + the check-installation page below): a plain
      [`data/glossary.csv`](data/glossary.csv), imported the normal Spryker way (the same Redis-backed
      Glossary module every Yves-facing string in a Spryker shop already uses):
      ```bash
      vendor/bin/console data:import glossary
      ```
14. **Verify the installation.**
    ```bash
    vendor/bin/console search-feedback:check-installation
    ```
    Most of the steps above fail *silently* when missed — the ticket form simply never appears, or
    appears but 404s on submit, with nothing in any log to say why. This command checks the core
    namespace registration, that every plugin class is loadable, and that the ticket table is reachable
    (a real DB round trip — the fastest way to notice step 9 was skipped). It exits non-zero and names
    the remedy for whatever is wrong, and explicitly flags the Backend Gateway router cache (step 10) —
    the single most notorious silent-failure point, since every other Zed page keeps working while ticket
    submission alone 404s until it's warmed.

    It also reports whether anybody other than a root-style admin can reach this package's Zed pages. Zed
    access is deny-by-default outside a matching ACL rule, and a nav entry the current user has no rule for is
    filtered out of the sidebar entirely rather than 403ing — so on a shop with real restricted back-office
    roles, "nobody adjusted ACL" looks exactly like "the package was never installed". A default Spryker
    install needs nothing done here (`root_role` holds a total wildcard), which is why this is a **warning at
    most, never a failure**, and only when restricted roles exist and not one of them has a rule for this
    package's module. Restricting these pages to root-style admins is a perfectly ordinary choice; the command
    cannot know which roles you meant to grant, so it asks you to confirm rather than telling you to fix.

    It is explicit about its own blind spots: running in Zed, it never bootstraps the Yves DI container,
    so it cannot confirm the route/Twig plugins from step 5 or the template include from step 6 — it says
    so in its output.

    It also reports on frozen replay (step 11, optional): it checks the three package classes that step
    ships (`ReplayCapableSearch`, `SearchFeedbackSnapshotResultFormatterPlugin`,
    `ViewSearchFeedbackTicketReplayPermissionPlugin`) load, and — the one project-level piece it CAN see
    from Zed — that `src/Pyz/Client/SearchElasticsearch/SearchElasticsearchFactory.php` exists and actually
    wraps `Search` in `ReplayCapableSearch`. Since the whole step is optional, a missing override is a
    **warning, not a failure**: skip it if you intentionally don't use frozen replay. The other two step-11
    registrations (the Client `CATALOG_SEARCH_RESULT_FORMATTER_PLUGINS` entry and both Permission
    DependencyProvider entries) are DI wiring this command cannot introspect any more than it can any other
    DependencyProvider registration in this file; the Yves `EventDispatcherDependencyProvider` entry for
    that same step is covered by the Yves counterpart below instead.

    Three more checks, added after real bugs surfaced during this feature's first live end-to-end
    verification:
    - **Snapshot column types.** A real DB round trip confirming `raw_response`/`query_dsl`/
      `request_parameters`/`term_vector_snapshot` on `spy_search_feedback_ticket_srp_snapshot` are actually
      `LONGTEXT`, not plain `TEXT`. Catches a project that installed this package from before the
      `LONGVARCHAR`→`CLOB` schema fix and never re-migrated — a real captured Elasticsearch response
      routinely exceeds `TEXT`'s 64KB cap, and the truncation only surfaces as a 500 on ticket submission,
      not at install time. Warning, not a failure — same "optional feature" posture as the rest of step 11.
    - **Permission sync.** Confirms `SubmitSearchFeedbackTicketPermissionPlugin` and
      `ViewSearchFeedbackTicketReplayPermissionPlugin` are actually synced into `spy_permission` (a real DB
      lookup, not just class-loadable). A permission plugin being registered in
      `PermissionDependencyProvider` does not mean Spryker knows about it — that needs a one-time
      `/permission/index/sync` visit in Zed (see step 11 above), and skipping it doesn't just hide the
      grant checkbox, it throws a hard error on the Company Role create/edit page for *every* role, not
      just ones that would hold the permission. Confirmed live.
    - **Search-results template mapping**, on the Yves counterpart below: confirms the project's
      `src/Pyz/Yves/CatalogPage/Theme/default/views/search/search.twig` actually maps
      `searchFeedbackSnapshot: _view.searchFeedbackSnapshot` into `data` — the single most notorious
      silent-failure point in step 11, confirmed live (see step 11 above for the full explanation).

    Register it in `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`:
    ```php
    use SprykerCommunity\Zed\SearchFeedback\Communication\Console\SearchFeedbackCheckInstallationConsole;

        protected function getConsoleCommands(Container $container): array
        {
            return [
                // ... existing commands
                new SearchFeedbackCheckInstallationConsole(),
            ];
        }
    ```

    **Yves-side counterpart.** `/search-feedback-widget/check-installation` closes exactly the gap the
    console command names above — it runs from inside the real Yves DI container (no new plugin
    registration needed, it uses the same `SearchFeedbackWidgetRouteProviderPlugin` from step 5), and
    checks the three Twig functions and the submit-ticket route from step 5, plus (optional, step 11)
    whether `SearchFeedbackReplayContextEventDispatcherPlugin` is registered as a `KernelEvents::REQUEST`
    listener — miss it and a replay link is never gated by the view-replay permission at the Yves layer
    (the Zed gateway still re-checks authorization independently, so this is a UX gap, not a security hole).
    It also checks whether `ViewSearchFeedbackTicketReplayPermissionPlugin` is registered on the Client, and
    whether the optional search-ranking specificity integration is wired up and enabled. And it checks
    whether the project's search-results template actually maps `searchFeedbackSnapshot` into `data` (see
    step 11 above) — the single most notorious silent-failure point in the whole frozen-replay feature,
    confirmed live: without it, the formatter still captures correctly every time, but the ticket form's
    hidden `snapshotToken` field silently stays empty and no ticket ever gets a frozen-replay snapshot.
    It is complementary, not a replacement: it does not re-check the core namespace, plugin class
    loadability, or the ticket table — run the console command for those.

    Reachable only when BOTH hold:
    - The route exists at all — governed by
      `SprykerCommunity\Shared\SearchFeedback\SearchFeedbackConstants::IS_CHECK_INSTALLATION_PAGE_ENABLED`,
      which **defaults to disabled**, same posture and same rationale as the identical flag on the
      sibling `search-debug` package. **Enable it in your development-tier config**:
      ```php
      $config[SearchFeedbackConstants::IS_CHECK_INSTALLATION_PAGE_ENABLED] = true;
      ```
    - The visiting customer holds the `SubmitSearchFeedbackTicketPermissionPlugin` permission — checked
      wherever the flag above leaves the route enabled. Missing the permission there renders a dedicated
      explanation with the exact remedy (grant the permission, per step 4) at HTTP 403, rather than a bare
      access-denied response.

## Glue API

`spryker-community/search-feedback` exposes one `api-platform`-era storefront REST resource,
`search-feedback-tickets` — a POST-only endpoint for filing a new SRP feedback ticket. This project's
Glue layer runs on `spryker/api-platform` (schema-driven: `resources/api/storefront/*.resource.yml` +
`.validation.yml`, autowired `Provider`/`Processor` classes, generated `#[ApiResource]` PHP), not the
legacy `ResourceRoutePluginInterface`/`@Glue(...)` convention. Nothing beyond declaring the schema is
needed in a host shop — it is discovered automatically as long as the shop's
`config/Glue/packages/spryker_api_platform.php` includes `vendor/spryker-community` in
`sourceDirectories()` (already the case for any shop that installs community packages under that vendor
namespace).

**If your shop installs `spryker-community/*` packages via composer path repositories** (symlinked into
`vendor/spryker-community/*`, rather than a real `composer require` install): a plain `sourceDirectories`
entry is not actually sufficient on its own — `spryker/api-platform`'s schema finder does not follow
symlinked directories, so this package's schema is silently invisible to `api:generate` despite the
correct config. See
[spryker-community/search-debug's own README](https://github.com/spryker-shop/search-debug#glue-api),
"Glue API" section, for the fix (a small `SchemaFinder`/`ValidationSchemaFinder` override).

### `POST /search-feedback-tickets`

Requires a bearer token for an authenticated customer (`securityBearerAuthRequired: true`,
`security: "is_granted('ROLE_CUSTOMER')"`). `customerReference`/`storeName`/`localeName` are always
resolved server-side from the authenticated session/store context — never taken from the request body.

Real authorization (`SubmitSearchFeedbackTicketPermissionPlugin`) is re-checked, and enforced, entirely
server-side in Zed's `GatewayController` — exactly the same posture the Yves `SubmitTicketController`
already relies on. The Glue processor performs the *same* permission check inline via
`PermissionClientInterface::can()` before calling the Client, purely as a UX-level fast-fail (a 422 with
a specific message beats a round-trip that Zed would reject anyway); it is not a security boundary and
cannot be trusted as one.

Request body (JSON:API `attributes`):

| Field        | Type    | Required | Notes                                                  |
|--------------|---------|----------|---------------------------------------------------------|
| `topic`      | string  | yes      | Short subject line.                                     |
| `body`       | string  | yes      | The customer's message.                                 |
| `searchTerm` | string  | no       | The search term the ticket is filed against.             |
| `filters`    | array   | no       | Active facet/filter state, e.g. `{"category": ["123"]}`. |
| `pageNumber` | integer | no       | Result page the ticket was filed from.                  |
| `skuList`    | array   | no       | SKUs shown on that page.                                 |

Response, on success (`201`): the same fields echoed back, plus `id` (the created ticket's id, as a
string), `status`, and `createdAt`. `body` is echoed from the request — `SearchFeedbackTicketTransfer`
has no top-level `body` field; the persisted message text lives at `messages[0].body` in the ticket's
thread, which this endpoint does not otherwise expose.

Errors: `422` on validation failure, on "not logged in," on "not authorized," or on any
`isSuccess: false` response from `SearchFeedbackClient::submitTicket()` (its `errorMessage` becomes the
JSON:API error detail).

After changing either `.resource.yml` file, regenerate with `vendor/bin/glue api:generate` from the
host shop root.

## Limitations

- **No notification when a ticket is answered.** There is no email/mail integration anywhere in this
  package — a Zed admin's reply lands in the database and nowhere else. Combined with the Yves side being
  write-only (see above), a customer who filed a ticket has no way to ever learn it was answered unless
  told through some other channel. Deliberate scope: adding notifications means picking a channel
  (email? a storefront inbox widget, which would also mean building the read-back path this package
  intentionally doesn't have?) that's a real product decision, not a default this package should assume.
- **No per-submitter scoping in Zed.** Any Zed admin with access to the module sees every ticket from
  every customer — Zed users and Yves customers are separate identity systems with no built-in link, so
  there's no natural "your tickets" boundary to enforce even if it were wanted. Access control here is
  role-level (ticket worker vs. feedback admin, via the two separately-restrictable controller actions),
  not row-level.
- **The same is true of a replay, on the storefront side.** `ViewSearchFeedbackTicketReplayPermissionPlugin`
  gates "can this customer replay a ticket's frozen SRP at all," not "does this specific ticket belong to
  them or their company" — a customer holding the permission can replay any ticket by id, including another
  customer's. Deliberate, confirmed choice, not an oversight: same posture as the Zed-side point above,
  extended to a Yves-granted permission instead of a Zed ACL role. Grant
  `ViewSearchFeedbackTicketReplayPermissionPlugin` accordingly — treat it as "can see any customer's search
  context," not as a personal, self-scoped permission.
- **One flat conversation thread per ticket, no internal/private notes.** Every message on a ticket —
  customer or Zed admin — is visible to any Zed admin who can view the ticket. There's no way for one Zed
  admin to leave a note for another without the customer's original message context, since there's no
  customer-facing view to accidentally leak an internal note into anyway; the constraint here is purely
  "everyone with access sees everything," not a security boundary between Zed users.
- **A frozen snapshot can go stale relative to today's product/index data.** The captured `_source` is
  exactly what Elasticsearch returned at ticket-filing time; if a field gets renamed or restructured by a
  later reindex, replaying an old ticket runs that old-shaped data through today's formatters, which can
  silently show a missing badge/facet rather than error. Accepted trade-off, not a bug — the alternative
  (migrating every stored snapshot forward on every reindex) is far more machinery than this feature
  warrants.
- **A pending snapshot can be silently evicted before a ticket is submitted.**
  `SearchFeedbackSnapshotContext` stages a captured snapshot in session storage, capped at 5 pending
  entries (FIFO eviction) to keep a long browsing session's storage bounded. Only a short-lived, random
  token — never the captured response/query/termvector data itself — is embedded as a hidden field in
  the ticket form; the browser never sees the actual snapshot, so it cannot be forged. Five or more
  searches — including opening "View SRP" replays, which are searches too — between seeing the results
  you want to complain about and clicking Submit will silently evict the session-side snapshot that
  token points to; the ticket still submits, just without a frozen replay (`hasTermVectorSnapshot:
  false`), with nothing telling the customer or the Zed admin that happened.
- **The stored `queryDsl` field can go stale for a different reason.** It's captured for informational
  display only, never replayed — `Spryker\Client\SearchElasticsearch\Search\Search::executeQuery()` never
  passes Elastica's `$options` through, so anything out-of-band a future ranking strategy might add
  (`search_pipeline`, a neural rerank pass) would be invisible to it. Not a concern for replay itself, since
  replay only ever uses the raw response.

## Testing and CI

### Automated checks

`.github/workflows/ci.yml` runs on every push and pull request:

| check | what it protects |
|---|---|
| `composer validate` | the manifest stays well-formed |
| `phpcs` (PHP 8.3, 8.4) | coding standard, via this package's own `phpcs.xml` |
| `composer check-floors` (PHP 8.3, 8.4) | the declared dependency floors are real |
| `rector` dry-run (PHP 8.3, 8.4) | no unapplied Rector rule set drifts in |
| `phpmd` (`phpmd.xml` + `phpmd-public-methods.xml`) | cyclomatic/NPath complexity, method/class length stay reasonable — run as two separate invocations because PHPMD merges every loaded ruleset's `exclude-pattern` into one global file list per run, and only the public-method-count rule should skip Facades/Factories |
| `phpstan` (PHP 8.3, 8.4) | static analysis, level 8, standalone CI variant — see "Static analysis" below |
| `portable tests` (PHP 8.3, 8.4) | this package's own `@group Portable` test subset actually passes — see "Test suite" below |

Same `check-floors` rationale as the sibling `search-debug`/`search-ranking` packages: this package's
`require` constraints are a promise about which Spryker versions an adopter may install, which a full demo
shop's dependency tree cannot itself verify. `composer check-floors` resolves every constraint to its
oldest allowed version and asserts every vendor symbol `src/` references still exists there.

### Test suite

Every test class carries a portability `@group`, so `codecept run -g <tag>` tells you what a given test
actually needs:

| tag | needs | where it runs |
|---|---|---|
| `Portable` | nothing beyond `Generated\Shared\Transfer\*` | standalone — CI runs exactly this, see below |
| `NeedsDatabase` | a real Propel connection | host shop only |
| `NeedsProject` | Codeception's project-only actor/module stack, or this package's own installation diagnostics — see their own docblocks | host shop only |

This package never touches Elasticsearch/OpenSearch at all, so unlike its sibling packages there is no
`NeedsSearch` tag here.

`Portable` tests run standalone in CI on every push, via `tests/codeception.portable.yml` +
`tests/_ci-standalone/` — no host shop, no live database. The recipe: a direct `TransferBusinessFactory`
call generates `Generated\Shared\Transfer\*` into `src/Generated/` (gitignored, exactly like a real project
already gitignores its own — regenerated every run), bypassing the full Zed Console/Kernel bootstrap and
Locator entirely. Run it yourself the same way CI does:

```bash
composer install
php tests/_ci-standalone/generate-transfers.php
vendor/bin/codecept run -c tests/codeception.portable.yml -g Portable
```

The rest of the suite — `NeedsDatabase`/`NeedsProject` — ships under `tests/SprykerCommunityTest/`, one
per layer, and runs **inside a host shop** (they use the host's test bootstrap and, for the Zed suites, a
live Propel/MySQL connection):

```bash
vendor/bin/codecept build -c vendor/spryker-community/search-feedback/tests/SprykerCommunityTest/Client/SearchFeedback
vendor/bin/codecept run   -c vendor/spryker-community/search-feedback/tests/SprykerCommunityTest/Client/SearchFeedback
vendor/bin/codecept run   -c vendor/spryker-community/search-feedback/tests/SprykerCommunityTest/Zed/SearchFeedback
vendor/bin/codecept run   -c vendor/spryker-community/search-feedback/tests/SprykerCommunityTest/Zed/SearchFeedbackGui
vendor/bin/codecept run   -c vendor/spryker-community/search-feedback/tests/SprykerCommunityTest/Yves/SearchFeedbackWidget
```

106 tests in this table, plus 28 more in the two browser Presentation suites below (134 total), all green:

| layer | tests | notable coverage |
|---|---|---|
| Client | 19 | `SearchFeedbackClient`, `SearchFeedbackFactory`, `SearchFeedbackStub`, `SearchFeedbackConfig`, both permission plugins — 100% methods; plus `ReplayCapableSearch`'s fall-through/replay decision tree and `SearchFeedbackSnapshotContext`'s capture/consume/eviction behavior (frozen replay, see [What it does](#what-it-does)) |
| Zed (`SearchFeedback`) | 47 | `SearchFeedbackFacade` 100% (6/6), `TicketManager` 100%, `SearchFeedbackEntityManager`/`Repository`/`Mapper` 100%, `CompanyUserPermissionAuthorizer` 100%, `GatewayController` 100%, `SearchFeedbackCheckInstallationConsole` 100% (every check's pass/fail branch, via a mocked Facade + `CommandTester`) |
| Zed (`SearchFeedbackGui`) | 25 | `ReplyForm` validation (via a real Symfony `FormFactory`), `SearchFeedbackGuiCommunicationFactory` DI wiring (all 7 `get*()`/`create*()` methods), `TicketTable::configure()`/`resolveCustomerEmail()`, `DetailController::resolveCustomerEmail()`/`buildSearchResultsPageUrl()` (both dynamic-store-mode branches, against this shop's real config), `IndexController::resolveStoreName()`/`resolveLocaleName()` |
| Yves (`SearchFeedbackWidget`) | 15 | `SearchFeedbackWidgetFactory` DI wiring, `CheckInstallationController` 100% (permission gate, both Twig-function/route check branches, against a hand-built `ContainerInterface` fixture — no real app boot needed) |

The Zed suite's `GatewayControllerTest` and `SearchFeedbackFacadeTest` are real database integration
tests — no mocked Propel query builder — covering the full submit → reply → status-change →
list/find round trip through the actual Locator-resolved Facade, the same path `DetailController` and
`IndexController` drive in production. `CompanyUserPermissionAuthorizerTest` and `GatewayControllerTest`
together prove the authorization gate actually blocks unauthorized writes (persists nothing), not just
that it decorates the response — mirroring the equivalent tests in the sibling
`search-ranking-optimizer` package, which this module's own `CompanyUserPermissionAuthorizer` is a direct,
deliberate copy of.

### Known coverage gaps

Several classes are **not** exercised beyond a DI-wiring smoke test, and this is a structural limitation of
testing Communication/Yves-layer classes outside a live HTTP request — not an oversight:

- **`SearchFeedbackGuiCommunicationFactory::createReplyForm()`**. It resolves the real Zed Silex
  `form.factory` application service, which only exists once the full Zed app is bootstrapped — confirmed
  empirically (`Call to a member function create() on null` under Codeception's `Environment` helper
  alone). The `ReplyForm` type it builds has full, dedicated coverage in `ReplyFormTest` via a real,
  standalone Symfony `FormFactory` instead.
- **`TicketTable::render()`/`fetchData()`/`formatStatus()`/`prepareData()`**. These resolve the request and
  Twig environment from the Zed application container the same way `getFormFactory()` does — same
  limitation, same reason no sibling package (`search-debug`, `search-ranking`, `search-ranking-optimizer`)
  tests a `Table` class's full render path either. `configure()` and `resolveCustomerEmail()` (the rest of
  its real logic — header/sortable/searchable config, the store/locale URL-param baking, the customer-email
  N+1 lookup and its not-found fallback) have full, dedicated coverage instead, driven directly via
  Reflection (see `TicketTableTest`). The Twig-dependent remainder is verified live via the Presentation
  suite's `TicketGridAndDetailCest`/`StoreLocaleFilterCest`: status badge CSS class mapping, the per-row
  "View" action link, and the Store/Locale filter dropdowns all render and round-trip correctly.
- **`DetailController::indexAction()`/`IndexController::indexAction()`/`tableAction()`/
  `changeStatusAction()`**. Same limitation for the same reason — they resolve `createReplyForm()`/
  `createTicketTable()->render()`/`fetchData()`. Their non-framework-coupled logic
  (`resolveCustomerEmail()`, `buildSearchResultsPageUrl()`, `resolveStoreName()`, `resolveLocaleName()`) is
  unit tested directly instead (see `DetailControllerTest`/`IndexControllerTest`); the full action methods
  are covered end-to-end by the Presentation suite.
- **`SubmitTicketController`** (Yves). Reaches through `PermissionAwareTrait::can()` (Spryker's global
  `Locator` singleton, no constructor seam to substitute a fake permission client) and the flash-message
  helpers on `AbstractController`, both of which need a bootstrapped Yves Silex app — the exact same
  documented limitation the sibling `search-debug` package accepts for its own
  `SearchDebugContextEventDispatcherPlugin::handleRequest()` permission-granted branch. The controller's
  own request-parsing helper (`buildRedirectParameters()`) and its collaborators (`SearchFeedbackClient`,
  `SearchFeedbackWidgetFactory`) are covered independently.
- **Frozen-replay capture/delivery path**: `SearchFeedbackSnapshotResultFormatterPlugin` (needs a real
  `Elastica\ResultSet` from a live search to exercise meaningfully — `ReplayCapableSearch`, the class that
  actually *consumes* a captured snapshot, has full coverage instead, see the table above),
  `SearchFeedbackReplayContextEventDispatcherPlugin` (same `PermissionAwareTrait`/Silex-app limitation as
  `SubmitTicketController` above), and `GatewayController::getTicketSrpSnapshotAction()` /
  `SearchFeedbackEntityManager::createTicket()`'s snapshot-persistence branch (would need the same
  real-database integration style `GatewayControllerTest`/`SearchFeedbackFacadeTest` already use for the
  rest of the ticket lifecycle — not yet added). Verified manually end-to-end in a live shop instead (real
  Propel migration applied, `phpstan`/`phpcs` clean against every new file) — see the package's PR for the
  verification log.

### Static analysis

Static analysis (`phpstan`, level 8) runs in two variants:

- **`composer phpstan-ci`** (config [`phpstan.ci.neon`](phpstan.ci.neon)) — what CI runs on every push,
  standalone. Same transfer-generation recipe as the `Portable` test subset above, and treats two
  categories of class as out of scope rather than faking them: Propel's generated `Orm\Zed\*\Persistence\*`
  entity/query/map classes (need a real schema + database, via `propel:model:build`) and the aggregated
  `Generated\{Zed,Yves,Client,Service}\Ide\AutoCompletion` stub (an aggregate across every module in a real
  project's full dependency graph, via `console dev:ide-auto-completion:generate`). Both gaps are the same
  shape as the sibling packages' checked-in `PageIndexMap.php` fixture — a single package can't reproduce a
  project-wide generator's output standalone.
- **`composer phpstan`** (config [`phpstan.neon`](phpstan.neon)) — the full check, run from a host shop,
  where those generated classes are real. This is the one that actually type-checks persistence-layer and
  DI-wiring code against their real generated types, so it stays the authoritative check for adopters even
  though CI can't run it:

```bash
vendor/bin/console dev:ide-auto-completion:generate
vendor/bin/phpstan clear-result-cache -c vendor/spryker-community/search-feedback/phpstan.neon
vendor/bin/phpstan analyse -c vendor/spryker-community/search-feedback/phpstan.neon vendor/spryker-community/search-feedback/src
```

### Browser (Presentation) suite

> **This suite is a development tool for this package's own reference demoshop — it is not something
> to install or run against YOUR shop.** It logs in as a real Zed user and as
> `search-admin@test-company.example` (Yves, the one account this demoshop's fixtures grant
> `SubmitSearchFeedbackTicketPermissionPlugin` to), submits and replies to real tickets against this
> demoshop's seeded catalog and store/locale scope. Point it at a different shop and most of it will
> simply fail on missing data, not on a real defect. It exists to catch UI regressions while developing
> this package, not as something adopters are expected to run.

**Reproducing the fixture on a fresh clone of this demoshop.** `spencor.hopkin@acme.com`
(`customer_reference` `DE--1`) is already a base-fixture member of the `test-company` company with no
company-role assignment — that's the negative-test account, nothing to add. The permitted account
(`search-admin@test-company.example`) is not a base fixture; add it to
`data/import/common/common/`:

- `customer.csv`: `SearchAdmin--1,en_US,,search-admin@test-company.example,Mr,Search,Admin,,Male,,$2y$12$CUw8PyVm4isuM.ugzQhZ0.os.n1nlGJOA61SEd7cgjXivzt5LqJ2.,2026-08-10`
  (that hash is `change123`, the password the Yves Tester expects)
- `company_user.csv`: `SearchAdmin--1,SearchAdmin--1,test-company,true`
- `company_business_unit_user.csv`: `SearchAdmin--1,test-business-unit-1`
- `company_user_role.csv`: `test-company_Admin,SearchAdmin--1`
- `company_role_permission.csv`: both `test-company_Admin,SubmitSearchFeedbackTicketPermissionPlugin,`
  and `test-company_Admin,ViewSearchFeedbackTicketReplayPermissionPlugin,`

Then re-import: `vendor/bin/console data:import customer company-user company-business-unit-user
company-user-role company-role-permission`. The Zed suite's login (`amZed()`) uses this demoshop's
standard backoffice test-authentication helper and needs no extra fixture.

Two suites, split by layer:

- `tests/SprykerCommunityTest/Zed/SearchFeedbackGuiPresentation/` — the ticket grid, detail page (context
  table + conversation thread), reply-and-auto-transition rules (a reply moves an Open ticket to Answered,
  never moves an Answered/Closed one), manual status changes in either direction (self-contained: restores
  whatever status it found), reply-body escaping (a `<script>`/`&`/`<b>` payload asserted to render as
  literal text, never executes), edge cases (unknown status value, nonexistent ticket id on both the
  change-status and detail routes — all redirect gracefully, never crash), the Store/Locale filter
  dropdowns (selecting a store reloads the grid with the right query string and shows the selection back as
  `selected`; the default "all" option applies no filter), and a plain-catalog-search regression check
  confirming a logged-out guest sees none of this package's or its siblings' UI.
- `tests/SprykerCommunityTest/Yves/SearchFeedbackWidgetPresentation/` — the SRP ticket form: a real
  submission that redirects back with the success flash message, client-side rejection of a blank body,
  and the permission gate (anonymous guest, logged-in customer without the role, and the permitted
  customer as the positive control).

```bash
vendor/bin/codecept build -c packages/spryker-community/search-feedback/tests/SprykerCommunityTest/Zed/SearchFeedbackGuiPresentation
vendor/bin/codecept run   -c packages/spryker-community/search-feedback/tests/SprykerCommunityTest/Zed/SearchFeedbackGuiPresentation
vendor/bin/codecept build -c packages/spryker-community/search-feedback/tests/SprykerCommunityTest/Yves/SearchFeedbackWidgetPresentation
vendor/bin/codecept run   -c packages/spryker-community/search-feedback/tests/SprykerCommunityTest/Yves/SearchFeedbackWidgetPresentation
```

Like the rest of the test suite, neither is part of CI — both need a real running shop plus the Selenium/
chromedriver service already provisioned in this demoshop's `docker-compose.yml`.

## License

MIT — see [LICENSE](LICENSE).
