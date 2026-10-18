<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeAddressRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Rekhu\Assistant\Attributes\Page;
use Rekhu\Assistant\Attributes\Tool;

/**
 * One action of each kind, the way the assistant uses them best. The assistant calls these routes as the signed-in
 * user: the route's middleware, the form request's authorize() and rules, policies and route model binding all apply,
 * exactly as when the user clicks in the app.
 */
class OrderController extends Controller
{
    // A list. The description says what comes back and what each input does, so the assistant asks for exactly what
    // it needs instead of reading everything. The label is what the user reads while it runs.
    #[Tool(
        'List the user\'s orders, newest first: number, date, status (open, shipped, delivered, cancelled), total in EUR. '
        .'"status" keeps one status; "search" matches an order number or product name; "per_page" is 1 to 50 (default 10). '
        .'Use it to find an order before showing or changing it.',
        name: 'list_orders',
        label: ['en' => 'Looking up your orders', 'nl' => 'Ik zoek je bestellingen', 'ar' => 'أبحث في طلباتك'],
        rules: ['status' => 'nullable|in:open,shipped,delivered,cancelled', 'search' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:50'],
    )]
    #[Page('Orders', help: 'All orders of the account, with status and tracking')]
    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->search, fn ($q, $s) => $q->where('number', 'like', "%{$s}%")->orWhereRelation('lines', 'product_name', 'like', "%{$s}%"))
            ->latest()
            ->paginate($request->integer('per_page', 10));

        // JSON for the assistant (it asks with Accept: application/json), the page for everyone else
        return $request->expectsJson() ? $orders : view('orders.index', compact('orders'));
    }

    // One record. Route model binding and the policy decide whether this user may see it; for another user's order the
    // assistant is told "that record does not exist, or the user cannot see it".
    #[Tool(
        'One order in full: its lines (product, quantity, price), delivery address, status with dates, tracking link. "order" is the order id from list_orders.',
        name: 'show_order',
        label: ['en' => 'Opening the order', 'nl' => 'Ik open de bestelling', 'ar' => 'أفتح الطلب'],
    )]
    #[Page('Order', help: 'One order with its lines, address and tracking')]
    public function show(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        return $request->expectsJson() ? $order->load('lines') : view('orders.show', compact('order'));
    }

    // A change. The input comes from the form request's rules; its authorize() is checked first. Say when it applies,
    // so the assistant does not try it on an order that has shipped.
    #[Tool(
        'Change the delivery address of an order that has not shipped yet. Ask the user for the full new address first; never guess parts of it.',
        name: 'change_address',
        label: ['en' => 'Changing the delivery address', 'nl' => 'Ik wijzig het afleveradres', 'ar' => 'أغيّر عنوان التوصيل'],
        confirm: true,     // the user sees the new address and clicks Confirm before anything changes
    )]
    public function address(ChangeAddressRequest $request, Order $order)
    {
        $order->update($request->validated());

        // a redirect with a flashed message is fine: the assistant reads the message and the page it went to
        return back()->with('status', "The delivery address of order {$order->number} is changed.");
    }

    // A DELETE route: the user always confirms first, and the confirm card is shown in red.
    #[Tool(
        'Cancel an order that has not shipped yet. The money goes back to the payment method within 5 working days. Only when the user asks to cancel.',
        name: 'cancel_order',
        label: ['en' => 'Cancelling the order', 'nl' => 'Ik annuleer de bestelling', 'ar' => 'ألغي الطلب'],
    )]
    public function destroy(Order $order)
    {
        $this->authorize('cancel', $order);
        $order->cancel();

        return response()->json(['message' => "Order {$order->number} is cancelled. The refund follows within 5 working days."]);
    }

    // An action for the public chat too (guests: true): its route works without login, so it may only read public things.
    #[Tool(
        'Where a parcel is, by its tracking code (from the shipping e-mail). Works for visitors who are not signed in.',
        name: 'track_parcel',
        label: ['en' => 'Tracking the parcel', 'nl' => 'Ik volg het pakket', 'ar' => 'أتتبع الشحنة'],
        rules: ['code' => 'required|string|max:40'],
        guests: true,
    )]
    public function track(Request $request)
    {
        return response()->json(Order::trackingStatus($request->string('code')));
    }
}
