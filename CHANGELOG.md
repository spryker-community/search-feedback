# Changelog

All notable changes to this package are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Each version below also has a [GitHub release](../../releases) with the fuller write-up.

## [Unreleased]

### Documented
- Installation is now a plain `composer require` from Packagist (`spryker-community` namespace,
  auto-updated); the VCS `repositories` entry is no longer needed.
- Replaced the "not an official Spryker project" note with the community org's own wording: maintained
  by the community, not part of the commercial Spryker product, not covered by commercial support.

## [1.5.2] - 2026-08-28

### Changed
- Dropped `spryker-community/search-ranking` from `require-dev` and removed the personal-URL
  `repositories` VCS entry. The `TermVectorSnapshot` provider/restorer seam is owned by this package;
  the standalone test suite never referenced a real search-ranking class. `suggest` still advertises
  the optional integration.

### Added
- `FakeTermVectorSnapshotProviderPlugin` / `FakeTermVectorSnapshotRestorerPlugin` and
  `TermVectorSnapshotStandaloneRoundTripTest` — cover the seam with nothing from search-ranking on the
  classpath.

## [1.5.1] - 2026-08-28

### Added
- `checkGlueApiWiring()` in `SearchFeedbackCheckInstallationConsole` — warns (never fails) when
  `Generated\Api\Storefront\SearchFeedbackTicketsStorefrontResource` has not been generated
  (`glue api:generate storefront` not re-run → `POST /search-feedback-tickets` 404s).

### Documented
- `extra.dependency-pins` note on `symfony/security-guard: 5.4.0-BETA1` (a resolution pin, no `src/`
  usage).
- OpenSearch 3.5 compatibility note.

### Build
- Bump `spryker/code-sniffer` dev dependency 0.17.35 → 0.17.36.

## [1.5.0] - 2026-08-27

### Added
- Glue API (API Platform): `POST /search-feedback-tickets` storefront resource, with a
  Provider/Processor/Mapper trio following core Spryker API Platform module patterns.

### Fixed
- Corrected composer dependency declarations after a requires-vs-usage audit: dropped an unused
  `spryker/catalog` require, made the previously-transitive `ruflin/elastica` explicit, declared
  `spryker/serializer`, tidied `require-dev`.
- Pinned `symfony/security-guard` to the one pre-release that supports Symfony 6.4/7 `security-core`,
  unblocking `spryker/api-platform` resolution.
- Standalone `phpstan-ci` now excludes `Generated\Api\*`.

## [1.4.4] - 2026-08-23

### Changed
- CI: bumped `actions/checkout` v4 → v7.

## [1.4.3] - 2026-08-23

### Fixed
- Require a valid CSRF token for `changeStatusAction()`.

### Added
- `suggest` entry for search-debug (the ticket-replay SRP snapshot reuses its real search route).
- Coverage for `SearchFeedbackClient::getTicketSrpSnapshot()` / `consumeSnapshot()`, `IndexController`'s
  factory-driven actions, and `SearchFeedbackReplayContextEventDispatcherPlugin`'s guards.

## [1.4.2] - 2026-08-20

### Changed
- Restored README screenshots against a fictional "Feldwerk" demo catalog; this package owns the shared
  demo-catalog generator (`fixtures/demo-catalog/build.php`) that search-debug and search-ranking bundle
  copies from.

### Fixed
- Two installability gaps: without `product_abstract_store` / `product_abstract_approval_status` rows a
  brand-new product is silently invisible to catalog search.
- A Rector finding and a phpcs warning.

## [1.4.1] - 2026-08-19

### Changed
- README states plainly that `spryker-community/*` is an independent, community-built namespace with no
  official Spryker affiliation.
- Updated the Packagist-namespace note.
- CI: added an `xmllint` job for ruleset XML; pinned dev tooling to stop CI drift.

## [1.4.0] - 2026-08-14

### Added
- Frozen replay: a ticket optionally captures the exact Elasticsearch response, query DSL, and (when
  `spryker-community/search-ranking` is installed) the specificity-weighting result that scored it, so
  "View SRP" later replays precisely what the customer saw at filing time. Fully optional and additive.
