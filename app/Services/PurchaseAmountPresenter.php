<?php

namespace App\Services;

use App\Models\VatInput;

class PurchaseAmountPresenter
{
    private const VAT_RATE = 0.12;

    /**
     * @return array{display_services_amount: float, display_calculated_total: float, display_amounts_inferred: bool}
     */
    public function forRecord(VatInput $record): array
    {
        if ($record->uses_vat_bucket_amounts === true) {
            $purchaseLocalVat = $this->rawOrInferred($record->purchase_local_vat_amount, $record->purchase_local);
            $servicesVat = $this->rawOrInferred($record->services_vat_amount, $record->services);
            $othersVat = $this->rawOrInferred($record->others_vat_amount, $record->others);

            return [
                'display_services_amount' => $servicesVat,
                'display_calculated_total' => round(
                    ($purchaseLocalVat + $servicesVat + $othersVat) / self::VAT_RATE,
                    2
                ),
                'display_amounts_inferred' => $record->purchase_local_vat_amount === null
                    || $record->services_vat_amount === null
                    || $record->others_vat_amount === null,
            ];
        }

        if ($record->uses_vat_bucket_amounts === false) {
            return [
                'display_services_amount' => round((float) $record->services, 2),
                'display_calculated_total' => round(
                    (float) $record->purchase_local + (float) $record->services + (float) $record->others,
                    2
                ),
                'display_amounts_inferred' => false,
            ];
        }

        // Legacy rows predate the source-amount marker. Their current stored
        // Purchase fields remain the safest total, while Services is presented
        // as the 12% VAT amount expected by the existing business-facing form.
        return [
            'display_services_amount' => round((float) $record->services * self::VAT_RATE, 2),
            'display_calculated_total' => round(
                (float) $record->purchase_local + (float) $record->services + (float) $record->others,
                2
            ),
            'display_amounts_inferred' => true,
        ];
    }

    private function rawOrInferred(mixed $rawVatAmount, mixed $taxableBase): float
    {
        return $rawVatAmount === null
            ? round((float) $taxableBase * self::VAT_RATE, 2)
            : round((float) $rawVatAmount, 2);
    }
}
