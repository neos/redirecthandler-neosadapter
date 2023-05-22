<?php
declare(strict_types=1);

namespace Neos\RedirectHandler\NeosAdapter;

/*
 * This file is part of the Neos.RedirectHandler.NeosAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\EventStore\Model\EventEnvelope;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathProjection;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Factory\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;

/**
 * The Neos RedirectHandler NeosAdapter Package
 */
class Package extends BasePackage
{
    /**
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(DocumentUriPathProjection::class, 'afterNodeAggregateWasMoved', function (
            ContentRepositoryId $contentRepositoryId, string $oldUriPath, string $newUriPath, NodeTypeName $nodeTypeName, SiteNodeName $siteNodeName, $_,
        ) use ($bootstrap) {
            $nodeRedirectService = $bootstrap->getObjectManager()->get(NodeRedirectService::class);

            $contentRepository = $bootstrap->getObjectManager()->get(ContentRepositoryRegistry::class)->get($contentRepositoryId);
            $nodeTypeManager = $contentRepository->getNodeTypeManager();
            $nodeType = $nodeTypeManager->getNodeType($nodeTypeName);

            $nodeRedirectService->createRedirect($oldUriPath, $newUriPath, $nodeType, $siteNodeName);
        });

        $dispatcher->connect(DocumentUriPathProjection::class, 'afterNodeAggregateWasRemoved', function (
            ContentRepositoryId $contentRepositoryId, string $oldUriPath, NodeTypeName $nodeTypeName, SiteNodeName $siteNodeName, $_,
        ) use ($bootstrap) {
            $nodeRedirectService = $bootstrap->getObjectManager()->get(NodeRedirectService::class);

            $contentRepository = $bootstrap->getObjectManager()->get(ContentRepositoryRegistry::class)->get($contentRepositoryId);
            $nodeTypeManager = $contentRepository->getNodeTypeManager();
            $nodeType = $nodeTypeManager->getNodeType($nodeTypeName);

            $nodeRedirectService->createRedirect($oldUriPath, null, $nodeType, $siteNodeName);
        });

        $dispatcher->connect(DocumentUriPathProjection::class, 'afterDocumentUriPathChanged', function (
            ContentRepositoryId $contentRepositoryId, string $oldUriPath, string $newUriPath, NodeTypeName $nodeTypeName, SiteNodeName $siteNodeName, $_, EventEnvelope $eventEnvelope,
        ) use ($bootstrap) {
            $nodeRedirectService = $bootstrap->getObjectManager()->get(NodeRedirectService::class);

            $contentRepository = $bootstrap->getObjectManager()->get(ContentRepositoryRegistry::class)->get($contentRepositoryId);
            $nodeTypeManager = $contentRepository->getNodeTypeManager();
            $nodeType = $nodeTypeManager->getNodeType($nodeTypeName);

            $nodeRedirectService->createRedirect($oldUriPath, $newUriPath, $nodeType, $siteNodeName);
        });

    }
}
