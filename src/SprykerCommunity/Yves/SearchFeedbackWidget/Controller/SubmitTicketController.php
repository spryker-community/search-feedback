<?php

/**
 * This file is part of the spryker-community/search-feedback package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchFeedbackWidget\Controller;

use Generated\Shared\Transfer\SearchFeedbackTicketRequestTransfer;
use Spryker\Yves\Kernel\Controller\AbstractController;
use Spryker\Yves\Kernel\PermissionAwareTrait;
use SprykerCommunity\Shared\SearchFeedback\Plugin\SubmitSearchFeedbackTicketPermissionPlugin;
use SprykerCommunity\Yves\SearchFeedbackWidget\Plugin\Twig\SearchFeedbackWidgetTwigPlugin;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * @method \SprykerCommunity\Yves\SearchFeedbackWidget\SearchFeedbackWidgetFactory getFactory()
 */
class SubmitTicketController extends AbstractController
{
    use PermissionAwareTrait;

    /**
     * @var string
     */
    protected const PARAM_TOPIC = 'topic';

    /**
     * @var string
     */
    protected const PARAM_BODY = 'body';

    /**
     * @var string
     */
    protected const PARAM_SEARCH_TERM = 'searchTerm';

    /**
     * @var string
     */
    protected const PARAM_PAGE_NUMBER = 'pageNumber';

    /**
     * @var string
     */
    protected const PARAM_SKU_LIST = 'skuList';

    /**
     * @var string
     */
    protected const PARAM_QUERY_STRING = 'queryString';

    /**
     * @var string
     */
    protected const PARAM_SNAPSHOT_TOKEN = 'snapshotToken';

    /**
     * @var string
     */
    protected const PARAM_CSRF_TOKEN = '_csrf_token';

    /**
     * Matches `SprykerShop\Yves\CatalogPage\Plugin\Router\CatalogPageRouteProviderPlugin::ROUTE_NAME_SEARCH`
     * — kept as a local string rather than a hard composer dependency on that package, same standalone
     * posture the rest of this package keeps toward the shop it's installed into.
     *
     * @var string
     */
    protected const ROUTE_NAME_SEARCH_FALLBACK = 'search';

    /**
     * This action isn't bound to a Symfony Form (it's a plain HTML form posted from the SRP widget), so it
     * carries none of the CSRF protection a Form-backed POST gets automatically — same manual-CSRF-token
     * posture `SubmitRelevanceJudgmentController` in search-ranking-optimizer uses for its own AJAX
     * endpoints, adapted here for a classic (non-AJAX) POST.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    protected function isCsrfTokenValid(Request $request): bool
    {
        $token = (string)$request->request->get(static::PARAM_CSRF_TOKEN, '');

        return $this->getFactory()->getCsrfTokenManager()->isTokenValid(
            new CsrfToken(SearchFeedbackWidgetTwigPlugin::CSRF_TOKEN_ID, $token),
        );
    }

    /**
     * UX-level gate only — the real, unbypassable check happens server-side in Zed's GatewayController,
     * which independently re-resolves the customer's permission rather than trusting anything asserted
     * here (see {@see \SprykerCommunity\Zed\SearchFeedback\Communication\Authorization\CompanyUserPermissionAuthorizer}).
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function submitAction(Request $request): RedirectResponse
    {
        $redirectParameters = $this->buildRedirectParameters($request);

        if (!$this->isCsrfTokenValid($request)) {
            $this->addErrorMessage('search_feedback.ticket.error.csrf');

            return $this->redirectResponseInternal(static::ROUTE_NAME_SEARCH_FALLBACK, $redirectParameters);
        }

        if (!$this->can(SubmitSearchFeedbackTicketPermissionPlugin::KEY)) {
            $this->addErrorMessage('search_feedback.ticket.error.not_authorized');

            return $this->redirectResponseInternal(static::ROUTE_NAME_SEARCH_FALLBACK, $redirectParameters);
        }

        $customerTransfer = $this->getFactory()->getCustomerClient()->getCustomer();

        if ($customerTransfer === null || $customerTransfer->getCustomerReference() === null) {
            $this->addErrorMessage('search_feedback.ticket.error.not_logged_in');

            return $this->redirectResponseInternal(static::ROUTE_NAME_SEARCH_FALLBACK, $redirectParameters);
        }

        $skuList = $request->request->all(static::PARAM_SKU_LIST);
        $snapshotToken = (string)$request->request->get(static::PARAM_SNAPSHOT_TOKEN, '');

        $requestTransfer = (new SearchFeedbackTicketRequestTransfer())
            ->setTopic((string)$request->request->get(static::PARAM_TOPIC, ''))
            ->setBody((string)$request->request->get(static::PARAM_BODY, ''))
            ->setSearchTerm((string)$request->request->get(static::PARAM_SEARCH_TERM, ''))
            ->setFilters($redirectParameters)
            ->setPageNumber((int)$request->request->get(static::PARAM_PAGE_NUMBER, 1))
            ->setSkuList(array_values(array_filter(array_map(static fn (mixed $sku): string => (string)$sku, $skuList))))
            ->setStoreName($this->getFactory()->getStoreClient()->getCurrentStore()->getNameOrFail())
            ->setLocaleName($this->getLocale())
            ->setCustomerReference($customerTransfer->getCustomerReference());

        if ($snapshotToken !== '') {
            $requestTransfer->setSnapshot($this->getFactory()->getSearchFeedbackClient()->consumeSnapshot($snapshotToken));
        }

        $responseTransfer = $this->getFactory()->getSearchFeedbackClient()->submitTicket($requestTransfer);

        if ($responseTransfer->getIsSuccess()) {
            $this->addSuccessMessage('search_feedback.ticket.success');
        } else {
            $this->addErrorMessage($responseTransfer->getErrorMessage() ?: 'search_feedback.ticket.error.generic');
        }

        return $this->redirectResponseInternal(static::ROUTE_NAME_SEARCH_FALLBACK, $redirectParameters);
    }

    /**
     * Reconstructs the exact SRP the ticket was filed from (search term + every active facet), from the
     * hidden `queryString` field the form captures via `app.request.queryString` — rather than enumerating
     * individual facet parameter names one by one, which are dynamic per catalog and would silently drop
     * whichever ones this package doesn't know about.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return array<array-key, mixed>
     */
    protected function buildRedirectParameters(Request $request): array
    {
        $queryString = (string)$request->request->get(static::PARAM_QUERY_STRING, '');
        parse_str($queryString, $parameters);

        return $parameters;
    }
}
