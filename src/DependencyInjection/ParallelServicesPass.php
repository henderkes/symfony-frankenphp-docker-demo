<?php

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all valid App\ services into a public ServiceLocator so
 * ext-parallel threads can access any autowired service from a booted
 * kernel without making individual services public.
 *
 * In a thread:
 *   $kernel = App\Kernel::bootForParallel();
 *   $locator = $kernel->getContainer()->get('parallel.services');
 *   $service = $locator->get(SomeService::class);
 */
class ParallelServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $refs = [];

        foreach ($container->getServiceIds() as $id) {
            if (!str_starts_with($id, 'App\\') || !$container->hasDefinition($id)) {
                continue;
            }

            $definition = $container->getDefinition($id);

            if ($definition->isAbstract() || $definition->isSynthetic() || $definition->hasErrors()) {
                continue;
            }

            $refs[$id] = new Reference($id);
        }

        if (!$refs) {
            return;
        }

        $locatorRef = ServiceLocatorTagPass::register($container, $refs);

        $container->setAlias('parallel.services', (string) $locatorRef)
            ->setPublic(true);
    }
}
