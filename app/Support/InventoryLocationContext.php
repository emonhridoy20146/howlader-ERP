<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Contracts\Session\Session;

class InventoryLocationContext
{
    public const SESSION_KEY = 'inventory.context_location_id';

    public function __construct(private Session $session) {}

    public function currentId(): ?int
    {
        if (! $this->session->has(self::SESSION_KEY)) {
            return null;
        }

        $id = (int) $this->session->get(self::SESSION_KEY);
        if ($id < 1 || ! Location::query()->whereKey($id)->where('is_active', true)->exists()) {
            $this->session->forget(self::SESSION_KEY);

            return null;
        }

        $user = auth()->user();
        if ($user && ! in_array($id, $user->accessibleLocationIds(), true)) {
            $this->session->forget(self::SESSION_KEY);

            return null;
        }

        return $id;
    }

    public function set(?int $locationId): void
    {
        if ($locationId === null || $locationId < 1) {
            $this->session->forget(self::SESSION_KEY);

            return;
        }

        if (! Location::query()->whereKey($locationId)->where('is_active', true)->exists()) {
            return;
        }

        $user = auth()->user();
        if ($user && ! in_array($locationId, $user->accessibleLocationIds(), true)) {
            return;
        }

        $this->session->put(self::SESSION_KEY, $locationId);
    }
}
