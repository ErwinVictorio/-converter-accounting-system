<?php

namespace App\Services\BIR;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds the BIR 1601EQ Quarterly Alphalist of Payees schedule.
 *
 * Shape taken from the BIR-generated reference file
 * Docs/Expanded/compareDatFile/original/00879197600000320251601EQ.DAT, which is what
 * the Alphalist Validation System accepts for 1601EQ/QAP:
 *
 *   HQAP,H1601EQ,{agent tin},{agent branch},"{agent name}",{m/Y},{rdo}  7 fields
 *   D1,1601EQ,{seq},{payee tin},{payee branch},{company},{last},
 *      {first},{middle},{m/Y},{atc},{rate},{income payment},
 *      {tax withheld}                                                  14 fields
 *   C1,1601EQ,{agent tin},{agent branch},{m/Y},{total income},
 *      {total withheld}                                                 7 fields
 *
 * The agent TIN, branch and period are header and control business only: detail rows
 * carry the payee's identity from field 3 onwards, and the period repeats after the
 * name columns. Writing the agent fields into the detail row instead -- which this
 * generator used to do -- pushed the payee TIN into the slot AVS reads as the agent
 * branch, so every line came back with "Detail Insufficient Column" and a cascade of
 * "Invalid Payees TIN" / "ATC is invalid" / "Amount ... is empty or zero" errors.
 * The period is a month-and-year, not a month-end date; the date form drew
 * "Specified Month End Date not the same!" on line 1.
 *
 * Two things differ from the RELIEF generators in this namespace and are
 * deliberate. Both were settled against the older sample payee data in
 * Docs/Expanded/0087919760000123120251604E.dat, referred to below as the sample file:
 *
 * 1. Internal runs of spaces are left alone. The RELIEF generators collapse
 *    them, but the sample file contains "PRINTSCAPE  PRINTING SERVICES AND
 *    BUSINESS SUPPLIE" with a double space, so collapsing here would rewrite
 *    stored data on the way out and make a byte-for-byte comparison impossible.
 *    Collapsing belongs in ExpandedWtaxImport, where messy spreadsheet text
 *    actually enters the system.
 * 2. Amounts always carry two decimals, including negatives. The sample file
 *    has -51600.00 / -2580.00 on a reversal row, so signs are passed through.
 *
 * One item in, one detail line out. The generator itself never merges anything --
 * grouping is ExpandedWtaxEntry::consolidate()'s job, and DatFileController calls it
 * before handing the collection over, so rows sharing reporting month, TIN, ATC and
 * rate arrive as a single item with their income payment and tax amount already
 * summed.
 *
 * That is a deliberate departure from the sample file, which lists PRUDENTIAL
 * GUARANTEE AND ASSURANCE INC twice under WC160 at 2%: the same month regenerated
 * from consolidated records produces **58 detail lines instead of 59**, with those
 * two becoming 221012.00 / 4420.24. The control total is unchanged at 241326.68,
 * because consolidation sums rather than filters. Sequence numbers renumber to close
 * the gap, so a byte-for-byte comparison against the reference file only holds for a
 * month with no mergeable duplicates.
 */
class ReliefExpandedWtaxDatGenerator
{
    private const FORM_TYPE = '1601EQ';

    /**
     * The longest company name in the reference file is exactly 50 characters.
     */
    private const COMPANY_NAME_LIMIT = 50;

