<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Http;

use Psr\Http\Message\ResponseInterface;
use Spiral\Core\Component;
use Spiral\Core\Container;

class MiddlewarePipe extends Component
{
    /**
     * Set of middleware layers builded to handle incoming Request and return Response. Middleware
     * can be represented as class, string (DI) or array (callable method).
     *
     * @var array|MiddlewareInterface[]
     */
    protected $middleware = array();

    /**
     * Final endpoint has to be called, this is "the deepest" part of pipeline. It's not necessary
     * that this endpoint will be called at all, as one of middleware layers can stop processing.
     *
     * @var callable
     */
    protected $target = null;

    /**
     * Pipe context, usually includes parent object or options provided from outside. Can be used to
     * identify basePath, base request or route options.
     *
     * @var mixed
     */
    protected $context = null;

    /**
     * Middleware Pipeline used by HttpDispatchers to pass request thought middleware(s) and receive
     * filtered result. Pipeline can be used outside dispatcher in routes, modules and controllers.
     *
     * @param MiddlewareInterface[] $middleware
     */
    public function __construct(array $middleware = array())
    {
        $this->middleware = $middleware;
    }

    /**
     * Add new middleware to end of chain. Middleware can be represented as class, string (DI) or
     * array (callable method). Use can use closures to specify middleware. Every middleware will
     * receive 3 parameters, Request, next closure and context.
     *
     * @param mixed $middleware
     * @return static
     */
    public function add($middleware)
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * Every pipeline should have specified target to generate "deepest" response instance or other
     * response data (depends on context). Target should always be specified.
     *
     * @param callable $target
     * @return static
     */
    public function target($target)
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Run pipeline chain with specified input request and context. Response type depends on target
     * method and middleware logic.
     *
     * @param Request $input
     * @param mixed   $context
     * @return mixed
     */
    public function run(Request $input, $context = null)
    {
        $this->context = $context;

        return $this->next(0, $input);
    }

    /**
     * Internal method used to jump between middleware layers.
     *
     * @param int     $position
     * @param Request $input
     * @return mixed
     */
    protected function next($position = 0, $input = null)
    {
        $next = function ($contextInput = null) use ($position, $input)
        {
            return $this->next(++$position, $contextInput ?: $input);
        };

        if (!isset($this->middleware[$position]))
        {
            return call_user_func($this->target, $input);
        }

        /**
         * @var callable $middleware
         */
        $middleware = $this->middleware[$position];
        $middleware = is_string($middleware) ? Container::get($middleware) : $middleware;

        return $middleware($input, $next, $this->context);
    }
}