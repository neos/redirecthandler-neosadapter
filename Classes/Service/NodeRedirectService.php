<?php
declare(strict_types=1);

namespace Neos\RedirectHandler\NeosAdapter\Service;

/*
 * This file is part of the Neos.RedirectHandler.NeosAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Psr\Log\LoggerInterface;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\Neos\FrontendRouting\NodeUriBuilder;
use Neos\ContentRepository\Core\Factory\ContentRepositoryId;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\FrontendRouting\Projection\DocumentNodeInfo;
use Neos\Neos\FrontendRouting\NodeAddress;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;

/**
 * Service that creates redirects for moved / deleted nodes.
 *
 * Note: This is usually invoked by a catchup hook. See: Neos\RedirectHandler\NeosAdapter\CatchUpHook\DocumentUriPathProjectionHook
 *
 * @Flow\Scope("singleton")
 */
final class NodeRedirectService
{
    const STATUS_CODE_TYPE_REDIRECT = 'redirect';
    const STATUS_CODE_TYPE_GONE = 'gone';

    private array $affectedNodes = [];
    private array $hostnamesRuntimeCache = [];

    #[Flow\Inject]
    protected ?LoggerInterface $logger = null;

    /**
     * @var array<string, int>
     */
    #[Flow\InjectConfiguration(path: "statusCode", package: "Neos.RedirectHandler")]
    protected array $defaultStatusCode;

    #[Flow\InjectConfiguration(path: "enableAutomaticRedirects", package: "Neos.RedirectHandler.NeosAdapter")]
    protected bool $enableAutomaticRedirects;

    #[Flow\InjectConfiguration(path: "enableRemovedNodeRedirect", package: "Neos.RedirectHandler.NeosAdapter")]
    protected bool $enableRemovedNodeRedirect;

    /**
     * @var array<string, bool>
     */
    #[Flow\InjectConfiguration(path: "restrictByOldUriPrefix", package: "Neos.RedirectHandler.NeosAdapter")]
    protected array $restrictByOldUriPrefix = [];

    /**
     * @var array<string, bool>
     */
    #[Flow\InjectConfiguration(path: "restrictByNodeType", package: "Neos.RedirectHandler.NeosAdapter")]
    protected array $restrictByNodeType = [];

    public function __construct(
        #[Flow\Inject]
        protected RedirectStorageInterface $redirectStorage,
        #[Flow\Inject]
        protected PersistenceManagerInterface $persistenceManager,
        #[Flow\Inject]
        protected ContentRepositoryRegistry $contentRepositoryRegistry,
        #[Flow\Inject]
        protected SiteRepository $siteRepository,
    ) {
    }

    /**
     * Collects affected nodes before they got moved or removed.
     *
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    public function appendAffectedNode(DocumentNodeInfo $nodeInfo, NodeAddress $nodeAddress, ContentRepositoryId $contentRepositoryId): void
    {
        try {
            $this->affectedNodes[$this->createAffectedNodesKey($nodeInfo, $contentRepositoryId)] = [
                'node' => $nodeInfo,
                'url' => $this->getNodeUriBuilder($nodeInfo->getSiteNodeName(), $contentRepositoryId)->uriFor($nodeAddress),
            ];
        } catch (NoMatchingRouteException $exception) {
        }
    }

    /**
     * Creates redirects for given node and uses the collected affected nodes to determine the source of the new redirect target.
     *
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    public function createRedirectForAffectedNode(DocumentNodeInfo $nodeInfo, NodeAddress $nodeAddress, ContentRepositoryId $contentRepositoryId): void
    {
        if (!$this->enableAutomaticRedirects) {
            return;
        }

        $affectedNode = $this->affectedNodes[$this->createAffectedNodesKey($nodeInfo, $contentRepositoryId)] ?? null;
        if ($affectedNode === null) {
            return;
        }
        unset($this->affectedNodes[$this->createAffectedNodesKey($nodeInfo, $contentRepositoryId)]);

        /** @var Uri $oldUri */
        $oldUri = $affectedNode['url'];
        $nodeType = $this->getNodeType($contentRepositoryId, $nodeInfo->getNodeTypeName());

        if ($this->isRestrictedByNodeType($nodeType) || $this->isRestrictedByOldUri($oldUri->getPath())) {
            return;
        }
        try {
            $newUri = $this->getNodeUriBuilder($nodeInfo->getSiteNodeName(), $contentRepositoryId)->uriFor($nodeAddress);
        } catch (NoMatchingRouteException $exception) {
            // We can't build an uri for given node, so we can't create any redirect. E.g.: Node is disabled.
            return;
        }
        $this->createRedirectWithNewTarget($oldUri->getPath(), $newUri->getPath(), $nodeInfo->getSiteNodeName());

