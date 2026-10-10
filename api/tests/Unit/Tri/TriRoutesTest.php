<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tri;
use MyInvoice\Tri\TriRoutes;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
final class TriRoutesTest extends TestCase
{
    public function testEveryRegisteredActionResolves(): void
    {
        $app=AppFactory::create();
        TriRoutes::register($app);
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $action=$route->getCallable();
            self::assertTrue(is_string($action)&&class_exists($action), $route->getPattern().' -> '.(string)$action);
        }
    }
}
