<?php

return [
    'companies' => [
        '008791976' => [
            'tin' => '008791976',
            'name' => 'FORTRESS STEEL INC.',
            'registered_name' => 'FORTRESS STEEL INC.',
            'address1' => 'LOT 433 J.P RIZAL NANGKA',
            'address2' => ' MARIKINA 1808',
            'rdo_code' => '045',
        ],
    ],

    /*
     * Fixed vendor identity applied to manual Importation entries when they are
     * synced into vat_inputs, so imported purchases pass the BIR purchase DAT
     * validator (which requires a valid 9-digit TIN and a non-empty address).
     *
     * The importation supplier name is still taken from each entry; only the
     * TIN and the fallback address line 2 come from here. Adjust the TIN to the
     * value your BIR filing uses for importations.
     */
    'importation' => [
        'tin' => '000-472-103-000', // TODO: confirm the Bureau of Customs TIN used in your BIR filing.
        'address2' => 'PORT AREA MANILA',
    ],

    /*
     * Expanded withholding tax (form 1601EQ/QAP).
     *
     * The BIR-format workbook (Docs/Expanded/BIR_Excel_Guide_Analysis.md) carries an
     * ATC column of its own, so the code is read from the file rather than worked out
     * here. A blank ATC cell is reported and blocks the DAT: only the taxpayer knows
     * which schedule a payment belongs on, and 10% alone cannot choose between WC139
     * and WI516.
     */
    'expanded_wtax' => [
        /*
         * Codes the DAT validator accepts, with the rate each is filed at. All of
         * these were read off the reference DAT. By BIR convention WC... codes are
         * used for juridical payees and WI... for individuals, which is why one
         * rate can have two entries. Confirm any code you add here against your
         * own registration before filing it.
         *
         * 'payee_type' restricts a code to one kind of payee. WC158 and WC160 carry
         * no restriction on purpose: the reference DAT files individual payees under
         * both (three rows at 1%, two at 2%), so restricting them would reject a
         * file the BIR already accepted.
         *
         * This list is now the gate on an uploaded ATC, which makes it the one place
         * to extend when Accounting starts filing a schedule this system has not seen
         * -- a 15% row, or a WI code beyond WI010 and WI516. An ATC that is missing
         * here is rejected with the code named, so the message says what to add.
         */
        'allowed_atc_codes' => [
            'WC158' => ['rate' => 1.00],
            'WC160' => ['rate' => 2.00],
            'WC100' => ['rate' => 5.00, 'payee_type' => 'company'],
            'WI010' => ['rate' => 5.00, 'payee_type' => 'individual'],
            'WC139' => ['rate' => 10.00, 'payee_type' => 'company'],
            'WI516' => ['rate' => 10.00, 'payee_type' => 'individual'],
        ],

        /*
         * DEPRECATED, and read by nothing since the BIR-format upload landed.
         *
         * The in-house workbook this module first read had a tax-withheld column per
         * rate and no ATC column, so the code had to be derived from rate + payee
         * type. The BIR format states the ATC outright, and deriving one would put a
         * payment on a schedule nobody chose.
         *
         * Kept rather than deleted because the two blocks record which code each rate
         * was filed under before the change, which is the only place that history is
         * written down. Delete both once no month imported through the old workbook
         * still needs explaining.
         */
        'default_rate_codes' => [
            '1.00' => ['company' => 'WC158', 'individual' => 'WC158'],
            '2.00' => ['company' => 'WC160', 'individual' => 'WC160'],
            '5.00' => ['company' => 'WC100', 'individual' => 'WI010'],
            '10.00' => ['company' => 'WC139', 'individual' => 'WI516'],
        ],

        /*
         * DEPRECATED alongside default_rate_codes, and empty in any case. A per-payee
         * override existed to settle what a rate default could not -- WC139 covers
         * brokers and agents, so a non-broker company withheld at 10% belonged here.
         * The workbook's own ATC column settles it now.
         *
         *   '123456789' => ['10.00' => 'WC140'],
         */
        'payee_atc_overrides' => [],
    ],
];
