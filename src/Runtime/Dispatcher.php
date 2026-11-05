<?php

namespace Rekhu\Assistant\Runtime;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use JsonSerializable;
use Rekhu\Assistant\Catalogue\Routes;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Performs an action as the signed-in user, in this same request: the route is matched like a form post, its middleware
 * runs (auth, can:, bindings, throttles, the app's own), then the controller. The session and cookie layers are skipped
 * because the user's session is already open and shared, so the user stays signed in and nothing is sent twice.
 * A Tool class has no route: its input is validated against its rules and its handle() is called. Either way a download
 * becomes a file the user gets a button for, and only the fields the action asks for are kept.
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
    public static function run(array $action, array $input, Request $outer, ?Conversation $conversation = null): array
    {
        // files the user attached go in by their id (att_…): the action gets them as uploaded files, as from a form
        [$input, $files] = self::files($input, $conversation);
        $outcome = isset($action['class']) ? self::tool($action['class'], $input + $files, $outer) : self::route($action['route'], $input, $outer, $files);
        if ($outcome['ok'] && ($action['fields'] ?? []) && ($outcome['result']['status'] ?? null) !== 'file') {
            $outcome['result'] = Shape::pick($outcome['result'], $action['fields']);
        }

        return $outcome;
    }

    /** The input without the attachment ids, and the files they stand for, under the same names. */
    private static function files(array $input, ?Conversation $conversation): array
    {
        $files = [];
        if (! $conversation || ! $conversation->attachments) {
            return [$input, $files];
        }
        $isId = fn ($v) => is_string($v) && isset($conversation->attachments[$v]);
        foreach ($input as $field => $value) {
            if ($isId($value)) {
                $file = Attachments::upload($conversation, $value);
                if ($file) {
                    $files[$field] = $file;
                    unset($input[$field]);
                }
            } elseif (is_array($value) && $value && array_is_list($value) && count(array_filter($value, $isId)) === count($value)) {
                $files[$field] = array_values(array_filter(array_map(fn ($id) => Attachments::upload($conversation, $id), $value)));
                unset($input[$field]);
            }
        }

        return [$input, $files];
    }

    private static function route(Route $route, array $input, Request $outer, array $files = []): array
    {
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
        if ($files && $verb !== 'GET') {
            // with files the body is a form, as a browser sends an upload; PUT and PATCH go as POST with _method, as Laravel's forms do
            $server['CONTENT_TYPE'] = 'multipart/form-data';
            $inner = Request::create($url, in_array($verb, ['PUT', 'PATCH', 'DELETE'], true) ? 'POST' : $verb, $body + (in_array($verb, ['PUT', 'PATCH', 'DELETE'], true) ? ['_method' => $verb] : []), [], $files, $server);
            if (in_array($verb, ['PUT', 'PATCH', 'DELETE'], true)) {
                $inner->setMethod($verb);
            }
        } else {
            $inner = $verb === 'GET'
                ? Request::create($url, 'GET', $body, [], [], $server)
                : Request::create($url, $verb, [], [], [], $server, json_encode((object) $body));
        }
        // an API request has no session: the action uses the app's session store, which redirect()->with() writes to, so
        // a controller that flashes a message still works; nothing saves it, and it is emptied again afterwards
        $stateless = ! $outer->hasSession();
        $session = $stateless ? app('session.store') : $outer->session();
        $inner->setLaravelSession($session);
        $inner->setUserResolver($outer->getUserResolver());

        // the HTTP kernel hands its middleware groups (web, api…) to the router; outside a web request it may not have yet
        app(Kernel::class);
        $router = app(Router::class);
        $previous = app('request');
        $flash = ['status', 'success', 'message'];
        $before = array_map(fn ($k) => $session->get($k), $flash);
        app()->instance('request', $inner);
        Facade::clearResolvedInstance('request');
        // binding the request makes Laravel point its user at the auth guard again; the action runs as the chat's user
        $inner->setUserResolver($outer->getUserResolver());
        try {
            $matched = $router->getRoutes()->match($inner);
            if ($matched->uri() !== $route->uri()) {
                return ['ok' => false, 'error' => 'this action could not be reached'];
            }
            $inner->setRouteResolver(fn () => $matched);
            // Laravel keeps a route's controller once built: listing the actions built it from the chat request, and an
            // earlier call from its own input. A controller that reads the request in its constructor needs this call's.
            $matched->flushController();
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
        } catch (Throwable $e) {
            return self::failure($e);
        } finally {
            if ($stateless) {
                $session->flush();
            }
            // nor may the app's next request (Octane) get the controller built from this call
            $route->flushController();
            app()->instance('request', $previous);
            Facade::clearResolvedInstance('request');
        }
    }

    /** A Tool class: a fresh instance for this call, its input validated, then handle() with the input as the request. */
    private static function tool(string $class, array $input, Request $outer): array
    {
        $server = array_merge($outer->server->all(), ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        unset($server['CONTENT_LENGTH'], $server['HTTP_CONTENT_LENGTH'], $server['CONTENT_TYPE']);
        $inner = Request::create($outer->url(), 'POST', $input, [], [], $server);
        $inner->setUserResolver($outer->getUserResolver());
        if ($outer->hasSession()) {
            $inner->setLaravelSession($outer->session());
        }
        $previous = app('request');
        app()->instance('request', $inner);
        Facade::clearResolvedInstance('request');
        $inner->setUserResolver($outer->getUserResolver());
        try {
            $tool = app($class);
            Validator::make($input, $tool->rules())->validate();
            $value = app()->call([$tool, 'handle'], ['request' => $inner]);
            if ($value instanceof JsonResource) {
                $value = $value->response($inner);
            }
            if ($value instanceof Response) {
                return self::answer($value, null, [], []);
            }

            return ['ok' => true, 'result' => match (true) {
                $value === null => ['status' => 'done'],
                $value instanceof Arrayable => $value->toArray(),
                $value instanceof JsonSerializable => $value->jsonSerialize(),
                is_array($value) => $value,
                default => ['result' => $value],
            }];
        } catch (Throwable $e) {
            return self::failure($e);
        } finally {
            app()->instance('request', $previous);
            Facade::clearResolvedInstance('request');
        }
    }

    /** What the model is told when an action fails: why, in words it can pass on, never the app's internals. */
    private static function failure(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return ['ok' => false, 'error' => 'the input was refused', 'errors' => $e->errors()];
        }
        if ($e instanceof AuthorizationException) {
            return ['ok' => false, 'error' => 'the user may not do this'];
        }
        if ($e instanceof AuthenticationException) {
            return ['ok' => false, 'error' => 'the user is not signed in'];
        }
        if ($e instanceof ModelNotFoundException) {
            return ['ok' => false, 'error' => 'that record does not exist, or the user cannot see it'];
        }
        if ($e instanceof HttpExceptionInterface) {
            return ['ok' => false, 'error' => self::refusal($e->getStatusCode(), $e->getMessage())];
        }
        report($e);

        return ['ok' => false, 'error' => 'the action failed in the app; the error is in its log'];
    }

    /** Why the app refused, in words the model can pass on: the app's own message, else what the status means. */
    private static function refusal(int $status, string $message): string
    {
        return match (true) {
            $status === 403 => 'the user may not do this',
            $status === 404 => 'that record does not exist, or the user cannot see it',
            $status === 429 => 'too many attempts; try again later',
            trim($message) !== '' => $message,
            default => 'the action failed ('.$status.')',
        };
    }

    /** What the action answered: JSON as is, a download as a file, a redirect as where it went and the message it flashed. */
    private static function answer(Response $response, ?Session $session, array $flash, array $before): array
    {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $data = $response instanceof JsonResponse ? $response->getData(true) : null;

            return ['ok' => false, 'error' => self::refusal($status, is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : '')];
        }
        if ($file = Files::from($response)) {
            return Files::keep(...$file);
        }
        $message = null;
        foreach ($flash as $i => $key) {
            $now = $session?->get($key);
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
