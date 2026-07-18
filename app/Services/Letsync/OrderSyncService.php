<?php

namespace App\Services\Letsync;

use App\Models\Item;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderSyncService
{
    public function __construct(
        private readonly OpenCartReader $reader,
        private readonly CustomerSyncService $customers,
        private readonly ProductSyncService $products,
        private readonly SyncLogger $logger,
    ) {}

    public function syncById(int $ocOrderId, string $event = 'sync'): void
    {
        $start = (int) (microtime(true) * 1000);

        $data = $this->reader->order($ocOrderId);
        if ($data === null) {
            $this->logger->skipped('order', $event, $ocOrderId, 'Order not found in OpenCart');

            return;
        }

        if ((int) ($data['order']['order_status_id'] ?? 0) === 0) {
            $this->logger->skipped('order', $event, $ocOrderId, 'Incomplete OpenCart order (status 0)');

            return;
        }

        $order = DB::transaction(fn (): Order => $this->upsert($data));
        $this->logger->success('order', $event, $ocOrderId, $order->id, $this->elapsed($start), 'Order synced');
    }

    public function deleteByExternalId(int $ocOrderId, string $event = 'delete_order'): void
    {
        Order::where('external_id', $ocOrderId)->get()->each(function (Order $order): void {
            OrderItem::where('order_id', $order->id)->delete();
            OrderPayment::where('order_id', $order->id)->delete();
            $order->delete();
        });
        $this->logger->success('order', $event, $ocOrderId, null, 0, 'Order deleted');
    }

    private function upsert(array $data): Order
    {
        $ocOrder = $data['order'];
        $externalId = (int) $ocOrder['order_id'];

        $customer = $this->resolveCustomer($ocOrder);
        $totals = $this->totals($data['totals'], (float) $ocOrder['total']);

        $order = Order::where('external_id', $externalId)->first() ?? new Order();
        $order->external_id = $externalId;
        $order->fill([
            'readable_id' => $order->readable_id ?: 'OC-' . $externalId,
            'module_id' => (int) config('letsync.module_id'),
            'customer_id' => $customer['id'],
            'customer_type' => $customer['type'],
            'order_type' => 'home_delivery',
            'order_platform' => 'web_order',
            'status' => $this->status($ocOrder),
            'order_total' => $totals['total'],
            'order_sub_total' => $totals['sub_total'],
            'total_tax' => $totals['tax'],
            'total_discount' => $totals['discount'],
            'stock_reserved' => false,
            'requires_prescription' => false,
            'is_prescription_request' => false,
        ]);
        $order->save();

        $this->syncItems($order, $data['products']);
        $this->syncPayment($order, $ocOrder, $totals['total']);
        $this->syncAddresses($order, $ocOrder);

        return $order;
    }

    private function syncAddresses(Order $order, array $ocOrder): void
    {
        $email = trim((string) ($ocOrder['email'] ?? '')) ?: null;
        $phone = trim((string) ($ocOrder['telephone'] ?? '')) ?: null;

        $this->upsertAddress($order, 'billing', $ocOrder, 'payment_', $email, $phone);

        $hasShipping = trim((string) ($ocOrder['shipping_address_1'] ?? '')) !== ''
            || trim((string) ($ocOrder['shipping_city'] ?? '')) !== '';

        if ($hasShipping) {
            $this->upsertAddress($order, 'shipping', $ocOrder, 'shipping_', $email, $phone);
        }
    }

    private function upsertAddress(Order $order, string $type, array $ocOrder, string $prefix, ?string $email, ?string $phone): void
    {
        $name = trim(($ocOrder[$prefix . 'firstname'] ?? '') . ' ' . ($ocOrder[$prefix . 'lastname'] ?? ''));
        if ($name === '') {
            $name = trim(($ocOrder['firstname'] ?? '') . ' ' . ($ocOrder['lastname'] ?? ''));
        }

        $addressLine = trim(($ocOrder[$prefix . 'address_1'] ?? '') . ' ' . ($ocOrder[$prefix . 'address_2'] ?? ''));

        $address = OrderAddress::where('order_id', $order->id)->where('type', $type)->first() ?? new OrderAddress();
        $address->fill([
            'order_id' => $order->id,
            'type' => $type,
            'name' => $name ?: null,
            'phone' => $phone,
            'email' => $email,
            'postal_code' => trim((string) ($ocOrder[$prefix . 'postcode'] ?? '')) ?: null,
            'city' => trim((string) ($ocOrder[$prefix . 'city'] ?? '')) ?: null,
            'state' => trim((string) ($ocOrder[$prefix . 'zone'] ?? '')) ?: null,
            'country' => trim((string) ($ocOrder[$prefix . 'country'] ?? '')) ?: null,
            'address' => $addressLine ?: null,
        ]);
        $address->save();
    }

    private function resolveCustomer(array $ocOrder): array
    {
        $ocCustomerId = (int) ($ocOrder['customer_id'] ?? 0);

        if ($ocCustomerId > 0) {
            $user = User::where('external_id', $ocCustomerId)->first();
            if ($user === null) {
                $this->customers->syncById($ocCustomerId, 'order_customer');
                $user = User::where('external_id', $ocCustomerId)->first();
            }
            if ($user !== null) {
                return ['id' => $user->id, 'type' => 'registered'];
            }
        }

        return ['id' => $this->guestUser($ocOrder)->id, 'type' => 'guest'];
    }

    private function guestUser(array $ocOrder): User
    {
        $email = strtolower(trim((string) ($ocOrder['email'] ?? '')));
        if ($email !== '') {
            $existing = User::where('email', $email)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return User::create([
            'first_name' => trim((string) ($ocOrder['firstname'] ?? 'Guest')),
            'last_name' => trim((string) ($ocOrder['lastname'] ?? '')),
            'email' => $email !== '' ? $email : null,
            'phone' => trim((string) ($ocOrder['telephone'] ?? '')) ?: null,
            'is_active' => true,
            'is_verified' => false,
            'user_type' => 'customer',
            'registration_type' => 'manual',
            'gender' => 'other',
        ]);
    }

    private function syncItems(Order $order, array $ocProducts): void
    {
        $keepIds = [];

        foreach ($ocProducts as $line) {
            $item = $this->resolveItem((int) ($line['product_id'] ?? 0));
            if ($item === null) {
                continue;
            }

            $quantity = (int) ($line['quantity'] ?? 1);
            $price = (float) ($line['price'] ?? 0);
            $lineTotal = (float) ($line['total'] ?? $price * $quantity);
            $externalLineId = (int) ($line['order_product_id'] ?? 0);

            $orderItem = OrderItem::where('order_id', $order->id)->where('external_id', $externalLineId)->first() ?? new OrderItem();
            $orderItem->external_id = $externalLineId;
            $orderItem->fill([
                'order_id' => $order->id,
                'item_id' => $item->id,
                'quantity' => $quantity,
                'price' => $price,
                'item_sub_total' => $lineTotal,
                'item_total' => $lineTotal,
                'total_tax' => 0,
                'total_discount' => 0,
                'item_basic_info' => json_encode([
                    'name' => $line['name'] ?? $item->name,
                    'model' => $line['model'] ?? null,
                ]),
            ]);
            $orderItem->save();
            $keepIds[] = $orderItem->id;
        }

        OrderItem::where('order_id', $order->id)->whereNotIn('id', $keepIds ?: [0])->delete();
    }

    private function resolveItem(int $ocProductId): ?Item
    {
        if ($ocProductId <= 0) {
            return null;
        }

        $item = Item::where('external_id', $ocProductId)->first();
        if ($item !== null) {
            return $item;
        }

        $this->products->syncById($ocProductId, 'order_product');

        return Item::where('external_id', $ocProductId)->first();
    }

    private function syncPayment(Order $order, array $ocOrder, float $total): void
    {
        OrderPayment::updateOrCreate(
            ['order_id' => $order->id],
            [
                'type' => 'order',
                'method' => 'cash_on_delivery',
                'currency' => (string) ($ocOrder['currency_code'] ?? 'USD'),
                'amount' => $total,
                'status' => $this->status($ocOrder) === 'delivered' ? 'paid' : 'unpaid',
                'meta_data' => json_encode(['opencart_payment_method' => $ocOrder['payment_method'] ?? null]),
            ]
        );
    }

    private function totals(array $ocTotals, float $fallbackTotal): array
    {
        $result = ['sub_total' => 0.0, 'tax' => 0.0, 'discount' => 0.0, 'total' => $fallbackTotal];

        foreach ($ocTotals as $row) {
            $code = (string) ($row['code'] ?? '');
            $value = (float) ($row['value'] ?? 0);

            match ($code) {
                'sub_total' => $result['sub_total'] = $value,
                'tax' => $result['tax'] += $value,
                'total' => $result['total'] = $value,
                default => $value < 0 ? $result['discount'] += abs($value) : null,
            };
        }

        if ($result['sub_total'] === 0.0) {
            $result['sub_total'] = $result['total'];
        }

        return $result;
    }

    private function status(array $ocOrder): string
    {
        $name = strtolower((string) ($ocOrder['order_status'] ?? ''));
        $statusId = (int) ($ocOrder['order_status_id'] ?? 0);

        if (str_contains($name, 'complete') || $statusId === 5) {
            return 'delivered';
        }
        if (str_contains($name, 'cancel') || str_contains($name, 'denied') || str_contains($name, 'refund') || in_array($statusId, [7, 8, 9, 10, 11, 14], true)) {
            return 'cancelled';
        }
        if (str_contains($name, 'shipped') || $statusId === 3) {
            return 'on_the_way';
        }
        if (str_contains($name, 'process') || $statusId === 2) {
            return 'confirmed';
        }

        return 'pending';
    }

    private function elapsed(int $start): int
    {
        return max(0, (int) (microtime(true) * 1000) - $start);
    }
}