        $this->persistenceManager->persistAll();
    }

    /**
     * Creates redirects for given removed node and uses the collected affected nodes to determine the source of the new redirect.
     */
    public function createRedirectForRemovedAffectedNode(DocumentNodeInfo $nodeInfo, ContentRepositoryId $contentRepositoryId): void
    {
        if (!$this->enableAutomaticRedirects) {
            return;
        }

        $affectedNode = $this->affectedNodes[$this->createAffectedNodesKey($nodeInfo, $contentRepositoryId)] ?? null;
        if ($affectedNode === null) {
            return;
        }
        unset($this->affectedNodes[$this->createAffectedNodesKey($nodeInfo, $contentRepositoryId)]);

        /** @var Uri $oldUri */
        $oldUri = $affectedNode['url'];
        $nodeType = $this->getNodeType($contentRepositoryId, $nodeInfo->getNodeTypeName());

        if ($this->isRestrictedByNodeType($nodeType) || $this->isRestrictedByOldUri($oldUri->getPath())) {
            return;
        }

        $this->createRedirectForRemovedTarget($oldUri->getPath(), $nodeInfo->getSiteNodeName());

        $this->persistenceManager->persistAll();
    }

    protected function getNodeType(ContentRepositoryId $contentRepositoryId, NodeTypeName $nodeTypeName): NodeType
    {
        return $this->contentRepositoryRegistry->get($contentRepositoryId)->getNodeTypeManager()->getNodeType($nodeTypeName);
    }

    private function createAffectedNodesKey(DocumentNodeInfo $nodeInfo, ContentRepositoryId $contentRepositoryId): string
    {
        return $contentRepositoryId->value . '#' . $nodeInfo->getNodeAggregateId()->value . '#' . $nodeInfo->getDimensionSpacePointHash();
    }

    protected function getNodeUriBuilder(SiteNodeName $siteNodeName, ContentRepositoryId $contentRepositoryId): NodeUriBuilder
    {
        // Generate a custom request when the current request was triggered from CLI
        $baseUri = 'http://localhost';

        // Prevent `index.php` appearing in generated redirects
        putenv('FLOW_REWRITEURLS=1');

        $httpRequest = new ServerRequest('POST', $baseUri);

        $httpRequest = (SiteDetectionResult::create($siteNodeName, $contentRepositoryId))->storeInRequest($httpRequest);
        $actionRequest = ActionRequest::fromHttpRequest($httpRequest);

        return NodeUriBuilder::fromRequest($actionRequest);
    }

    /**
     * Adds a redirect for given $oldUriPath to $newUriPath for all domains set up for $siteNode
     */
    protected function createRedirectWithNewTarget(string $oldUriPath, string $newUriPath, SiteNodeName $siteNodeName): bool
    {
        if ($oldUriPath === $newUriPath) {
            return false;
        }

        $hosts = $this->getHostnames($siteNodeName);
        $statusCode = $this->defaultStatusCode[self::STATUS_CODE_TYPE_REDIRECT];

        $this->redirectStorage->addRedirect($oldUriPath, $newUriPath, $statusCode, $hosts);

        return true;
    }

    /**
     * Adds a redirect for a removed target if enabled.
     */
    protected function createRedirectForRemovedTarget(string $oldUriPath, SiteNodeName $siteNodeName): bool
    {
        // By default the redirect handling for removed nodes is activated.
        // If it is deactivated in your settings you will be able to handle the redirects on your own.
        // For example redirect to dedicated landing pages for deleted campaign NodeTypes
        if ($this->enableRemovedNodeRedirect) {
            $hosts = $this->getHostnames($siteNodeName);
            $statusCode = $this->defaultStatusCode[self::STATUS_CODE_TYPE_GONE];
            $this->redirectStorage->addRedirect($oldUriPath, '', $statusCode, $hosts);

            return true;
        }

        return false;
    }

    /**
     * Check if the current node type is restricted by NodeType
     */
    protected function isRestrictedByNodeType(NodeType $nodeType): bool
    {
        if (!isset($this->restrictByNodeType)) {
            return false;
        }

        foreach ($this->restrictByNodeType as $disabledNodeType => $status) {
            if ($status !== true) {
                continue;
            }

            if ($nodeType->isOfType($disabledNodeType)) {
                $this->logger?->debug(vsprintf('Redirect skipped based on the current node type (%s) for a node because is of type %s', [
                    $nodeType->name->value,
                    $disabledNodeType
                ]));

                return true;
            }
        }

        return false;
    }

    /**
     * Check if the old URI is restricted by old uri
     */
    protected function isRestrictedByOldUri(string $oldUriPath): bool
    {
        if (!isset($this->restrictByOldUriPrefix)) {
            return false;
        }

        foreach ($this->restrictByOldUriPrefix as $uriPrefix => $status) {
            if ($status !== true) {
                continue;
            }
            $uriPrefix = rtrim($uriPrefix, '/') . '/';
            $oldUriPath = rtrim($oldUriPath, '/') . '/';
            if (mb_strpos($oldUriPath, $uriPrefix) === 0) {
                $this->logger?->debug(vsprintf('Redirect skipped based on the old URI (%s) because prefix matches %s', [
                    $oldUriPath,
                    $uriPrefix
                ]));

                return true;
            }
        }

        return false;
    }

    /**
     * Collects all hostnames from the Domain entries attached to the current site.
     * @return array<string, array<string>>
     */
    protected function getHostnames(SiteNodeName $siteNodeName): array
    {
        if (!isset($this->hostnamesRuntimeCache[$siteNodeName->value])) {
            $site = $this->siteRepository->findOneByNodeName($siteNodeName);

            $domains = [];
            if ($site === null) {
                return $domains;
            }

            foreach ($site->getActiveDomains() as $domain) {
                /** @var Domain $domain */
                $domains[] = $domain->getHostname();
            }

            $this->hostnamesRuntimeCache[$siteNodeName->value] = $domains;
        }

        return $this->hostnamesRuntimeCache[$siteNodeName->value];
    }
}
