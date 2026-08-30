<?php
namespace App\Policies;
use App\Models\Invoice;
use App\Models\User;
class InvoicePolicy
{
    public function update(User $user, Invoice $invoice): bool { return $user->is_admin; }
    public function delete(User $user, Invoice $invoice): bool { return $user->is_admin; }
}
