<?php

declare(strict_types=1);

namespace Spiral\Router;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Spiral\Router\Exception\RouteException;
use Spiral\Router\Exception\RouteNotFoundException;
use Spiral\Router\Exception\RouterException;
use Spiral\Router\Exception\UndefinedRouteException;
use Spiral\Router\Loader\Configurator\RoutingConfigurator;
use Spiral\Router\Target\AbstractTarget;

/**
 * Manages set of routes.
 */
final class Router implements RouterInterface
{
    // attribute to store active route in request
    public const ROUTE_ATTRIBUTE = 'route';

    // attribute to store active route in request
    public const ROUTE_NAME = 'routeName';

    // attribute to store active route in request
    public const ROUTE_MATCHES = 'matches';

    private string $basePath = '/';

    /** @var RouteInterface[] */
    private array $routes = [];

    private ?RouteInterface $default = null;

    public function __construct(
        string $basePath,
        private readonly UriHandler $uriHandler,
        private readonly ContainerInterface $container,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->basePath = '/' . \ltrim($basePath, '/');
    }

    /**
     * @throws RouteNotFoundException
     * @throws RouterException
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $route = $this->matchRoute($request, $routeName);
        } catch (RouteException $e) {
            throw new RouterException('Invalid route definition', $e->getCode(), $e);
        }

        if ($route === null) {
            $this->eventDispatcher?->dispatch(new RouteNotFound($request));
            throw new RouteNotFoundException($request->getUri());
        }

        $this->eventDispatcher?->dispatch(new RouteFound($route));

        return $route->handle(
            $request
                ->withAttribute(self::ROUTE_ATTRIBUTE, $route)
                ->withAttribute(self::ROUTE_NAME, $routeName)
                ->withAttribute(self::ROUTE_MATCHES, $route->getMatches() ?? [])
        );
    }

    public function setRoute(string $name, RouteInterface $route): void
    {
        // each route must inherit basePath prefix
        $this->routes[$name] = $this->configure($route);
    }

    public function setDefault(RouteInterface $route): void
    {
        $this->default = $this->configure($route);
    }

    public function getRoute(string $name): RouteInterface
    {
        if (isset($this->routes[$name])) {
            return $this->routes[$name];
        }

        throw new UndefinedRouteException(\sprintf('Undefined route `%s`', $name));
    }

    public function getRoutes(): array
    {
        if (!empty($this->default)) {
            return $this->routes + [null => $this->default];
        }

        return $this->routes;
    }

    public function uri(string $route, iterable $parameters = []): UriInterface
    {
        try {
            return $this->getRoute($route)->uri($parameters);
        } catch (UndefinedRouteException) {
            //In some cases route name can be provided as controller:action pair, we can try to
            //generate such route automatically based on our default/fallback route
            return $this->castRoute($route)->uri($parameters);
        }
    }

    public function import(RoutingConfigurator $routes): void
    {
        /** @var GroupRegistry $groups */
        $groups = $this->container->get(GroupRegistry::class);

        foreach ($routes->getCollection() as $name => $configurator) {
            $target = $configurator->target;
            if ($configurator->core !== null && $target instanceof AbstractTarget) {
                $target = $target->withCore($configurator->core);
            }

            $route = new Route(
                $configurator->prefix . '/' . \ltrim($configurator->pattern, '/'),
                $target,
                $configurator->defaults
            );

            if ($configurator->middleware !== null) {
                $route = $route->withMiddleware(...$configurator->middleware);
            }

            if ($configurator->methods !== null) {
                $route = $route->withVerbs(...$configurator->methods);
            }

            if (!isset($this->routes[$name]) && $name !== RoutingConfigurator::DEFAULT_ROUTE_NAME) {
                $groups->getGroup($configurator->group ?? $groups->getDefaultGroup())->addRoute($name, $route);
            }

            if ($name === RoutingConfigurator::DEFAULT_ROUTE_NAME) {
                $this->setDefault($route);
            }
        }
    }

    /**
     * Find route matched for given request.
     */
    protected function matchRoute(ServerRequestInterface $request, string &$routeName = null): ?RouteInterface
    {
        foreach ($this->routes as $name => $route) {
            // Matched route will return new route instance with matched parameters
            $matched = $route->match($request);

            if ($matched !== null) {
                $routeName = $name;
                return $matched;
            }
        }

        if ($this->default !== null) {
            return $this->default->match($request);
        }

        // unable to match any route
        return null;
    }

    /**
     * Configure route with needed dependencies.
     */
    protected function configure(RouteInterface $route): RouteInterface
    {
        if ($route instanceof ContainerizedInterface && !$route->hasContainer()) {
            // isolating route in a given container
            $route = $route->withContainer($this->container);
        }

        return $route->withUriHandler($this->uriHandler->withPrefix($this->basePath));
    }

    /**
     * Locates appropriate route by name. Support dynamic route allocation using following pattern:
     * Named route:   `name/controller:action`
     * Default route: `controller:action`
     * Only action:   `name/action`
     *
     * @throws UndefinedRouteException
     */
    protected function castRoute(string $route): RouteInterface
    {
        if (
            !\preg_match(
                '/^(?:(?P<name>[^\/]+)\/)?(?:(?P<controller>[^:]+):+)?(?P<action>[a-z_\-]+)$/i',
                $route,
                $matches
            )
        ) {
            throw new UndefinedRouteException(
                "Unable to locate route or use default route with 'name/controller:action' pattern"
            );
        }

        if (!empty($matches['name'])) {
            $routeObject = $this->getRoute($matches['name']);
        } elseif ($this->default !== null) {
            $routeObject = $this->default;
        } else {
            throw new UndefinedRouteException(\sprintf('Unable to locate route candidate for `%s`', $route));
        }

        return $routeObject->withDefaults(
            [
                'controller' => $matches['controller'],
                'action'     => $matches['action'],
            ]
        );
    }
}
