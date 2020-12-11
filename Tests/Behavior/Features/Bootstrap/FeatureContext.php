<?php

require_once(__DIR__ . '/../../../../../Neos.Behat/Tests/Behat/FlowContextTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository/Tests/Behavior/Features/Bootstrap/NodeOperationsTrait.php');
require_once(__DIR__ . '/RedirectOperationTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository/Tests/Behavior/Features/Bootstrap/NodeAuthorizationTrait.php');
require_once(__DIR__ . '/../../../../../../Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/IsolatedBehatStepsTrait.php');
require_once(__DIR__ . '/../../../../../../Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/SecurityOperationsTrait.php');

use Behat\MinkExtension\Context\MinkContext;
use Neos\Behat\Tests\Behat\FlowContextTrait;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Service\AuthorizationService;
use Neos\ContentRepository\Tests\Behavior\Features\Bootstrap\NodeAuthorizationTrait;
use Neos\ContentRepository\Tests\Behavior\Features\Bootstrap\NodeOperationsTrait;
use Neos\Flow\Tests\Behavior\Features\Bootstrap\IsolatedBehatStepsTrait;
use Neos\Flow\Tests\Behavior\Features\Bootstrap\SecurityOperationsTrait;
use Neos\RedirectHandler\NeosAdapter\Tests\Behavior\Features\Bootstrap\RedirectOperationTrait;

/**
 * Features context
 */
class FeatureContext extends MinkContext
{
    use FlowContextTrait;
    use NodeAuthorizationTrait;
    use IsolatedBehatStepsTrait;
    use SecurityOperationsTrait;
    use RedirectOperationTrait;
    use NodeOperationsTrait;

    /**
     * Initializes the context
     */
    public function __construct()
    {
        if (self::$bootstrap === null) {
            self::$bootstrap = $this->initializeFlow();
        }
        $this->objectManager = self::$bootstrap->getObjectManager();
        $this->nodeAuthorizationService = $this->objectManager->get(AuthorizationService::class);
        $this->nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        $this->setupSecurity();
    }
}
