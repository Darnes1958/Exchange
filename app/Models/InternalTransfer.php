<?php

namespace App\Models;

use App\Enums\IncomingCommission;
use App\Enums\InternalTransferDirection;
use App\Enums\InternalTransferSettlement;
use App\Enums\InternalTransferStatus;
use App\Enums\OutgoingCommission;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalTransfer extends CompanyModel
{
    protected $fillable = [
        'work_day_id',
        'transfer_office_id',
        'cashier_vault_id',
        'direction',
        'status',
        'reference',
        'sender_name',
        'sender_phone',
        'sender_identity',
        'amount',
        'commission',
        'commission_type',
        'cash_amount',
        'office_amount',
        'profit',
        'settlement',
        'bank_account_id',
        'recipient_name',
        'recipient_identity',
        'recipient_phone',
        'user_id',
        'user_name',
    ];

    protected function casts(): array
    {
        return [
            'direction' => InternalTransferDirection::class,
            'status' => InternalTransferStatus::class,
            'settlement' => InternalTransferSettlement::class,
            'amount' => 'decimal:3',
            'commission' => 'decimal:3',
            'cash_amount' => 'decimal:3',
            'office_amount' => 'decimal:3',
            'profit' => 'decimal:3',
        ];
    }

    public function commissionLabel(): string
    {
        $type = (string) $this->commission_type;

        if ($this->direction === InternalTransferDirection::Incoming) {
            return IncomingCommission::tryFrom($type)?->label() ?? $type;
        }

        return OutgoingCommission::tryFrom($type)?->label() ?? $type;
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(TransferOffice::class, 'transfer_office_id');
    }

    public function workDay(): BelongsTo
    {
        return $this->belongsTo(WorkDay::class);
    }

    public function cashierVault(): BelongsTo
    {
        return $this->belongsTo(CashierVault::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
