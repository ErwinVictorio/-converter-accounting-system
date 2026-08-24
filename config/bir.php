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
     * Expanded withholding tax (form 1604E).
     *
     * The source spreadsheet carries a tax-withheld amount per rate column but no
     * ATC code, while the DAT needs an exact one. Rate alone cannot decide it:
     * 5% is WC100 or WI010 and 10% is WC139 or WI516. Rate plus payee type does
     * decide it, and resolves every row in both reference samples.
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
         * Rate + payee type -> ATC, applied at import time.
         *
         * 15% is intentionally absent. No row in either reference file uses it, so
         * a 15% row is stored without a code and reported as an issue on the
         * Generate DAT screen instead of being given a guessed one.
         */
        'default_rate_codes' => [
            '1.00' => ['company' => 'WC158', 'individual' => 'WC158'],
            '2.00' => ['company' => 'WC160', 'individual' => 'WC160'],
            '5.00' => ['company' => 'WC100', 'individual' => 'WI010'],
            '10.00' => ['company' => 'WC139', 'individual' => 'WI516'],
        ],

        /*
         * Per-payee overrides, keyed by 9-digit TIN then by rate. These win over
         * default_rate_codes.
         *
         * Needed because a rate default can only be right for the common case:
         * WC139 covers brokers and agents, and every 10% company payee in the
         * reference files happens to be one, so a non-broker company withheld at
         * 10% belongs here.
         *
         *   '123456789' => ['10.00' => 'WC140'],
         */
        'payee_atc_overrides' => [],
    ],
];
