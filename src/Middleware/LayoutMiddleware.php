<?php

declare(strict_types=1);

namespace Marko\Layout\Middleware;

use Marko\Layout\Exceptions\LayoutNotFoundException;
use Marko\Layout\LayoutProcessorInterface;
use Marko\Layout\LayoutResolver;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Routing\Middleware\MiddlewareInterface;
use Marko\Routing\RouteMatcherInterface;

readonly class LayoutMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RouteMatcherInterface $routeMatcher,
        private LayoutProcessorInterface $layoutProcessor,
        private LayoutResolver $layoutResolver,
    ) {}

    public function handle(
        Request $request,
        callable $next,
    ): Response {
        $matched = $this->routeMatcher->match($request->method(), $request->path());

        if ($matched === null) {
            return $next($request);
        }

        $controllerClass = $matched->route->controller;
        $action = $matched->route->action;

        try {
            $this->layoutResolver->resolve($controllerClass, $action);
        } catch (LayoutNotFoundException) {
            return $next($request);
        }

        // Run the rest of the pipeline (route middleware + controller) first.
        // Inner denials — auth redirects, 401/403/419/429, 404s thrown by the
        // controller — arrive here as ordinary responses because the pipeline
        // renders HTTP exceptions at the depth they are thrown. They must reach
        // the client untouched; only a successful controller result is replaced
        // by the layout, whose components provide their own data.
        $response = $next($request);

        if (!$this->isSuccessfulResult($response)) {
            return $response;
        }

        return $this->layoutProcessor->process(
            controllerClass: $controllerClass,
            action: $action,
            routePath: $matched->route->path,
            routeParameters: $matched->parameters,
            request: $request,
        );
    }

    private function isSuccessfulResult(
        Response $response,
    ): bool {
        $status = $response->statusCode();

        if ($status < 200 || $status >= 300) {
            return false;
        }

        foreach (array_keys($response->headers()) as $name) {
            if (strtolower($name) === 'location') {
                return false;
            }
        }

        return true;
    }
}
