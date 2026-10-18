<?php

namespace App\Providers;

use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Rekhu\Assistant\RekhuAssistant;

/** How this app tells Rekhu Assistant about its customers, its rights and its front end's pages. */
class AssistantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A multi-tenant app: usage in your Rekhu account shows per customer (a model, or ['id' => …, 'name' => …]).
        // Users are identified to Rekhu by their id only, never by name or e-mail.
        RekhuAssistant::tenantUsing(fn ($user) => $user->team);

        // Rights checked another way than "can:" middleware: say which actions and pages a user is offered.
        // Every call still runs through the route's own middleware, so this only decides what is offered.
        RekhuAssistant::authorizeUsing(function ($user, Route $route) {
            // Spatie laravel-permission, with a permission per route name ("orders.index")
            return $user->can($route->getName());
            // Laratrust:                          return $user->isAbleTo($route->getName());
            // a permission from part of the name: return $user->isAbleTo(str($route->getName())->afterLast('.'));
        });

        // A front end of its own (Nuxt, Next, a mobile app): its pages, as this user may open them. No user means a
        // visitor of the public chat, or `php artisan rekhu:assistant` without --user.
        RekhuAssistant::pagesUsing(fn ($user) => ! $user ? [] : collect([
            ['path' => '/orders', 'label' => 'Orders', 'help' => 'All orders with their status', 'permission' => 'orders.index'],
            ['path' => '/orders/{order}', 'label' => 'Order', 'help' => 'One order with its lines and tracking', 'permission' => 'orders.show'],
            ['path' => '/invoices', 'label' => 'Invoices', 'help' => 'Invoices with download and payment status', 'permission' => 'invoices.index'],
        ])->filter(fn ($page) => $user->can($page['permission']))->all());
    }
}
