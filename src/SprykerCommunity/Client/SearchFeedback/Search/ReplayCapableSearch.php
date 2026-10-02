<?php

/**
 * This file is part of the spryker-community/search-feedback package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchFeedback\Search;

use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet;
use Elastica\ResultSet\DefaultBuilder;
use Generated\Shared\Transfer\SearchFeedbackTicketSrpSnapshotRequestTransfer;
use Spryker\Client\Customer\CustomerClientInterface;
use Spryker\Client\SearchElasticsearch\Search\SearchInterface;
use Spryker\Client\SearchExtension\Dependency\Plugin\QueryInterface as SearchQueryInterface;
use SprykerCommunity\Client\SearchFeedback\SearchFeedbackClientInterface;
use SprykerCommunity\Shared\SearchFeedback\SearchFeedbackConfig;

/**
 * Decorates the real `Search` class (wired in by the host project's own `SearchElasticsearchFactory`
 * override — see the package README, "Installation") so a request carrying
 * `?srpReplayTicket=<id>` (already permission-gated in Yves by
 * `SearchFeedbackReplayContextEventDispatcherPlugin`) replays a ticket's frozen SRP snapshot instead of
 * hitting Elasticsearch, while still running the frozen data through TODAY's live result-formatter stack —
 * template/formatting fixes shipped after the ticket was filed still apply, only the ranking data itself is
 * frozen. Falls through to a live search whenever there's nothing to replay: no ticket id on the request,
 * no logged-in customer, the ticket has no snapshot, or Zed denies the request — never an error, since all
 * of these are ordinary, expected states (a plain search request, a ticket filed before this feature
 * existed, an unauthorized visitor Yves's own gate somehow didn't already stop).
 *
 * Also restores any captured termvector snapshot (see `TermVectorSnapshotRestorerPluginInterface`) before
 * returning — this is the ONLY thing this class does that isn't just "swap the ES call". Confirmed live
 * why it has to: query expansion (search-ranking's own function_score wrapping, which computes THIS
 * request's own "last specificity weighting result") still runs fresh on every request regardless of
 * replay, since only the actual Elasticsearch call gets intercepted here. Without the restore step, a
 * replay's debug overlay would silently show live current values instead of the ones that actually scored
 * the ticket — looking correctly frozen right up until a live setting changes and the same replay is
 * reopened.
 */
class ReplayCapableSearch implements SearchInterface
{
    /**
     * @param \Spryker\Client\SearchElasticsearch\Search\SearchInterface $decoratedSearch
     * @param \SprykerCommunity\Client\SearchFeedback\SearchFeedbackClientInterface $searchFeedbackClient
     * @param \Spryker\Client\Customer\CustomerClientInterface $customerClient
     */
    public function __construct(
        protected SearchInterface $decoratedSearch,
        protected SearchFeedbackClientInterface $searchFeedbackClient,
        protected CustomerClientInterface $customerClient,
    ) {
    }

    /**
     * @param \Spryker\Client\SearchExtension\Dependency\Plugin\QueryInterface $searchQuery
     * @param array<\Spryker\Client\SearchExtension\Dependency\Plugin\ResultFormatterPluginInterface> $resultFormatters
     * @param array<string, mixed> $requestParameters
     *
     * @return \Elastica\ResultSet|array<string, mixed>
     */
    public function search(SearchQueryInterface $searchQuery, array $resultFormatters = [], array $requestParameters = [])
    {
        $resultSet = $this->buildReplayResultSet($requestParameters);

        if ($resultSet === null) {
            return $this->decoratedSearch->search($searchQuery, $resultFormatters, $requestParameters);
        }

        if ($resultFormatters === []) {
            return $resultSet;
        }

        $formattedResult = [];

        foreach ($resultFormatters as $resultFormatter) {
            $formattedResult[$resultFormatter->getName()] = $resultFormatter->formatResult($resultSet, $requestParameters);
        }

        return $formattedResult;
    }

    /**
     * Replay is never applied to multiSearch() — autosuggest/facet-count batch queries are not what a
     * ticket's "View SRP" link ever triggers, so there is nothing meaningful to replay here.
     *
     * @param array<string, \Spryker\Client\SearchExtension\Dependency\Plugin\QueryInterface> $searchQueries
     * @param array<string, array<\Spryker\Client\SearchExtension\Dependency\Plugin\ResultFormatterPluginInterface>> $resultFormattersPerQuery
     * @param array<string, mixed> $requestParameters
     *
     * @return array<string, mixed>
     */
    public function multiSearch(array $searchQueries, array $resultFormattersPerQuery, array $requestParameters = []): array
    {
        return $this->decoratedSearch->multiSearch($searchQueries, $resultFormattersPerQuery, $requestParameters);
    }

    /**
     * @param array<string, mixed> $requestParameters
     */
    protected function buildReplayResultSet(array $requestParameters): ?ResultSet
    {
        $idSearchFeedbackTicket = $requestParameters[SearchFeedbackConfig::REQUEST_PARAM_SRP_REPLAY_TICKET] ?? null;

        if (!is_numeric($idSearchFeedbackTicket)) {
            return null;
        }

        $customerTransfer = $this->customerClient->getCustomer();
        $customerReference = $customerTransfer?->getCustomerReference();

        if ($customerReference === null) {
            return null;
        }

        $requestTransfer = (new SearchFeedbackTicketSrpSnapshotRequestTransfer())
            ->setIdSearchFeedbackTicket((int)$idSearchFeedbackTicket)
            ->setCustomerReference($customerReference);

        $responseTransfer = $this->searchFeedbackClient->getTicketSrpSnapshot($requestTransfer);

        if (!$responseTransfer->getIsFound()) {
            return null;
        }

        $snapshotTransfer = $responseTransfer->getSnapshotOrFail();

        if ($snapshotTransfer->getHasTermVectorSnapshot() && $snapshotTransfer->getTermVectorSnapshot() !== null) {
            $this->searchFeedbackClient->restoreTermVectorSnapshot($snapshotTransfer->getTermVectorSnapshot());
        }

        $response = new Response($snapshotTransfer->getRawResponseOrFail());
        $queryDsl = json_decode($snapshotTransfer->getQueryDslOrFail(), true);
        $query = Query::create(is_array($queryDsl) ? $queryDsl : []);

        return (new DefaultBuilder())->buildResultSet($response, $query);
    }
}
