<?php

namespace App\Services\Letsync;

use App\Models\User;

class CustomerSyncService
{
    public function __construct(
        private readonly OpenCartReader $reader,
        private readonly SyncLogger $logger,
    ) {}

    public function syncById(int $ocCustomerId, string $event = 'sync'): void
    {
        $start = (int) (microtime(true) * 1000);

        $data = $this->reader->customer($ocCustomerId);
        if ($data === null) {
            $this->logger->skipped('customer', $event, $ocCustomerId, 'Customer not found in OpenCart');

            return;
        }

        $user = $this->upsert($data['customer']);
        $this->logger->success('customer', $event, $ocCustomerId, null, $this->elapsed($start), "Customer synced (user {$user->id})");
    }

    public function deleteByExternalId(int $ocCustomerId, string $event = 'delete_customer'): void
    {
        User::where('external_id', $ocCustomerId)->get()->each->delete();
        $this->logger->success('customer', $event, $ocCustomerId, null, 0, 'Customer deleted');
    }

    public function upsert(array $customer): User
    {
        $externalId = (int) $customer['customer_id'];

        $user = User::where('external_id', $externalId)->first() ?? new User();
        $user->external_id = $externalId;
        $user->fill([
            'first_name' => $this->clean($customer['firstname'] ?? ''),
            'last_name' => $this->clean($customer['lastname'] ?? ''),
            'email' => $this->uniqueEmail($customer, $externalId),
            'phone' => $this->clean($customer['telephone'] ?? '') ?: null,
            'is_active' => (int) ($customer['status'] ?? 1) === 1,
            'is_verified' => (int) ($customer['status'] ?? 1) === 1,
            'user_type' => 'customer',
            'registration_type' => 'manual',
            'gender' => 'other',
        ]);
        $user->save();

        return $user;
    }

    private function uniqueEmail(array $customer, int $externalId): ?string
    {
        $email = strtolower(trim((string) ($customer['email'] ?? '')));
        if ($email === '') {
            return null;
        }

        $clash = User::where('email', $email)->where('external_id', '!=', $externalId)->exists();

        return $clash ? null : $email;
    }

    private function clean(string $value): string
    {
        return trim(strip_tags(html_entity_decode($value)));
    }

    private function elapsed(int $start): int
    {
        return max(0, (int) (microtime(true) * 1000) - $start);
    }
}
