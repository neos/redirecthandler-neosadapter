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

use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;

/**
 * The Neos RedirectHandler NeosAdapter Package
 */
class Package extends BasePackage
{
    /**
     * @Flow\InjectConfiguration(path="disableAutomaticRedirects")
     * @var boolean
     */
    protected $disableAutomaticRedirects;

    /**
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap): void
    {
        if ($this->disableAutomaticRedirects === false) {
            $dispatcher = $bootstrap->getSignalSlotDispatcher();

            $dispatcher->connect(Workspace::class, 'beforeNodePublishing', NodeRedirectService::class, 'collectPossibleRedirects');
            $dispatcher->connect(PersistenceManager::class, 'allObjectsPersisted', NodeRedirectService::class, 'createPendingRedirects');
        }
    }
}
