<?php

namespace App\Http\Controllers;

use App\Models\PdfRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;
use setasign\Fpdi\Tcpdf\Fpdi;

class PdfRecordController extends Controller
{
    /** All coordinates are "pixels" of a page rendered 893px wide (measured from csf_cf2.pdf). */
    private const REF_W = 893;

    /** When the patient IS the member: copy member PIN into the dependent PIN boxes? */
    private const FILL_DEPENDENT_PIN_WHEN_MEMBER = true;

    /** Fixed values used when the field is left empty. */
    private const DEFAULTS = [
        'professional_accreditation' => '1501-1638387-1',
        'professional_name'          => 'GLADYS LOURDES B. BENGCO, M.D',
        'first_case_rate'            => '90375',
        'hci_rep_name'               => 'MAR A. PANLILIO',
        'hci_rep_designation'        => 'ABTC NURSE',
        'facility_pan'               => 'B03036538',
        'facility_name'              => 'CONCEPCION RURAL HEALTH UNIT I ANIMAL BITE TREATMENT CENTER',
        'facility_street'            => 'L. CORTEZ STREET SAN NICOLAS POBLACION',
        'facility_city'              => 'CONCEPCION',
        'facility_province'          => 'TARLAC',
    ];

    /** Font size (pt) of the patient name on CF-2 Part II-1. */
    private const CF2_NAME_SIZE = 7;

    private float $k = 1;

    private function listing()
    {
        return PdfRecord::query()->latest()->limit(100)
            ->get(['id', 'record_no', 'patient_name', 'created_at']);
    }

    public function index()
    {
        return Inertia::render('PdfRecords/Index', [
            'records' => $this->listing(),
            'record'  => null,
        ]);
    }

    public function edit(PdfRecord $record)
    {
        return Inertia::render('PdfRecords/Index', [
            'records' => $this->listing(),
            'record'  => $record,
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'patient_name' => ['nullable', 'string', 'max:255'],
            'form_data'    => ['required', 'array'],
        ]);

        $fd = $this->resolve($data['form_data']);
        $data['patient_name'] = $data['patient_name']
            ?: trim(($fd['patient_last_name'] ?? '') . ', ' . ($fd['patient_first_name'] ?? ''), ' ,') ?: null;

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $record = PdfRecord::create($data);
        $record->update([
            'record_no' => 'CSF-' . now()->format('Ym') . '-' . str_pad((string) $record->id, 5, '0', STR_PAD_LEFT),
        ]);

        return redirect()->route('pdf-records.edit', $record);
    }

    public function update(Request $request, PdfRecord $record)
    {
        $record->update($this->validated($request));

        return redirect()->route('pdf-records.edit', $record);
    }

    /** Apply fixed defaults and the "patient is the member" rule. */
    private function resolve(array $d): array
    {
        foreach (self::DEFAULTS as $key => $value) {
            if (blank($d[$key] ?? null)) {
                $d[$key] = $value;
            }
        }

        // Signer is automatic: a representative name means the representative signs,
        // otherwise the patient signs (and the patient is the member when relationship = Member).
        $hasRep = ($d['has_representative'] ?? 'no') === 'yes' && filled($d['representative_name'] ?? null);
        $d['part3_signer'] = $hasRep ? 'representative' : 'patient';
        if (!$hasRep) {
            // ignore any stale representative data
            foreach (['representative_name', 'representative_relationship', 'representative_relationship_other',
                      'representative_reason', 'representative_reason_other'] as $f) {
                $d[$f] = '';
            }
        }

        if (strtolower($d['relationship_to_member'] ?? '') === 'member') {
            foreach (['last_name', 'first_name', 'extension', 'middle_name', 'birthdate'] as $f) {
                $d["patient_{$f}"] = $d["member_{$f}"] ?? '';
            }
            $d['dependent_pin'] = self::FILL_DEPENDENT_PIN_WHEN_MEMBER ? ($d['member_pin'] ?? '') : '';
        }

        return $d;
    }

