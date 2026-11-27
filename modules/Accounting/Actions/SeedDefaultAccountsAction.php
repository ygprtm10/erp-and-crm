<?php

declare(strict_types=1);

namespace Modules\Accounting\Actions;

use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;
use Modules\Accounting\Models\Account;

/**
 * Idempotently provisions standard Indonesian UMKM Chart of Accounts for a company.
 */
class SeedDefaultAccountsAction
{
    /**
     * Create or update the standard system Chart of Accounts for the given company.
     *
     * @return Collection<int, Account>
     */
    public function execute(Company $company): Collection
    {
        $definitions = [
            ['code' => '1101', 'name' => 'Kas', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1102', 'name' => 'Bank', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1103', 'name' => 'Saldo Dompet Platform', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1104', 'name' => 'Piutang Usaha', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '1201', 'name' => 'Persediaan Barang', 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit],
            ['code' => '2101', 'name' => 'Utang Usaha', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit],
            ['code' => '2102', 'name' => 'Utang PPh 21', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit],
            ['code' => '2103', 'name' => 'Utang BPJS', 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit],
            ['code' => '3101', 'name' => 'Modal Pemilik', 'type' => AccountType::Equity, 'normal_balance' => NormalBalance::Credit],
            ['code' => '4101', 'name' => 'Pendapatan Penjualan', 'type' => AccountType::Revenue, 'normal_balance' => NormalBalance::Credit],
            ['code' => '5101', 'name' => 'Harga Pokok Penjualan (HPP)', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '6101', 'name' => 'Beban Gaji', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
            ['code' => '6102', 'name' => 'Beban Operasional', 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit],
        ];

        return DB::transaction(function () use ($company, $definitions): Collection {
            $accounts = new Collection;

            foreach ($definitions as $definition) {
                $account = Account::query()
                    ->withoutGlobalScope('company')
                    ->updateOrCreate(
                        [
                            'company_id' => $company->getKey(),
                            'code' => $definition['code'],
                        ],
                        [
                            'name' => $definition['name'],
                            'type' => $definition['type'],
                            'normal_balance' => $definition['normal_balance'],
                            'is_system' => true,
                        ],
                    );

                $accounts->push($account);
            }

            return $accounts;
        });
    }
}