- Demo-fixture apply script for bootstrapping the permission/glossary data this feature needs.

### Documented
- Pending snapshots are staged in session storage with a 5-entry FIFO cap; 5+ searches between viewing
  results and submitting evict the pending snapshot (ticket still submits, without a frozen replay).

## [1.3.2] - 2026-08-13

### Changed
- CI: `phpstan` level 8 gated via a standalone `composer phpstan-ci` variant; now a required check.

### Fixed
- Declared `spryker/permission` in `require` (previously only satisfied transitively by host shops).

## [1.3.1] - 2026-08-13

### Changed
- CI: the Codeception "Portable" subset now runs standalone via a `tests/_ci-standalone` bootstrap.

## [1.3.0] - 2026-08-12

### Added
- Back-office ACL reachability check in `search-feedback:check-installation` — warns (never fails) when
  restricted Zed roles exist but none has an ACL rule for `search-feedback-gui`. Upgrading can turn a
  previously-green check red where navigation/ACL was already incompletely registered.

## [1.2.1] - 2026-08-12

### Fixed
- Five `|trans` keys the Zed GUI renders (store/locale filter labels, `View SRP`) were missing from
  `data/translation/Zed/` — they rendered untranslated in a non-English Zed.

### Added
- `search-feedback:check-installation` now scans the package's own `|trans` keys against the shipped
  catalog (one-directional — never reports a key as unused).

## [1.2.0] - 2026-08-12

### Added
- `search-feedback:check-installation` now verifies the Zed `navigation.xml` entry against the built
  navigation cache. This can turn a previously-green check red where navigation was already
  incompletely registered.
- `CheckInstallationCest` for the Yves check page.

## [1.1.0] - 2026-08-11

### Added
- Optional Store/Locale filter on the Zed ticket grid (defaults to "all").
- Ticket detail page shows the customer's resolved email; "View search results" link reconstructs the
  exact SRP a ticket was filed from.

### Fixed
- Declared the previously-missing `spryker/locale` in `require`.
- phpstan level 8: `SubmitTicketController::buildRedirectParameters()` docblock return type.
- Missing `FACADE_CUSTOMER` stub in `SearchFeedbackGuiCommunicationFactoryTest`.
- A WebDriver click-interception flake; wasted per-message customer-email lookups on the reply-success
  redirect path.

## [1.0.1] - 2026-08-10

### Documented
- Dynamic store mode routing workaround for the widget's `submit-ticket` route on non-default stores.
- Zed nav placement gotchas (`BREADCRUMB_MERGE_STRATEGY`, the `array_merge_recursive` crash) and the
  Backoffice router cache rebuild.
- Added the storefront ticket-form screenshot; refreshed both Zed screenshots.

## [1.0.0] - 2026-08-06

### Added
- Initial release: SRP feedback ticketing — an authorized storefront admin files a free-text ticket
  about a set of search results (query, active filters, page, SKUs shown); Zed back-office admins hold a
  conversation about it. Write-only from Yves. `search-feedback:check-installation` (Zed console) and
  `/search-feedback-widget/check-installation` (Yves page) diagnostics.

[Unreleased]: ../../compare/v1.5.2...HEAD
[1.5.2]: ../../releases/tag/v1.5.2
[1.5.1]: ../../releases/tag/v1.5.1
[1.5.0]: ../../releases/tag/v1.5.0
[1.4.4]: ../../releases/tag/v1.4.4
[1.4.3]: ../../releases/tag/v1.4.3
[1.4.2]: ../../releases/tag/v1.4.2
[1.4.1]: ../../releases/tag/v1.4.1
[1.4.0]: ../../releases/tag/v1.4.0
[1.3.2]: ../../releases/tag/v1.3.2
[1.3.1]: ../../releases/tag/v1.3.1
[1.3.0]: ../../releases/tag/v1.3.0
[1.2.1]: ../../releases/tag/v1.2.1
[1.2.0]: ../../releases/tag/v1.2.0
[1.1.0]: ../../releases/tag/v1.1.0
[1.0.1]: ../../releases/tag/v1.0.1
[1.0.0]: ../../releases/tag/v1.0.0
