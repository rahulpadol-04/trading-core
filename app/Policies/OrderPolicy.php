<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class OrderPolicy
{
    public function view(User $user, Order $order): Response
    {
        return $this->owns($user, $order);
    }

    public function cancel(User $user, Order $order): Response
    {
        return $this->owns($user, $order);
    }

    /**
     * 404 rather than 403, so the API does not reveal that another trader's order exists.
     */
    private function owns(User $user, Order $order): Response
    {
        return $user->id === $order->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
