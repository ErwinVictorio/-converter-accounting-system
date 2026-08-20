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
];
