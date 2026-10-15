<?php

namespace Rekhu\Assistant\Runtime;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Contracts\Session\Session;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Rekhu\Assistant\Catalogue\Routes;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Performs an action as the signed-in user, in this same request: the route is matched like a form post, its middleware
 * runs (auth, can:, bindings, throttles, the app's own), then the controller. The session and cookie layers are skipped
 * because the user's session is already open and shared, so the user stays signed in and nothing is sent twice.
 */
class Dispatcher
{
    /** Middleware the inner call skips: the outer request already did their work. */
    private const SKIP = [
        StartSession::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, ShareErrorsFromSession::class,
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
    ];

    /** ['ok' => true, 'result' => …] or ['ok' => false, 'error' => …, 'errors'?] */
    public static function run(array $action, array $input, Request $outer): array
    {
        $route = $action['route'];
        $parameters = array_intersect_key($input, array_flip($route->parameterNames()));
        $body = array_diff_key($input, $parameters);
        $verb = Routes::verb($route);
        try {
            $url = app('url')->toRoute($route, $parameters, true);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'missing in the address: '.$e->getMessage()];
        }

        // the input goes as a JSON body (numbers stay numbers), or as the query of a GET; the outer request's own body never comes along
        $server = array_merge($outer->server->all(), ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'CONTENT_TYPE' => 'application/json']);
        unset($server['CONTENT_LENGTH'], $server['HTTP_CONTENT_LENGTH']);
        $inner = $verb === 'GET'
            ? Request::create($url, 'GET', $body, [], [], $server)
            : Request::create($url, $verb, [], [], [], $server, json_encode((object) $body));
        // an API request has no session: the action uses the app's session store, which redirect()->with() writes to, so
        // a controller that flashes a message still works; nothing saves it, and it is emptied again afterwards
        $stateless = ! $outer->hasSession();
        $session = $stateless ? app('session.store') : $outer->session();
        $inner->setLaravelSession($session);
        $inner->setUserResolver($outer->getUserResolver());

        // the HTTP kernel hands its middleware groups (web, api…) to the router; outside a web request it may not have yet
        app(\Illuminate\Contracts\Http\Kernel::class);
        $router = app(Router::class);
        $previous = app('request');
        $flash = ['status', 'success', 'message'];
        $before = array_map(fn ($k) => $session->get($k), $flash);
        app()->instance('request', $inner);
        Facade::clearResolvedInstance('request');
        try {
            $matched = $router->getRoutes()->match($inner);
            if ($matched->uri() !== $route->uri()) {
                return ['ok' => false, 'error' => 'this action could not be reached'];
            }
            $inner->setRouteResolver(fn () => $matched);
            $middleware = array_filter($router->gatherRouteMiddleware($matched), function ($m) {
                $class = is_string($m) ? explode(':', $m, 2)[0] : $m;
                foreach (self::SKIP as $skip) {
                    if (is_string($class) && is_a($class, $skip, true)) {
                        return false;
                    }
                }

                return true;
            });
            $response = (new Pipeline(app()))->send($inner)->through($middleware)
                ->then(fn ($request) => Router::toResponse($request, $matched->run()));

            return self::answer($response, $session, $flash, $before);
        } catch (ValidationException $e) {
            return ['ok' => false, 'error' => 'the input was refused', 'errors' => $e->errors()];
        } catch (AuthorizationException) {
            return ['ok' => false, 'error' => 'the user may not do this'];
        } catch (AuthenticationException) {
            return ['ok' => false, 'error' => 'the user is not signed in'];
        } catch (ModelNotFoundException) {
            return ['ok' => false, 'error' => 'that record does not exist, or the user cannot see it'];
        } catch (HttpExceptionInterface $e) {
            $status = $e->getStatusCode();

            return ['ok' => false, 'error' => match (true) {
                $status === 403 => 'the user may not do this',
                $status === 404 => 'that record does not exist, or the user cannot see it',
                $status === 429 => 'too many attempts; try again later',
                default => 'the action failed ('.$status.')'.($e->getMessage() ? ': '.$e->getMessage() : ''),
            }];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'the action failed in the app; the error is in its log'];
        } finally {
            if ($stateless) {
                $session->flush();
            }
            app()->instance('request', $previous);
            Facade::clearResolvedInstance('request');
        }
    }

    /** What the action answered: JSON as is, a redirect as where it went and the message it flashed. */
    private static function answer($response, Session $session, array $flash, array $before): array
    {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $data = $response instanceof JsonResponse ? $response->getData(true) : null;

            return ['ok' => false, 'error' => is_array($data) ? ($data['message'] ?? 'the action failed') : 'the action failed ('.$status.')'];
        }
        $message = null;
        foreach ($flash as $i => $key) {
            $now = $session->get($key);
            if (is_string($now) && $now !== $before[$i]) {
                $message = $now;
            }
        }
        if ($response instanceof RedirectResponse) {
            return ['ok' => true, 'result' => array_filter(['status' => 'done', 'message' => $message, 'page' => $response->getTargetUrl()])];
        }
        if ($response instanceof JsonResponse) {
            return ['ok' => true, 'result' => $response->getData(true)];
        }

        return ['ok' => true, 'result' => array_filter(['status' => 'done', 'message' => $message])];
    }
}
