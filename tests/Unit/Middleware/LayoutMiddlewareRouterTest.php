<?php

declare(strict_types=1);

namespace Marko\Layout\Tests\Unit\Middleware\RouterIntegration;

use Marko\Core\Container\Container;
use Marko\Core\Container\PreferenceRegistry;
use Marko\Core\Discovery\ClassFileParser;
use Marko\Layout\Attributes\Component;
use Marko\Layout\Attributes\Layout;
use Marko\Layout\LayoutProcessorInterface;
use Marko\Layout\Middleware\LayoutMiddleware;
use Marko\Routing\Exceptions\HttpException;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Routing\Middleware\MiddlewareInterface;
use Marko\Routing\RouteCollection;
use Marko\Routing\RouteDefinition;
use Marko\Routing\Router;
use Marko\Routing\RoutingBootstrapper;

#[Component(template: 'layouts/dashboard.html', slots: ['content'])]
class DashboardLayoutComponent {}

#[Layout(component: DashboardLayoutComponent::class)]
class DashboardController
{
    public function index(): Response
    {
        return new Response('controller body');
    }

    public function missing(): Response
    {
        throw HttpException::notFound('Order not found.');
    }
}

/**
 * Auth-style route middleware: anonymous requests are redirected to /login.
 */
class RequiresLoginMiddleware implements MiddlewareInterface
{
    public function handle(
        Request $request,
        callable $next,
    ): Response {
        if ($request->header('X-User') === null) {
            return Response::redirect('/login');
        }

        return $next($request);
    }
}

/**
 * Authorization-style route middleware that throws, as #[Can] does.
 */
class ForbidsMiddleware implements MiddlewareInterface
{
    public function handle(
        Request $request,
        callable $next,
    ): Response {
        throw HttpException::forbidden('Admins only.');
    }
}

class RecordingLayoutProcessor implements LayoutProcessorInterface
{
    public int $calls = 0;

    public function process(
        string $controllerClass,
        string $action,
        string $routePath,
        array $routeParameters,
        Request $request,
    ): Response {
        $this->calls++;

        return Response::html('<dashboard>secret account data</dashboard>');
    }
}

/**
 * @param array<class-string<MiddlewareInterface>> $routeMiddleware
 */
function bootLayoutRouter(
    string $action,
    array $routeMiddleware,
    RecordingLayoutProcessor $processor,
): Router {
    $preferenceRegistry = new PreferenceRegistry();
    $container = new Container($preferenceRegistry);
    $container->instance(LayoutProcessorInterface::class, $processor);

    $router = new RoutingBootstrapper(
        modules: [],
        container: $container,
        preferenceRegistry: $preferenceRegistry,
        classFileParser: new ClassFileParser(),
    )->boot([LayoutMiddleware::class]);

    $container->get(RouteCollection::class)->add(new RouteDefinition(
        method: 'GET',
        path: '/dashboard',
        controller: DashboardController::class,
        action: $action,
        middleware: $routeMiddleware,
    ));

    return $router;
}

/**
 * @param array<string, string> $server
 */
function dashboardRequest(
    array $server = [],
): Request {
    return new Request(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard', ...$server]);
}

describe('LayoutMiddleware as global middleware through Router::handle()', function (): void {
    it('returns the auth redirect to an anonymous request instead of rendering the layout', function (): void {
        $processor = new RecordingLayoutProcessor();
        $router = bootLayoutRouter('index', [RequiresLoginMiddleware::class], $processor);

        $response = $router->handle(dashboardRequest());

        expect($response->statusCode())->toBe(302)
            ->and($response->headers()['Location'])->toBe('/login')
            ->and($response->body())->not->toContain('secret account data')
            ->and($processor->calls)->toBe(0);
    });

    it('renders the layout for an authenticated request', function (): void {
        $processor = new RecordingLayoutProcessor();
        $router = bootLayoutRouter('index', [RequiresLoginMiddleware::class], $processor);

        $response = $router->handle(dashboardRequest(['HTTP_X_USER' => 'mark']));

        expect($response->statusCode())->toBe(200)
            ->and($response->body())->toBe('<dashboard>secret account data</dashboard>')
            ->and($processor->calls)->toBe(1);
    });

    it('returns the 403 rendered from an exception thrown by inner middleware', function (): void {
        $processor = new RecordingLayoutProcessor();
        $router = bootLayoutRouter('index', [ForbidsMiddleware::class], $processor);

        $response = $router->handle(dashboardRequest());

        expect($response->statusCode())->toBe(403)
            ->and($response->body())->toContain('Admins only.')
            ->and($processor->calls)->toBe(0);
    });

    it('returns the 404 rendered from an exception thrown by the controller', function (): void {
        $processor = new RecordingLayoutProcessor();
        $router = bootLayoutRouter('missing', [], $processor);

        $response = $router->handle(dashboardRequest());

        expect($response->statusCode())->toBe(404)
            ->and($response->body())->toContain('Order not found.')
            ->and($processor->calls)->toBe(0);
    });
});
