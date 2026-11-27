<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\PayrollRunResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Modules\Hris\Enums\PayslipItemType;
use Modules\Hris\Models\Payslip;
use Modules\Hris\Models\PayslipItem;

/**
 * Read-only relation manager listing employee payslips with a native Filament slip detail modal.
 */
class PayslipsRelationManager extends RelationManager
{
    /**
     * The Eloquent relationship name on the parent PayrollRun model.
     */
    protected static string $relationship = 'payslips';

    /**
     * The title displayed above the payslips relation table.
     */
    protected static ?string $title = 'Rincian Slip Gaji Karyawan';

    /**
     * Prevent manual payslip creation outside PayrollCalculationService.
     */
    public function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent direct payslip editing.
     */
    public function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting computed payslip records.
     */
    public function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the employee payslips table schema and slip detail modal action.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_code')
                    ->label('NIP')
                    ->badge()
                    ->searchable(),
                TextColumn::make('employee.full_name')
                    ->label('Nama Karyawan')
                    ->searchable(),
                TextColumn::make('attendance_days')
                    ->label('Hadir')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('gross_salary')
                    ->label('Gaji Bruto')
                    ->money('IDR')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('pph21_amount')
                    ->label('PPh 21')
                    ->money('IDR')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('bpjs_employee_total')
                    ->label('BPJS Karyawan')
                    ->state(static fn (Payslip $record): string => bcadd((string) $record->bpjs_kesehatan_employee, (string) $record->bpjs_tk_employee, 4))
                    ->money('IDR')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('net_salary')
                    ->label('Take Home Pay')
                    ->money('IDR')
                    ->alignEnd()
                    ->weight('bold')
                    ->extraAttributes(['class' => 'tabular-nums']),
            ])
            ->recordActions([
                Action::make('viewPayslip')
                    ->label('Lihat Slip Gaji')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('info')
                    ->modalHeading(static fn (Payslip $record): string => 'Slip Gaji — '.($record->employee?->full_name ?? 'Karyawan'))
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(static fn (Payslip $record): View => view(
                        'hris::filament.modals.payslip-detail',
                        static::buildPayslipViewData($record),
                    )),
            ]);
    }

    /**
     * Prepare pre-formatted payslip breakdown data so the Blade modal stays 100% PHP-free.
     *
     * @return array<string, mixed>
     */
    public static function buildPayslipViewData(Payslip $payslip): array
    {
        $payslip->loadMissing([
            'employee.department' => static fn ($query) => $query->withoutGlobalScope('company'),
            'employee.position' => static fn ($query) => $query->withoutGlobalScope('company'),
            'payrollRun' => static fn ($query) => $query->withoutGlobalScope('company'),
            'items' => static fn ($query) => $query->withoutGlobalScope('company'),
        ]);

        $employee = $payslip->employee;
        $payrollRun = $payslip->payrollRun;

        $earnings = [];
        $deductions = [];
        $contributions = [];

        foreach ($payslip->items as $item) {
            /** @var PayslipItem $item */
            $row = [
                'name' => $item->name,
                'amount' => static::formatRupiah((string) $item->amount),
            ];

            match ($item->type) {
                PayslipItemType::Earning => $earnings[] = $row,
                PayslipItemType::Deduction => $deductions[] = $row,
                PayslipItemType::CompanyContribution => $contributions[] = $row,
            };
        }

        $totalEmployeeDeductions = bcadd(
            bcadd((string) $payslip->pph21_amount, (string) $payslip->other_deductions, 4),
            bcadd((string) $payslip->bpjs_kesehatan_employee, (string) $payslip->bpjs_tk_employee, 4),
            4,
        );

        $totalCompanyContribution = bcadd(
            (string) $payslip->bpjs_kesehatan_company,
            (string) $payslip->bpjs_tk_company,
            4,
        );

        return [
            'payrollNumber' => $payrollRun?->payroll_number ?? '-',
            'periodLabel' => $payrollRun !== null
                ? sprintf('%02d / %04d', $payrollRun->period_month, $payrollRun->period_year)
                : '-',
            'employeeCode' => $employee?->employee_code ?? '-',
            'employeeName' => $employee?->full_name ?? '-',
            'departmentName' => $employee?->department?->name ?? '-',
            'positionName' => $employee?->position?->name ?? '-',
            'ptkpStatus' => $employee?->ptkp_status?->value ?? '-',
            'attendanceDays' => $payslip->attendance_days,
            'absentDays' => $payslip->absent_days,
            'lateMinutes' => $payslip->late_minutes,
            'overtimeMinutes' => $payslip->overtime_minutes,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'contributions' => $contributions,
            'formattedGrossSalary' => static::formatRupiah((string) $payslip->gross_salary),
            'formattedTotalDeductions' => static::formatRupiah($totalEmployeeDeductions),
            'formattedTotalContributions' => static::formatRupiah($totalCompanyContribution),
            'formattedNetSalary' => static::formatRupiah((string) $payslip->net_salary),
        ];
    }

    /**
     * Format a decimal money string into Indonesian Rupiah display without floating-point conversion.
     */
    private static function formatRupiah(string $amount): string
    {
        $rounded = bcadd($amount, '0', 0);
        $isNegative = str_starts_with($rounded, '-');
        $digits = $isNegative ? substr($rounded, 1) : $rounded;
        $formattedDigits = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $digits);

        return ($isNegative ? '-Rp ' : 'Rp ').$formattedDigits;
    }
}
