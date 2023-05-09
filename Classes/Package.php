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
            string $oldUriPath, string $newUriPath, SiteNodeName $siteNodeName, $_,
        ) use ($bootstrap) {
            $nodeRedirectService = $bootstrap->getObjectManager()->get(NodeRedirectService::class);
            $nodeRedirectService->createRedirect($oldUriPath, $newUriPath, $siteNodeName);
        });

        $dispatcher->connect(DocumentUriPathProjection::class, 'afterNodeAggregateWasRemoved', function (
            string $oldUriPath, SiteNodeName $siteNodeName, $_,
        ) use ($bootstrap) {
            $nodeRedirectService = $bootstrap->getObjectManager()->get(NodeRedirectService::class);
            $nodeRedirectService->createRedirect($oldUriPath, null, $siteNodeName);
        });

        $dispatcher->connect(DocumentUriPathProjection::class, 'afterDocumentUriPathChanged', function (
            string $oldUriPath, string $newUriPath, SiteNodeName $siteNodeName, $_, EventEnvelope $eventEnvelope,
        ) use ($bootstrap) {
            $nodeRedirectService = $bootstrap->getObjectManager()->get(NodeRedirectService::class);
            $nodeRedirectService->createRedirect($oldUriPath, $newUriPath, $siteNodeName);
        });

    }
}
