<?php

/*
 * This file is part of the Neos.RedirectHandler.NeosAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\RedirectHandler\DatabaseStorage\Domain\Repository\RedirectRepository;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;
use Neos\RedirectHandler\DatabaseStorage\RedirectStorage;
use PHPUnit\Framework\Assert;

trait RedirectOperationTrait
{
    /**
     * @template T of object
     * @param class-string<T> $className
     *
     * @return T
     */
    abstract private function getObject(string $className): object;

    /**
     * @Given /^I have the following redirects:$/
     * @When /^I create the following redirects:$/
     */
    public function iHaveTheFollowingRedirects($table): void
    {
        $rows = $table->getHash();
        $nodeRedirectStorage = $this->getObject(RedirectStorage::class);
        $redirectRepository = $this->getObject(RedirectRepository::class);

        foreach ($rows as $row) {
            $nodeRedirectStorage->addRedirect(
                $row['sourceuripath'],
                $row['targeturipath']
            );
        }

        $redirectRepository->persistEntities();
    }

    /**
     *  @Given /^I should have a redirect with sourceUri "([^"]*)" and targetUri "([^"]*)"$/
     */
    public function iShouldHaveARedirectWithSourceUriAndTargetUri($sourceUri, $targetUri): void
    {
        $nodeRedirectStorage = $this->getObject(RedirectStorage::class);

        $redirect = $nodeRedirectStorage->getOneBySourceUriPathAndHost($sourceUri);

        if ($redirect !== null) {
            Assert::assertEquals(
                $targetUri,
                $redirect->getTargetUriPath(),
                'A redirect was created, but the target URI does not match'
            );
        } else {
            Assert::assertNotNull($redirect, 'No redirect was created for asserted sourceUri');
        }
    }

    /**
     *  @Given /^I should have a redirect with sourceUri "([^"]*)" and statusCode "([^"]*)"$/
     */
    public function iShouldHaveARedirectWithSourceUriAndStatus($sourceUri, $statusCode): void
    {
        $nodeRedirectStorage = $this->getObject(RedirectStorage::class);

        $redirect = $nodeRedirectStorage->getOneBySourceUriPathAndHost($sourceUri);

        if ($redirect !== null) {
            Assert::assertEquals(
                $statusCode,
                $redirect->getStatusCode(),
                'A redirect was created, but the status code does not match'
            );
        } else {
            Assert::assertNotNull($redirect, 'No redirect was created for asserted sourceUri');
        }
    }

    /**
     *  @Given /^I should have no redirect with sourceUri "([^"]*)" and targetUri "([^"]*)"$/
     */
    public function iShouldHaveNoRedirectWithSourceUriAndTargetUri($sourceUri, $targetUri): void
    {
        $nodeRedirectStorage = $this->getObject(RedirectStorage::class);
        $redirect = $nodeRedirectStorage->getOneBySourceUriPathAndHost($sourceUri);

        if ($redirect !== null) {
            Assert::assertNotEquals(
                $targetUri,
                $redirect->getTargetUriPath(),
                'An unwanted redirect was created for given source and target URI'
            );
        } else {
            Assert::assertNull($redirect);
        }
    }

    /**
     *  @Given /^I should have no redirect with sourceUri "([^"]*)"$/
     */
    public function iShouldHaveNoRedirectWithSourceUri($sourceUri): void
    {
        $nodeRedirectStorage = $this->getObject(RedirectStorage::class);

        $redirect = $nodeRedirectStorage->getOneBySourceUriPathAndHost($sourceUri);

        Assert::assertNull($redirect);
    }
}
