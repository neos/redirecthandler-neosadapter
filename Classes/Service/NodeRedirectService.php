<?php
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

use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\Flow\Utility\Environment;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Request;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Flow\Mvc\Routing\RouterCachingService;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Neos\Routing\Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;

/**
 * Service that creates redirects for moved / deleted nodes.
 *
 * Note: This is usually invoked by a signal emitted by Workspace::publishNode()
 *
 * @Flow\Scope("singleton")
 */
class NodeRedirectService implements NodeRedirectServiceInterface
{
    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @Flow\Inject
     * @var RedirectStorageInterface
     */
    protected $redirectStorage;

    /**
     * @Flow\Inject
     * @var RouterCachingService
     */
    protected $routerCachingService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\InjectConfiguration(path="statusCode", package="Neos.RedirectHandler")
     * @var array
     */
    protected $defaultStatusCode;

    /**
     * @Flow\InjectConfiguration(path="enableRemovedNodeRedirect", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $enableRemovedNodeRedirect;

    /**
     * @Flow\Inject
     * @var Environment
     */
    protected $environment;

    /**
     * {@inheritdoc}
     */
    public function createRedirectsForPublishedNode(NodeInterface $node, Workspace $targetWorkspace)
    {
        try {
            $this->executeRedirectsForPublishedNode($node, $targetWorkspace);
        } catch (\Exception $exception) {
            $this->systemLogger->log(sprintf('Can not create redirect for the node = %s in workspace = %s. See original exception: "%s"', $node->getContextPath(), $targetWorkspace->getName(), $exception->getMessage()), LOG_WARNING);
        }
    }

    /**
     * Creates a redirect for the node if it is a 'Neos.Neos:Document' node and its URI has changed
     *
     * @param NodeInterface $publishedNode The node that is about to be published
     * @param Workspace $targetWorkspace
     * @return void
     * @throws Exception
     * @throws \Neos\Eel\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function executeRedirectsForPublishedNode(NodeInterface $publishedNode, Workspace $targetWorkspace)
    {
        $nodeType = $publishedNode->getNodeType();
        if ($targetWorkspace->getName() !== 'live' || !$nodeType->isOfType('Neos.Neos:Document')) {
            return;
        }

        $liveContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'dimensions' => $publishedNode->getContext()->getDimensions()
        ]);

        $liveNode = $liveContext->getNodeByIdentifier($publishedNode->getIdentifier());
        if ($liveNode === null) {
            // The page has been added
            return;
        }

        $liveNodeUriPath = $this->buildUriPathForNodeContextPath($liveNode);
        if ($liveNodeUriPath === null) {
            throw new Exception('The target URI path of the node could not be resolved', 1451945358);
        }

        $hosts = $this->getHostnames($publishedNode->getContext());

        // The page has been removed
        if ($publishedNode->isRemoved()) {
            // By default the redirect handling for removed nodes is activated.
            // If it is deactivated in your settings you will be able to handle the redirects on your own.
            // For example redirect to dedicated landing pages for deleted campaign NodeTypes
            if ($this->enableRemovedNodeRedirect) {
                $this->flushRoutingCacheForNode($liveNode);
                $statusCode = (integer)$this->defaultStatusCode['gone'];
                $this->redirectStorage->addRedirect($liveNodeUriPath, '', $statusCode, $hosts);
            }

            return;
        }

        // We need to reset the internal node cache to get a node with new path in the same request
        $this->nodeFactory->reset();

        // compare the "old" node URI to the new one
        $publishedNodeUriPath = $this->buildUriPathForNodeContextPath($publishedNode);
        // use the same regexp than the ContentContextBar Ember View
        $publishedNodeUriPath = preg_replace('/@[A-Za-z0-9;&,\-_=]+/', '', $publishedNodeUriPath);
        if ($publishedNodeUriPath === null || $publishedNodeUriPath === $liveNodeUriPath) {
            // The page node path has not been changed
            return;
        }

        $this->flushRoutingCacheForNode($liveNode);
        $statusCode = (integer)$this->defaultStatusCode['redirect'];
        $this->redirectStorage->addRedirect($liveNodeUriPath, $publishedNodeUriPath, $statusCode, $hosts);

        foreach ($publishedNode->getChildNodes('Neos.Neos:Document') as $childNode) {
            $this->executeRedirectsForPublishedNode($childNode, $targetWorkspace);
        }
    }

    /**
     * Collects all hostnames from the Domain entries attached to the current site.
     *
     * @param ContentContext $contentContext
     * @return array
     */
    protected function getHostnames(ContentContext $contentContext)
    {
        $site = $contentContext->getCurrentSite();
        $domains = [];
        if ($site !== null) {
            foreach ($site->getActiveDomains() as $domain) {
                /** @var Domain $domain */
                $domains[] = $domain->getHostname();
            }
        }
        return $domains;
    }

    /**
     * Removes all routing cache entries for the given $nodeData
     *
     * @param NodeInterface $node
     * @return void
     */
    protected function flushRoutingCacheForNode(NodeInterface $node)
    {
        $nodeData = $node->getNodeData();
        $nodeDataIdentifier = $this->persistenceManager->getIdentifierByObject($nodeData);
        if ($nodeDataIdentifier === null) {
            return;
        }
        $this->routerCachingService->flushCachesByTag($nodeDataIdentifier);
    }

    /**
     * Creates a (relative) URI for the given $nodeContextPath removing the "@workspace-name" from the result
     *
     * @param NodeInterface $node
     * @return string the resulting (relative) URI or NULL if no route could be resolved
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function buildUriPathForNodeContextPath(NodeInterface $node)
    {
        try {
            $uriBuilder = $this->getUriBuilder();
            $uri = $uriBuilder->uriFor('show', ['node' => $node], 'Frontend\\Node', 'Neos.Neos');

            $uriPathPrefix = $this->environment->isRewriteEnabled() ? '' : 'index.php/';
            $uriPathPrefix = $uriBuilder->getRequest()->getHttpRequest()->getScriptRequestPath() . $uriPathPrefix;
            if (strpos($uri, $uriPathPrefix) === 0) {
                $uri = substr($uri, strlen($uriPathPrefix));
            }

            return $uri;
        } catch (NoMatchingRouteException $exception) {
            return null;
        }
    }

    /**
     * Creates an UriBuilder instance for the current request
     *
     * @return UriBuilder
     */
    protected function getUriBuilder()
    {
        if ($this->uriBuilder === null) {
            $httpRequest = Request::createFromEnvironment();
            $actionRequest = new ActionRequest($httpRequest);
            $this->uriBuilder = new UriBuilder();
            $this->uriBuilder
                ->setRequest($actionRequest);
            $this->uriBuilder
                ->setFormat('html')
                ->setCreateAbsoluteUri(false);
        }
        return $this->uriBuilder;
    }
}