    public function generate(array $company, Collection $transactions, Carbon $period): string
    {
        $period = $period->copy()->endOfMonth();

        $lines = [$this->header($company, $period)];

        $sequence = 0;
        foreach ($transactions as $transaction) {
            $lines[] = $this->detail($transaction, $period, ++$sequence);
        }

        $lines[] = $this->trailer($company, $transactions, $period);

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * The BIR 1601EQ validator expects TIN + branch + MMYYYY + 1601EQ.DAT.
     */
    public function filename(array $company, Carbon $period): string
    {
        return $this->birTin($this->companyTin($company))
            . $this->branch($company)
            . $period->copy()->endOfMonth()->format('mY')
            . '1601EQ.DAT';
    }

    private function header(array $company, Carbon $period): string
    {
        $fields = [
            'HQAP',
            'H' . self::FORM_TYPE,
            $this->birTin($this->companyTin($company)),
            $this->branch($company),
            $this->quote($this->birName($this->companyName($company))),
            $period->format('m/Y'),
            $this->rdoCode($company),
        ];

        if (count($fields) !== 7) {
            throw new RuntimeException('1601EQ QAP header must contain exactly 7 fields.');
        }

        return implode(',', $fields);
    }

    private function detail(array|object $transaction, Carbon $period, int $sequence): string
    {
        $row = $this->row($transaction);

        $fields = [
            'D1',
            self::FORM_TYPE,
            (string) $sequence,
            $this->birTin($this->text($row, 'payee_tin')),
            $this->payeeBranch($this->text($row, 'payee_branch_code')),
            $this->optionalName($this->birName($this->text($row, 'company_name'), self::COMPANY_NAME_LIMIT)),
            $this->optionalName($this->birName($this->text($row, 'last_name'))),
            $this->optionalName($this->birName($this->text($row, 'first_name'))),
            $this->optionalName($this->birName($this->text($row, 'middle_name'))),
            $period->format('m/Y'),
            strtoupper(trim($this->text($row, 'atc_code'))),
            $this->number($this->amount($row, 'tax_rate')),
            $this->number($this->amount($row, 'income_payment')),
            $this->number($this->amount($row, 'tax_withheld')),
        ];

        if (count($fields) !== 14) {
            throw new RuntimeException('1601EQ QAP detail must contain exactly 14 fields.');
        }

        return implode(',', $fields);
    }

    private function trailer(array $company, Collection $transactions, Carbon $period): string
    {
        $totalIncome = 0.0;
        $totalTax = 0.0;

        foreach ($transactions as $transaction) {
            $row = $this->row($transaction);
            $totalIncome += $this->amount($row, 'income_payment');
            $totalTax += $this->amount($row, 'tax_withheld');
        }

        $fields = [
            'C1',
            self::FORM_TYPE,
            $this->birTin($this->companyTin($company)),
            $this->branch($company),
            $period->format('m/Y'),
            $this->number(round($totalIncome, 2)),
            $this->number(round($totalTax, 2)),
        ];

        if (count($fields) !== 7) {
            throw new RuntimeException('1601EQ QAP control record must contain exactly 7 fields.');
        }

        return implode(',', $fields);
    }

    /**
     * Accepts models, plain arrays and stdClass rows, so the controller can hand
     * over an Eloquent collection while tests build fixtures by hand.
     */
    private function row(array|object $transaction): array
    {
        if (is_object($transaction) && method_exists($transaction, 'toBirExpandedRow')) {
            return $transaction->toBirExpandedRow();
        }

        return (array) $transaction;
    }

    private function companyTin(array $company): string
    {
        return (string) ($company['tin'] ?? '');
    }

    private function companyName(array $company): string
    {
        return (string) ($company['registered_name'] ?? $company['name'] ?? '');
    }

    private function rdoCode(array $company): string
    {
        return substr($this->digits((string) ($company['rdo_code'] ?? '')), 0, 3);
    }

    /**
     * The withholding agent's own branch code. Absent from config/bir.php, where
     * only head-office details are held, so it falls back to the head office.
     */
    private function branch(array $company): string
    {
        return $this->branchCode((string) ($company['branch_code'] ?? ''));
    }

    /**
     * Every payee in the reference file carries 0000, including payees whose TIN
     * has a non-zero branch suffix, so this never derives the branch from the TIN.
     */
    private function payeeBranch(string $value): string
    {
        return $this->branchCode($value);
    }

    private function branchCode(string $value): string
    {
        $digits = $this->digits($value);

        return $digits === '' ? '0000' : substr(str_pad($digits, 4, '0', STR_PAD_LEFT), 0, 4);
    }

    private function quote(?string $value): string
    {
        return '"' . str_replace('"', '""', (string) $value) . '"';
    }

    /**
     * Name fields are quoted when filled and left bare when empty, matching the
     * ",,,," runs the reference file uses for a company's unused name columns.
     */
    private function optionalName(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : $this->quote($value);
    }

    /**
     * Shapes text for the DAT without collapsing internal spacing -- see the
     * class docblock.
     *
     * The ampersand expansion and comma removal are a last line of defence:
     * BirExpandedWtaxRowValidator rejects both characters, so a stored row
     * carrying one never reaches generation. Dropping the ampersand outright
     * would join two words, so it is expanded even though that can leave a
     * double space behind.
     */
    private function birName(?string $value, ?int $limit = null): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', ' AND ', $value);
        $value = str_replace(',', ' ', $value);
        $value = preg_replace('/[^A-Z0-9 ]/', ' ', $value);
        $value = rtrim(ltrim($value));

        if ($limit !== null) {
            $value = rtrim(substr($value, 0, $limit));
        }

        return $value;
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function text(array $row, string $key): string
    {
        return (string) ($row[$key] ?? '');
    }

    private function amount(array $row, string $key): float
    {
        $value = $row[$key] ?? null;

        if ($value === null || trim((string) $value) === '') {
            return 0.0;
        }

        return round((float) preg_replace('/[^\d.-]/', '', (string) $value), 2);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    private function birTin(string $value): string
    {
        return substr($this->digits($value), 0, 9);
    }
}
