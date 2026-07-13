<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SalesChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CustomerController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['registered', 'guest'], true)
            ? (string) $request->query('status')
            : '';
        $channelId = max(0, (int) $request->query('channel'));

        $customers = Customer::query()
            ->with('externalAccounts.integration.salesChannel')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('email', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('display_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhereHas('externalAccounts', function (Builder $account) use ($like): void {
                            $account
                                ->where('external_customer_id', 'like', $like)
                                ->orWhere('username', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        })
                        ->orWhereHas('orders', function (Builder $order) use ($like): void {
                            $order
                                ->where('external_number', 'like', $like)
                                ->orWhere('external_id', 'like', $like);
                        });
                });
            })
            ->when($status !== '', fn (Builder $query): Builder => $query->where('account_status', $status))
            ->when($channelId > 0, function (Builder $query) use ($channelId): void {
                $query->where(function (Builder $query) use ($channelId): void {
                    $query
                        ->whereHas('externalAccounts.integration', fn (Builder $integration): Builder => $integration->where('sales_channel_id', $channelId))
                        ->orWhereHas('orders', fn (Builder $order): Builder => $order->where('sales_channel_id', $channelId));
                });
            })
            ->orderByDesc('last_order_at')
            ->orderBy('display_name')
            ->orderBy('email')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $metrics = [
            'all' => Customer::query()->count(),
            'registered' => Customer::query()->where('account_status', 'registered')->count(),
            'guest' => Customer::query()->where('account_status', 'guest')->count(),
            'orders' => (int) Customer::query()->sum('orders_count'),
        ];

        $channels = SalesChannel::query()
            ->whereHas('integrations')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_active']);

        return view('customers.index', [
            'title' => 'Klienci',
            'subtitle' => 'Konta oraz goście z WooCommerce, ich zamówienia i dane kontaktowe w jednym miejscu.',
            'module' => 'customers',
            'customers' => $customers,
            'channels' => $channels,
            'metrics' => $metrics,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'channel' => $channelId,
            ],
        ]);
    }

    public function show(Customer $customer): View
    {
        $customer->load('externalAccounts.integration.salesChannel');

        $orders = $customer->orders()
            ->with('salesChannel')
            ->latest('external_created_at')
            ->latest('id')
            ->paginate(15, ['*'], 'orders_page')
            ->withQueryString();

        return view('customers.show', [
            'title' => $this->customerName($customer),
            'subtitle' => 'Karta klienta: konto WooCommerce, dane kontaktowe, historia zakupów i punkty lojalnościowe.',
            'module' => 'customers',
            'headerBackUrl' => route('customers.index'),
            'customer' => $customer,
            'orders' => $orders,
        ]);
    }

    private function customerName(Customer $customer): string
    {
        $firstAndLastName = trim(implode(' ', array_filter([
            $customer->first_name,
            $customer->last_name,
        ])));

        return $firstAndLastName !== ''
            ? $firstAndLastName
            : ($customer->display_name ?: ($customer->email ?: 'Klient #'.$customer->id));
    }
}
