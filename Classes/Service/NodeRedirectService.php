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

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that creates redirects for moved / deleted nodes.
 *
 * Note: This is usually invoked by signals.
 *
 * @Flow\Scope("singleton")
 */
class NodeRedirectService
{
    const STATUS_CODE_TYPE_REDIRECT = 'redirect';
    const STATUS_CODE_TYPE_GONE = 'gone';

    /**
     * @Flow\Inject
     * @var RedirectStorageInterface
     */
    protected $redirectStorage;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\InjectConfiguration(path="statusCode", package="Neos.RedirectHandler")
     * @var array
     */
    protected $defaultStatusCode;

    /**
     * @Flow\InjectConfiguration(path="enableAutomaticRedirects", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $enableAutomaticRedirects;

    /**
     * @Flow\InjectConfiguration(path="enableRemovedNodeRedirect", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $enableRemovedNodeRedirect;

    /**
     * @Flow\InjectConfiguration(path="restrictByOldUriPrefix", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $restrictByOldUriPrefix;

    /**
     * @Flow\InjectConfiguration(path="restrictByNodeType", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $restrictByNodeType;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    #[\Neos\Flow\Annotations\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;


    /**
     * @param string $oldUriPath
     * @param string|null $newUriPath
     * @param SiteNodeName $siteNodeName
     * @return void
     */
    public function createRedirect(
        string $oldUriPath,
        ?string $newUriPath,
        SiteNodeName $siteNodeName,
    ): void {

        if (!$this->enableAutomaticRedirects) {
            return;
        }

        // TODO: Restrict by NodeType
        if (/*$this->isRestrictedByNodeType($targetNodeInfo->node)|| */ $this->isRestrictedByOldUri($oldUriPath)) {
            return;
        }
        $oldUriPath = $this->buildUri($oldUriPath);
        if ($newUriPath !== null) {
            $newUriPath = $this->buildUri($newUriPath);
            $this->createRedirectWithNewTarget($oldUriPath, $newUriPath, $siteNodeName);
        } else {
            $this->createRedirectForRemovedTarget($oldUriPath, $siteNodeName);
        }

        $this->persistenceManager->persistAll();
    }

    /**
     * Adds a redirect for given $oldUriPath to $newUriPath for all domains set up for $siteNode
     *
     * @param NodeAggregateId $nodeAggregateId
     * @param string $oldUriPath
     * @param string $newUriPath
     * @return bool
     */
    protected function createRedirectWithNewTarget(string $oldUriPath, string $newUriPath, SiteNodeName $siteNodeName): bool
    {
        if ($oldUriPath === $newUriPath) {
            return false;
        }

        $hosts = $this->getHostnames($siteNodeName);
        $statusCode = (integer)$this->defaultStatusCode[self::STATUS_CODE_TYPE_REDIRECT];

        $this->redirectStorage->addRedirect($oldUriPath, $newUriPath, $statusCode, $hosts);

        return true;
    }

    /**
     * Removes a redirect
     *
     * @param string $oldUriPath
     * @param SiteNodeName $siteNodeName
     * @return bool
     */
    protected function createRedirectForRemovedTarget(string $oldUriPath, SiteNodeName $siteNodeName): bool
    {
        // By default the redirect handling for removed nodes is activated.
        // If it is deactivated in your settings you will be able to handle the redirects on your own.
        // For example redirect to dedicated landing pages for deleted campaign NodeTypes
        if ($this->enableRemovedNodeRedirect) {
            $hosts = $this->getHostnames($siteNodeName);
            $statusCode = (integer)$this->defaultStatusCode[self::STATUS_CODE_TYPE_GONE];
            $this->redirectStorage->addRedirect($oldUriPath, '', $statusCode, $hosts);

            return true;
        }

        return false;
    }

    /**
     * Check if the current node type is restricted by Settings
     *
     * @param Node $node
     * @return bool
     */
    protected function isRestrictedByNodeType(Node $node): bool
    {
        if (!isset($this->restrictByNodeType)) {
            return false;
        }

        foreach ($this->restrictByNodeType as $disabledNodeType => $status) {
            if ($status !== true) {
                continue;
            }
            if ($node->nodeType->isOfType($disabledNodeType)) {
                $this->logger->debug(vsprintf('Redirect skipped based on the current node type (%s) for node %s because is of type %s', [
                    $node->nodeType->name->value,
                    $node->nodeAggregateId->value,
                    $disabledNodeType
                ]));

                return true;
            }
        }

        return false;
    }

    /**
     * Check if the old URI is restricted by Settings
     *
     * @param string $oldUriPath
     * @return bool
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
                $this->logger->debug(vsprintf('Redirect skipped based on the old URI (%s) because prefix matches %s', [
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
     *
     * @param SiteNodeName $siteNodeName
     * @return array
     */
    protected function getHostnames(SiteNodeName $siteNodeName): array
    {
        // TODO: Caching
        $site = $this->siteRepository->findOneByNodeName($siteNodeName);

        $domains = [];
        if ($site === null) {
            return $domains;
        }

        foreach ($site->getActiveDomains() as $domain) {
            /** @var Domain $domain */
            $domains[] = $domain->getHostname();
        }

        return $domains;
    }

    /**
     * Creates a (relative) URI for the given $nodeInfo
     *
     * @param string $uriPath
     * @return string the resulting (relative) URI
     */
    protected function buildUri(string $uriPath): string
    {
        // TODO: Add dimension prefix
        // TODO: Add uriSuffix
        return $uriPath;
    }
}