    public function preview(Request $request, PdfRecord $record)
    {
        $template = collect([
            storage_path('app/pdf-templates/csf_cf2.pdf'),
            storage_path('app/pdf-template/csf_cf2.pdf'),
            storage_path('app/private/pdf-templates/csf_cf2.pdf'),
        ])->first(fn ($p) => is_file($p));

        abort_unless($template, 404, 'The PDF template was not found in storage/app/pdf-templates.');

        $d = $this->resolve($record->form_data ?? []);

        $pdf = new Fpdi();
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setCellMargins(0, 0, 0, 0);

        $pageCount = $pdf->setSourceFile($template);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tpl  = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tpl);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            $this->k = $size['width'] / self::REF_W;
            $pdf->SetTextColor(0, 0, 0);

            if ($request->boolean('grid')) {
                $this->grid($pdf, $size);
            }

            match ($pageNo) {
                1 => $this->pageCsf($pdf, $d),
                2 => $this->pageCf2Front($pdf, $d),
                3 => $this->pageCf2Back($pdf, $d),
                default => null,
            };
        }

        $content = $pdf->Output('csf-cf2-' . $record->id . '.pdf', 'S');

        return response($content, 200, [
            'Content-Type'           => 'application/pdf',
            'Content-Disposition'    => 'inline; filename="csf-cf2-' . $record->id . '.pdf"',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /* ---------------------------------------------------------------
     |  PAGE MAPS  (x = left edge, y = vertical CENTER of the text, px @ 893 wide)
     |  Measured from the PDF's own boxes/labels. Use ?grid=1 to verify.
     * ------------------------------------------------------------- */

    /** Box left-edges (all boxes are 18px wide). */
    private const PIN_MEMBER = [[379, 397], [426, 444, 462, 480, 497, 515, 533, 551, 569], [597]];
    private const PIN_DEP    = [[395, 413], [442, 460, 478, 496, 514, 532, 550, 568, 586], [614]];

    private const DOB_MEMBER  = ['m' => [669, 686], 'd' => [715, 733], 'y' => [761, 779, 797, 815]];
    private const ADMITTED    = ['m' => [131, 149], 'd' => [177, 195], 'y' => [223, 241, 259, 277]];
    private const DISCHARGED  = ['m' => [428, 446], 'd' => [474, 492], 'y' => [520, 538, 556, 574]];
    private const DOB_PATIENT = ['m' => [669, 686], 'd' => [715, 733], 'y' => [761, 779, 797, 815]];
    private const SIGNED      = ['m' => [682, 700], 'd' => [728, 746], 'y' => [775, 793, 811, 829]];

    private const SIGNED_I9_MEMBER = ['m' => [180, 198], 'd' => [226, 244], 'y' => [272, 290, 308, 326]];
    private const SIGNED_I9_REP    = ['m' => [575, 593], 'd' => [622, 640], 'y' => [668, 686, 704, 722]];
    private const SIGNED_III       = ['m' => [583, 601], 'd' => [630, 648], 'y' => [676, 694, 712, 730]];

    private const ACCRED = [[123, 141, 159, 177], [206, 224, 242, 260, 277, 295, 313], [342]];

    private function pageCsf(Fpdi $pdf, array $d): void
    {
        // ---------- PART I ----------
        $this->pin($pdf, self::PIN_MEMBER, 247, $d['member_pin'] ?? '');

        // 2. Member name
        $this->txt($pdf, 40,  283, $d['member_last_name'] ?? '', 10, 150);
        $this->txt($pdf, 201, 283, $d['member_first_name'] ?? '', 10, 150);
        $this->txt($pdf, 361, 283, $d['member_extension'] ?? '', 10, 150);
        $this->txt($pdf, 522, 283, $d['member_middle_name'] ?? '', 10, 140);

        // 3. Member birthdate
        $this->date($pdf, self::DOB_MEMBER, 292, $d['member_birthdate'] ?? '');

        // 4. Dependent PIN (blank unless filled by resolve())
        $this->pin($pdf, self::PIN_DEP, 343, $d['dependent_pin'] ?? '');

        // 5. Patient name
        $this->txt($pdf, 40,  379, $d['patient_last_name'] ?? '', 10, 150);
        $this->txt($pdf, 201, 379, $d['patient_first_name'] ?? '', 10, 150);
        $this->txt($pdf, 361, 379, $d['patient_extension'] ?? '', 10, 150);
        $this->txt($pdf, 522, 379, $d['patient_middle_name'] ?? '', 10, 140);

        // 6. Relationship (no box when the patient is the member)
        match (strtolower($d['relationship_to_member'] ?? '')) {
            'child'  => $this->check($pdf, 663, 385),
            'parent' => $this->check($pdf, 724, 385),
            'spouse' => $this->check($pdf, 790, 385),
            default  => null,
        };

        // 7. Confinement period / 8. Patient DOB
        $this->date($pdf, self::ADMITTED, 457, $d['date_admitted'] ?? '');
        $this->date($pdf, self::DISCHARGED, 457, $d['date_discharged'] ?? '', 11, true);
        $this->date($pdf, self::DOB_PATIENT, 457, $d['patient_birthdate'] ?? '');

        // 9. Certification of member — signatory mirrors Part III (member / representative)
        $this->partI9($pdf, $d);

        // ---------- PART II (employer) : intentionally left blank ----------

        // ---------- PART III : consent / representative of the patient ----------
        $this->partIII($pdf, $d);

        // ---------- PART IV : health care professional (date = admission, day blank) ----------
        $this->cells($pdf, self::ACCRED[0], 1115, substr($this->digits($d['professional_accreditation']), 0, 4), 11);
        $this->cells($pdf, self::ACCRED[1], 1115, substr($this->digits($d['professional_accreditation']), 4, 7), 11);
        $this->cells($pdf, self::ACCRED[2], 1115, substr($this->digits($d['professional_accreditation']), 11, 1), 11);
        $this->txt($pdf, 385, 1112, $d['professional_name'], 10, 225);
        $this->signedDate($pdf, 1115, $d['date_admitted'] ?? '');

        // ---------- PART V : provider ----------
        $this->txt($pdf, 415, 1252, $d['first_case_rate'] ?? '', 11, 140);
        $this->txt($pdf, 680, 1255, $d['second_case_rate'] ?? '', 11, 170);
        $this->txt($pdf, 40,  1293, $d['hci_rep_name'], 10, 340);
        $this->txt($pdf, 400, 1293, $d['hci_rep_designation'], 10, 200);
        $this->signedDate($pdf, 1295, $d['date_admitted'] ?? '');
    }

    /** Part I item 9: same signer choice and representative details as Part III. */
    private function partI9(Fpdi $pdf, array $d): void
    {
        $byRep = ($d['part3_signer'] ?? 'patient') === 'representative';

        if (!$byRep) {
            $this->check($pdf, 45, 657);                         // Member box
            $this->txt($pdf, 90, 530, $this->fullName($d, 'member'), 10, 280);
            $this->signedDate($pdf, 568, $d['date_admitted'] ?? '', self::SIGNED_I9_MEMBER);
            return;
        }

        $this->check($pdf, 116, 657);                            // Representative box
        $this->txt($pdf, 468, 530, $d['representative_name'] ?? '', 10, 380);
        $this->signedDate($pdf, 568, $d['date_admitted'] ?? '', self::SIGNED_I9_REP);

        switch (strtolower($d['representative_relationship'] ?? '')) {
            case 'spouse':  $this->check($pdf, 588, 599); break;
            case 'child':   $this->check($pdf, 655, 599); break;
            case 'parent':  $this->check($pdf, 712, 599); break;
            case 'sibling': $this->check($pdf, 588, 619); break;
            case 'others':
                $this->check($pdf, 655, 619);
                $this->txt($pdf, 740, 617, $d['representative_relationship_other'] ?? '', 9, 105);
                break;
        }

        if (($d['representative_reason'] ?? '') === 'incapacitated') {
            $this->check($pdf, 588, 641);
        } elseif (($d['representative_reason'] ?? '') === 'other') {
            $this->check($pdf, 588, 662);
            $this->txt($pdf, 672, 666, $d['representative_reason_other'] ?? '', 9, 185);
        }
    }

    private function partIII(Fpdi $pdf, array $d): void
    {
        $byRep = ($d['part3_signer'] ?? 'patient') === 'representative';
        $this->signedDate($pdf, 965, $d['date_admitted'] ?? '', self::SIGNED_III);

        if ($byRep) {
            $this->check($pdf, 111, 1058);                       // Representative box
            $this->txt($pdf, 95, 958, $d['representative_name'] ?? '', 10, 410);

            switch (strtolower($d['representative_relationship'] ?? '')) {
                case 'spouse':  $this->check($pdf, 588, 1001); break;
                case 'child':   $this->check($pdf, 655, 1001); break;
                case 'parent':  $this->check($pdf, 712, 1001); break;
                case 'sibling': $this->check($pdf, 588, 1022); break;
                case 'others':
                    $this->check($pdf, 655, 1022);
                    $this->txt($pdf, 740, 1021, $d['representative_relationship_other'] ?? '', 9, 105);
                    break;
            }

            if (($d['representative_reason'] ?? '') === 'incapacitated') {
                $this->check($pdf, 588, 1045);
            } elseif (($d['representative_reason'] ?? '') === 'other') {
                $this->check($pdf, 588, 1066);
                $this->txt($pdf, 672, 1066, $d['representative_reason_other'] ?? '', 9, 185);
            }
        } else {
            $this->check($pdf, 45, 1058);                        // Patient box
            $this->txt($pdf, 95, 962, $this->fullName($d, 'patient'), 10, 410);
        }
    }

    private function pageCf2Front(Fpdi $pdf, array $d): void
    {
        // Part I
        $pan = preg_replace('/[^A-Za-z0-9]/', '', (string) ($d['facility_pan'] ?? ''));
        $this->cells($pdf, [482, 500, 518, 536, 554, 571, 589, 607, 625], 290, $pan, 11);
        $this->txt($pdf, 258, 315, $d['facility_name'] ?? '', 10, 590);
        $this->txt($pdf, 122, 339, $d['facility_street'] ?? '', 10, 300);
        $this->txt($pdf, 435, 339, $d['facility_city'] ?? '', 10, 215);
        $this->txt($pdf, 662, 339, $d['facility_province'] ?? '', 10, 200);

        // Part II-1 patient name
        $this->txt($pdf, 185, 406, $d['patient_last_name'] ?? '', self::CF2_NAME_SIZE, 170);
        $this->txt($pdf, 363, 406, $d['patient_first_name'] ?? '', self::CF2_NAME_SIZE, 175);
        $this->txt($pdf, 552, 406, $d['patient_extension'] ?? '', self::CF2_NAME_SIZE, 125);
        $this->txt($pdf, 689, 406, $d['patient_middle_name'] ?? '', self::CF2_NAME_SIZE, 170);

        // Part II-2 referred by another HCI: always NO
        $this->check($pdf, 56, 490);

        // Part II-3 confinement period (admitted = full date; discharged = no day)
        $cf2Date = ['m' => [289, 307], 'd' => [335, 353], 'y' => [381, 399, 417, 435]];
        $this->date($pdf, $cf2Date, 534, $d['date_admitted'] ?? '', 10);
        $this->date($pdf, $cf2Date, 558, $d['date_discharged'] ?? '', 10, true);

        // Time admitted / discharged, AM-PM from the entered time
        $this->time($pdf, 535, 696, 758, $d['time_admitted'] ?? '');
        $this->time($pdf, 559, 696, 758, $d['time_discharged'] ?? '', 557);

        // Part II-4 patient disposition: always IMPROVED
        $this->check($pdf, 56, 604);

        // Part II-5 accommodation: always NON-PRIVATE
        $this->check($pdf, 299, 692);

        // 6. Admission diagnosis (two lines under the heading)
        $this->wrap($pdf, 52, [736, 752], $d['admission_diagnosis'] ?? '', 800, 10);

        // 7. Discharge diagnosis table — row a
        $this->wrap($pdf, 64, [813, 833, 852], $d['discharge_diagnosis'] ?? '', 118, 8.5);
        $this->txt($pdf, 184, 813, $d['icd10_code'] ?? '', 9, 82);
        $this->txt($pdf, 295, 813, $d['procedure'] ?? '', 9, 178);
        $this->txt($pdf, 478, 813, $d['rvs_code'] ?? '', 9, 95);
        $this->txt($pdf, 580, 813, $this->mdy($d['procedure_date'] ?? ''), 9, 90);

        // 9. Benefits
        $this->txt($pdf, 255, 1331, $d['first_case_rate'] ?? '', 11, 240);
        $this->txt($pdf, 640, 1331, $d['second_case_rate'] ?? '', 11, 210);
    }

    private function pageCf2Back(Fpdi $pdf, array $d): void
    {
        // Part III-A "Total Actual Charges" column (centred at ~642, right-aligned at 805)
        $this->money($pdf, 805, 514, $d['hci_charges'] ?? '');
        $this->money($pdf, 805, 536, $d['professional_fees'] ?? '');
        $this->money($pdf, 805, 557, $d['grand_total'] ?? '');
    }

    /* ----------------------------- helpers ----------------------------- */

    /** Place one cell of text, vertically centred on $ycMm. */
    private function put(Fpdi $pdf, float $xMm, float $ycMm, float $wMm, string $text, string $align = 'L'): void
    {
        $h = 6;
        $pdf->SetXY($xMm, $ycMm - $h / 2);
        $pdf->Cell($wMm, $h, $text, 0, 0, $align, false, '', 0, false, 'T', 'M');
    }

    /** Uppercase text, shrunk to fit $maxW (px) if given. */
    private function txt(Fpdi $pdf, float $x, float $yc, ?string $text, float $size = 10, ?float $maxW = null): void
    {
        $text = mb_strtoupper(trim((string) $text));
        if ($text === '') return;

        $pdf->SetFont('helvetica', '', $size);
        if ($maxW) {
            $limit = $maxW * $this->k;
            while ($size > 6 && $pdf->GetStringWidth($text) > $limit) {
                $size -= 0.5;
                $pdf->SetFont('helvetica', '', $size);
            }
        }

        $this->put($pdf, $x * $this->k, $yc * $this->k, ($maxW ?? 400) * $this->k + 5, $text, 'L');
    }

    /** Word-wrap across fixed baselines (one y per line). */
    private function wrap(Fpdi $pdf, float $x, array $ys, ?string $text, float $maxW, float $size): void
    {
        $text = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $text)));
        if ($text === '') return;

        $pdf->SetFont('helvetica', '', $size);
        $limit = $maxW * $this->k;
        $lines = [];
        $cur   = '';
        foreach (explode(' ', $text) as $word) {
            $try = $cur === '' ? $word : "$cur $word";
            if ($cur === '' || $pdf->GetStringWidth($try) <= $limit) {
                $cur = $try;
            } else {
                $lines[] = $cur;
                $cur = $word;
            }
        }
        $lines[] = $cur;

        foreach (array_slice($lines, 0, count($ys)) as $i => $line) {
            $this->put($pdf, $x * $this->k, $ys[$i] * $this->k, $maxW * $this->k + 5, $line, 'L');
        }
    }

    private function money(Fpdi $pdf, float $xRight, float $yc, ?string $value): void
    {
        $value = trim((string) $value);
        if ($value === '') return;
        $clean = str_replace(',', '', $value);
        if (is_numeric($clean)) $value = number_format((float) $clean, 2);

        $pdf->SetFont('helvetica', '', 11);
        $w = 200 * $this->k;
        $this->put($pdf, $xRight * $this->k - $w, $yc * $this->k, $w, $value, 'R');
    }

    /** One character centred in each 18px box ($xs = left edges). */
    private function cells(Fpdi $pdf, array $xs, float $yc, ?string $text, float $size = 11, float $w = 18): void
    {
        if ($text === null || $text === '') return;
        $pdf->SetFont('helvetica', '', $size);
        foreach (mb_str_split(mb_strtoupper($text)) as $i => $ch) {
            if (!isset($xs[$i])) break;
            $this->put($pdf, $xs[$i] * $this->k, $yc * $this->k, $w * $this->k, $ch, 'C');
        }
    }

    private function digits(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    /** $groups = [2-box, 9-box, 1-box]; needs 12 digits. */
    private function pin(Fpdi $pdf, array $groups, float $yc, ?string $pin): void
    {
        $digits = $this->digits($pin);
        if (strlen($digits) < 12) return;
        $this->cells($pdf, $groups[0], $yc, substr($digits, 0, 2));
        $this->cells($pdf, $groups[1], $yc, substr($digits, 2, 9));
        $this->cells($pdf, $groups[2], $yc, substr($digits, 11, 1));
    }

    /** $cells = ['m'=>[..],'d'=>[..],'y'=>[..]]; value is Y-m-d from <input type=date>. */
    private function date(Fpdi $pdf, array $cells, float $yc, ?string $value, float $size = 11, bool $blankDay = false): void
    {
        if (!$value || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) return;
        $this->cells($pdf, $cells['m'], $yc, $m[2], $size);
        if (!$blankDay) {
            $this->cells($pdf, $cells['d'], $yc, $m[3], $size);
        }
        $this->cells($pdf, $cells['y'], $yc, $m[1], $size);
    }

    /** "Date signed" = admission month + year, DAY ALWAYS BLANK. */
    private function signedDate(Fpdi $pdf, float $yc, ?string $admitted, ?array $cells = null): void
    {
        $cells ??= self::SIGNED;
        if (!$admitted || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $admitted, $m)) return;
        $this->cells($pdf, $cells['m'], $yc, $m[2]);
        $this->cells($pdf, $cells['y'], $yc, $m[1]);
    }

    /** $value "HH:MM" (24h from <input type=time>) → hour/min cells + AM/PM check. */
    private function time(Fpdi $pdf, float $yc, float $amX, float $pmX, ?string $value, ?float $ampmY = null): void
    {
        if (!$value || !preg_match('/^(\d{1,2}):(\d{2})/', $value, $m)) return;

        $h24  = (int) $m[1];
        $ampm = $h24 >= 12 ? 'PM' : 'AM';
        $h12  = $h24 % 12 ?: 12;

        $this->cells($pdf, [571, 589], $yc, str_pad((string) $h12, 2, '0', STR_PAD_LEFT), 10);
        $this->cells($pdf, [617, 635], $yc, $m[2], 10);
        $this->check($pdf, $ampm === 'AM' ? $amX : $pmX, $ampmY ?? $yc);
    }

    private function mdy(?string $value): string
    {
        return ($value && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m))
            ? "{$m[2]}-{$m[3]}-{$m[1]}" : '';
    }

    private function fullName(array $d, string $prefix): string
    {
        return trim(implode(' ', array_filter([
            $d["{$prefix}_first_name"]  ?? '',
            $d["{$prefix}_middle_name"] ?? '',
            $d["{$prefix}_last_name"]   ?? '',
            $d["{$prefix}_extension"]   ?? '',
        ])));
    }

    /** Check mark centred on (cx, cy) px. */
    private function check(Fpdi $pdf, float $cx, float $cy): void
    {
        $pdf->SetFont('zapfdingbats', '', 11);
        $w = 6;
        $this->put($pdf, $cx * $this->k - $w / 2, $cy * $this->k, $w, '4', 'C');
    }

    private function grid(Fpdi $pdf, array $size): void
    {
        $pdf->SetFont('helvetica', '', 5);
        $pdf->SetDrawColor(255, 0, 0);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->SetLineWidth(0.05);
        for ($px = 0; $px <= self::REF_W; $px += 50) {
            $mm = $px * $this->k;
            $pdf->Line($mm, 0, $mm, $size['height']);
            $pdf->Text($mm + 0.5, 1, (string) $px);
        }
        for ($py = 0; $py * $this->k <= $size['height']; $py += 50) {
            $mm = $py * $this->k;
            $pdf->Line(0, $mm, $size['width'], $mm);
            $pdf->Text(0.5, $mm + 0.5, (string) $py);
        }
        $pdf->SetTextColor(0, 0, 0);
    }
}