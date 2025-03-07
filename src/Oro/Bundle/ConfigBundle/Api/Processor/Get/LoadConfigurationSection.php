<?php

namespace Oro\Bundle\ConfigBundle\Api\Processor\Get;

use Oro\Bundle\ApiBundle\Processor\SingleItemContext;
use Oro\Bundle\ConfigBundle\Api\Processor\GetScope;
use Oro\Bundle\ConfigBundle\Api\Repository\ConfigurationRepository;
use Oro\Component\ChainProcessor\ContextInterface;
use Oro\Component\ChainProcessor\ProcessorInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads a configuration section.
 */
class LoadConfigurationSection implements ProcessorInterface
{
    private ConfigurationRepository $configRepository;

    public function __construct(ConfigurationRepository $configRepository)
    {
        $this->configRepository = $configRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContextInterface $context)
    {
        /** @var SingleItemContext $context */

        if ($context->hasResult()) {
            // data already retrieved
            return;
        }

        $section = $this->configRepository->getSection(
            $context->getId(),
            $context->get(GetScope::CONTEXT_PARAM)
        );
        if (!$section) {
            throw new NotFoundHttpException();
        }

        $context->setResult($section);
    }
}
