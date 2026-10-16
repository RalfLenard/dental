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
        'professional_accreditation' => '2000-1636682-2',
        'professional_name' => 'HAROLD P. DELA VEGA',

        // CF-2 page 3 (item 10) health care professional
        'cf2_professional_accreditation' => '1501-1638387-1',
        'cf2_professional_name' => 'DR. GLADYS LOURDES BENGCO, M.D.',

        // Annex E (dental) Part IV health care professional
        'annex_provider_last_name' => 'DELA VEGA',
        'annex_provider_first_name' => 'HAROLD',
        'annex_provider_middle_name' => 'PARAYNO',
        'annex_professional_accreditation' => '2000-1636682-2',
        'annex_professional_name' => 'HAROLD P. DELA VEGA',

        'hci_rep_name' => 'ROXAN B. VILLANUEVA',
        'hci_rep_designation' => 'ADMINISTRATIVE AIDE',
        'facility_pan' => 'P03033905',
        'facility_name' => 'CONCEPCION RURAL HEALTH UNIT I',
        'facility_street' => 'L. CORTEZ STREET SAN NICOLAS POBLACION',
        'facility_city' => 'CONCEPCION',
        'facility_province' => 'TARLAC',
    ];

    /** Font size (pt) of the patient name on CF-2 Part II-1. */
    private const CF2_NAME_SIZE = 7.5;

    /* ---------------------------------------------------------------
     |  EXTRA COORDINATES (measured from csf_cf2.pdf, px @ 893 wide)
     * ------------------------------------------------------------- */

    /** CF-2 back (page 3). Box left-edges, same layout as the CSF accreditation boxes. */
    private const CF2B_ACCRED = [[145, 163, 181, 199], [228, 246, 263, 281, 299, 317, 335], [364]];
    private const CF2B_ACCRED_Y = 104;                      // first accreditation row
    private const CF2B_ACCRED_SIZE = 10;                        // font pt (boxes are only ~8pt tall)
    private const CF2B_ACCRED_DY = -2;                       // + moves down, - moves up (px)
    private const CF2B_DATE_SIZE = 10;                        // font pt for ALL date-signed boxes on page 3
    private const CF2B_DATE_DY = -3;                       // + moves down, - moves up (px)
    private const CF2B_NAME_DY = 1;                       // signature names / capacity on page 3 (px)
    private const CF2B_PROF_NAME = [83, 126, 340];           // x, y, maxW (signature line, row 1)
    private const CF2B_PROF_DATE_Y = 170;
    private const CF2B_SIGNED = ['m' => [183, 201], 'd' => [229, 247], 'y' => [276, 294, 311, 329]];

    private const CF2B_BENEFIT_BOX = [60, 460];                // Part III-A: FIRST box only

    private const CF2B_PATIENT_NAME = [47, 1058, 345];          // Part III-B signature line
    private const CF2B_PATIENT_DATE_Y = 1107;

    private const CF2B_HCI_NAME = [47, 1291, 300];          // Part IV signature line
    private const CF2B_HCI_DESIG = [360, 1291, 240];         // Part IV official capacity
    private const CF2B_HCI_DATE_Y = 1298;
    private const CF2B_SIGNED_IV = ['m' => [686, 704], 'd' => [732, 750], 'y' => [778, 796, 814, 832]];

    /** CF-2 page 3, Part III-B: representative details copied from CSF Part III (check box centres / text, px). */
    private const CF2B_REL = [
        'spouse' => [240, 1146],
        'child' => [308, 1146],
        'parent' => [368, 1146],
        'sibling' => [240, 1163],
        'others' => [308, 1163],
    ];
    private const CF2B_REL_OTHER = [397, 1160, 105];                                  // "Others, Specify" line: x, y, maxW
    private const CF2B_REASON = ['incapacitated' => [240, 1186], 'other' => [240, 1198]];
    private const CF2B_REASON_OTHER = [335, 1195, 170];                                  // "Other Reasons" line: x, y, maxW
    private const CF2B_WHO = ['patient' => [530, 1186], 'representative' => [530, 1199]];

    /** CF-2 page 3, Part III-A second (co-pay) table. Row 1 = HCI fees (free input), row 2 = professional fees (always N/A). */
    private const COPAY_COLS = [254, 393, 542];   // centre x: total actual charges, after discount, PhilHealth benefit
    private const COPAY_HCI_Y = 722;               // vertical centre of the HCI fees row
    private const COPAY_PF_Y = 790;               // vertical centre of the professional fees row
    private const COPAY_HCI_AMT = [675, 695, 140];   // "Amount P" line, HCI row: x, y, maxW
    private const COPAY_PF_AMT = [695, 766, 140];   // "Amount P" line, professional row
    private const COPAY_HCI_OTHERS = [627, 745];        // "Others" check box centre, HCI row

    /** CF-2 front, item 8b: Z-Benefit Package Code (free input). Adjust with ?grid=1 if needed. */
    private const Z_BENEFIT_POS = [430, 1065, 205];              // x, y, maxW

    /* ---------------------------------------------------------------
     |  ANNEX E (dental claim form) page 1 = PDF page 4
     |  Measured from the template, px @ 893 wide. Use ?grid=1 to verify.
     * ------------------------------------------------------------- */
    private const AE_PIN_MEMBER = [[430, 447], [473.5, 491, 508, 524.75, 541.75, 558, 574.75, 592.25, 609], [634.75]];
    private const AE_PIN_DEP = [[475, 494.5], [526.5, 545, 564, 583.5, 602.7, 622, 641.7, 661.3, 680.5], [711]];
    private const AE_DOB_MEMBER = ['m' => [681.5, 697], 'd' => [720.7, 736], 'y' => [759.5, 775, 790.4, 805.8]];
    private const AE_DOB_PATIENT = ['m' => [642.2, 663.5], 'd' => [692.3, 712.7], 'y' => [742.2, 762.3, 781.5, 800.2]];
    private const AE_PAN = [536, 555, 574, 593.5, 613, 632, 651, 670.7, 690];
    private const AE_ACCRED = [[255.7, 274.8, 294, 312.7], [344, 363.2, 382.3, 401.5, 420.7, 439.8, 459.3], [491]];
    private const AE_SIGNED = ['m' => [624.7, 644.7], 'd' => [674.3, 693.3], 'y' => [724, 743.2, 762.2, 781.3]];

    /** Part II item 4: Relationship to Member check box centres [x, y] (Child / Parent / Spouse). Tune with ?grid=1. */
    private const AE_REL = [
        'child' => [261.3, 811],
        'parent' => [261.5, 853],
        'spouse' => [261.5, 881],
    ];

    /* ---------------------------------------------------------------
     |  ANNEX E page 2 = PDF page 5 (Part V and VI)
     |  ESTIMATED from the template image, px @ 893 wide.
     |  Open the PDF with ?grid=1 and adjust any value that is off.
     * ------------------------------------------------------------- */
    /** Part V item 1 / 2 / 4: check box centres (always NO, NO, Improved). */
    private const AE2_REF_NO_1 = [46, 160];
    private const AE2_REF_NO_2 = [46, 343];
    private const AE2_IMPROVED = [228, 454];

    /** Part V item 3: date provision of service/s (= date admitted). */
    private const AE2_DATE_SERVICE = ['m' => [225, 243], 'd' => [275, 293], 'y' => [325, 343, 361, 379]];
    private const AE2_DATE_SERVICE_Y = 413;

    /** Part V items 5 to 7: text lines [x, y, maxW]. */
    private const AE2_WORKING_DX = [215, 521, 640];
    private const AE2_FINAL_DX = [215, 546, 320];
    private const AE2_FINAL_ICD = [615, 546, 260];
    private const AE2_PROCEDURE = [215, 572, 320];
    private const AE2_PROC_CODE = [645, 572, 225];

    /** Part V item 8: tooth table (3 rows): centre x of the tooth number and dental service columns, row centres. */
    private const AE2_TOOTH_X = 282;
    private const AE2_SERVICE_X = 415;
    private const AE2_TOOTH_Y = [632, 647.5, 662.5];

    /** Part V item 9: package code [x, y, maxW]. */
    private const AE2_PACKAGE = [556, 709, 75];

    /** Part VI item 1: "No copayment" check box centre. */
    private const AE2_NO_COPAY = [46, 807];

    /** Part VI item 2: amounts after the printed "PHP" (x of each column, y, maxW). */
    private const AE2_COPAY_X = [100, 275, 470, 650];
    private const AE2_COPAY_Y = 918;
    private const AE2_COPAY_W = 125;

    /** Part VI item 3: consent signatory name [x, y, maxW] and date signed. */
    private const AE2_CONSENT_NAME = [44, 1040, 380];
    private const AE2_CONSENT_DATE_Y = 1035;
    private const AE2_SIGNED_3 = ['m' => [578, 598], 'd' => [629, 649], 'y' => [679, 698, 718, 738]];

    /** Part VI certification: authorized HCI representative name [x, y, maxW] and date signed. */
    private const AE2_CERT_NAME = [44, 1164, 330];
    private const AE2_CERT_DATE_Y = 1155;
    private const AE2_SIGNED_CERT = ['m' => [555, 575], 'd' => [605, 625], 'y' => [658, 678, 696, 716]];

    private float $k = 1;

    /** Live nudges for page 3 (px). Override in the URL: ?acc_dy=-2&date_dy=-3&name_dy=-4 */
    private array $nudge = ['acc' => self::CF2B_ACCRED_DY, 'date' => self::CF2B_DATE_DY, 'name' => self::CF2B_NAME_DY];

    private function listing()
    {
        return PdfRecord::query()->latest()->limit(100)
            ->get(['id', 'record_no', 'patient_name', 'created_at']);
    }

    public function index()
    {
        return Inertia::render('PdfRecords/Index', [
            'records' => $this->listing(),
            'record' => null,
        ]);
    }

    public function edit(PdfRecord $record)
    {
        return Inertia::render('PdfRecords/Index', [
            'records' => $this->listing(),
            'record' => $record,
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'patient_name' => ['nullable', 'string', 'max:255'],
            'form_data' => ['required', 'array'],
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
            foreach ([
                'representative_name',
                'representative_relationship',
                'representative_relationship_other',
                'representative_reason',
                'representative_reason_other'
            ] as $f) {
                $d[$f] = '';
            }
        }

        if (strtolower($d['relationship_to_member'] ?? '') === 'member') {
            foreach (['last_name', 'first_name', 'extension', 'middle_name', 'birthdate'] as $f) {
                $d["patient_{$f}"] = $d["member_{$f}"] ?? '';
            }
            $d['dependent_pin'] = self::FILL_DEPENDENT_PIN_WHEN_MEMBER ? ($d['member_pin'] ?? '') : '';
            $d['patient_sex'] = $d['member_sex'] ?? '';
        }

        // CF-2 page 3 (Part III-A): professional fees always 0, grand total = institution fees.
        $d['professional_fees'] = '0';
        $d['grand_total'] = $d['hci_charges'] ?? '';

        $d['dx_a'] = $this->diagnosisRows($d, 'dx_a');
        $d['dx_b'] = $this->diagnosisRows($d, 'dx_b');

        // Annex E item 8: always three tooth-number rows
        $teeth = is_array($d['ae_teeth'] ?? null) ? array_values($d['ae_teeth']) : [];
        $d['ae_teeth'] = [
            trim((string) ($teeth[0] ?? '')),
            trim((string) ($teeth[1] ?? '')),
            trim((string) ($teeth[2] ?? '')),
        ];

        return $d;
    }

    /**
     * CF-2 item 7: three rows per group (one printed underline each).
     * Everything is free input now: nothing is filled automatically, and any row can stay blank.
     */
    private function diagnosisRows(array $d, string $key): array
    {
        $rows = is_array($d[$key] ?? null) ? array_values($d[$key]) : [];

        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $r = $rows[$i] ?? [];
            $out[] = [
                'diagnosis' => trim((string) ($r['diagnosis'] ?? '')),
                'icd10' => trim((string) ($r['icd10'] ?? '')),
                'procedure' => trim((string) ($r['procedure'] ?? '')),
                'rvs' => trim((string) ($r['rvs'] ?? '')),
                'date' => trim((string) ($r['date'] ?? '')),
            ];
        }

        return $out;
    }

    public function preview(Request $request, PdfRecord $record)
    {
        $template = collect([
            storage_path('app/pdf-templates/csf_cf2.pdf'),
            storage_path('app/pdf-template/csf_cf2.pdf'),
            storage_path('app/private/pdf-templates/csf_cf2.pdf'),
        ])->first(fn($p) => is_file($p));

        abort_unless($template, 404, 'The PDF template was not found in storage/app/pdf-templates.');

        $d = $this->resolve($record->form_data ?? []);

        $this->nudge = [
            'acc' => (float) $request->query('acc_dy', self::CF2B_ACCRED_DY),
            'date' => (float) $request->query('date_dy', self::CF2B_DATE_DY),
            'name' => (float) $request->query('name_dy', self::CF2B_NAME_DY),
        ];

        $pdf = new Fpdi();
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setCellMargins(0, 0, 0, 0);

        $pageCount = $pdf->setSourceFile($template);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tpl = $pdf->importPage($pageNo);
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
                4 => $this->pageAnnexE1($pdf, $d),
                5 => $this->pageAnnexE2($pdf, $d),
                default => null,
            };
        }

        $content = $pdf->Output('csf-cf2-' . $record->id . '.pdf', 'S');

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="csf-cf2-' . $record->id . '.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /* ---------------------------------------------------------------
     |  PAGE MAPS  (x = left edge, y = vertical CENTER of the text, px @ 893 wide)
     |  Measured from the PDF's own boxes/labels. Use ?grid=1 to verify.
     * ------------------------------------------------------------- */

    /** Box left-edges (all boxes are 18px wide). */
    private const PIN_MEMBER = [[379, 397], [426, 444, 462, 480, 497, 515, 533, 551, 569], [597]];
    private const PIN_DEP = [[395, 413], [442, 460, 478, 496, 514, 532, 550, 568, 586], [614]];

    private const DOB_MEMBER = ['m' => [669, 686], 'd' => [715, 733], 'y' => [761, 779, 797, 815]];
    private const ADMITTED = ['m' => [131, 149], 'd' => [177, 195], 'y' => [223, 241, 259, 277]];
    private const DISCHARGED = ['m' => [428, 446], 'd' => [474, 492], 'y' => [520, 538, 556, 574]];
    private const DOB_PATIENT = ['m' => [669, 686], 'd' => [715, 733], 'y' => [761, 779, 797, 815]];
    private const SIGNED = ['m' => [682, 700], 'd' => [728, 746], 'y' => [775, 793, 811, 829]];

    private const SIGNED_I9_MEMBER = ['m' => [180, 198], 'd' => [226, 244], 'y' => [272, 290, 308, 326]];
    private const SIGNED_I9_REP = ['m' => [575, 593], 'd' => [622, 640], 'y' => [668, 686, 704, 722]];
    private const SIGNED_III = ['m' => [583, 601], 'd' => [630, 648], 'y' => [676, 694, 712, 730]];

    private const ACCRED = [[123, 141, 159, 177], [206, 224, 242, 260, 277, 295, 313], [342]];

    private function pageCsf(Fpdi $pdf, array $d): void
    {
        // ---------- PART I ----------
        $this->pin($pdf, self::PIN_MEMBER, 247, $d['member_pin'] ?? '');

        // 2. Member name
        $this->txt($pdf, 40, 283, $d['member_last_name'] ?? '', 10, 150);
        $this->txt($pdf, 201, 283, $d['member_first_name'] ?? '', 10, 150);
        $this->txt($pdf, 361, 283, $d['member_extension'] ?? '', 10, 150);
        $this->txt($pdf, 522, 283, $d['member_middle_name'] ?? '', 10, 140);

        // 3. Member birthdate
        $this->date($pdf, self::DOB_MEMBER, 292, $d['member_birthdate'] ?? '');

        // 4. Dependent PIN (blank unless filled by resolve())
        $this->pin($pdf, self::PIN_DEP, 343, $d['dependent_pin'] ?? '');

        // 5. Patient name
        $this->txt($pdf, 40, 379, $d['patient_last_name'] ?? '', 10, 150);
        $this->txt($pdf, 201, 379, $d['patient_first_name'] ?? '', 10, 150);
        $this->txt($pdf, 361, 379, $d['patient_extension'] ?? '', 10, 150);
        $this->txt($pdf, 522, 379, $d['patient_middle_name'] ?? '', 10, 140);

        // 6. Relationship (no box when the patient is the member)
        match (strtolower($d['relationship_to_member'] ?? '')) {
            'child' => $this->check($pdf, 663, 385),
            'parent' => $this->check($pdf, 724, 385),
            'spouse' => $this->check($pdf, 790, 385),
            default => null,
        };

        // 7. Confinement period (both complete dates) / 8. Patient DOB
        $this->date($pdf, self::ADMITTED, 457, $d['date_admitted'] ?? '');
        $this->date($pdf, self::DISCHARGED, 457, $d['date_discharged'] ?? '');
        $this->date($pdf, self::DOB_PATIENT, 457, $d['patient_birthdate'] ?? '');

        // 9. Certification of member: signatory mirrors Part III (member / representative)
        $this->partI9($pdf, $d);

        // ---------- PART II (employer) : intentionally left blank ----------

        // ---------- PART III : consent / representative of the patient ----------
        $this->partIII($pdf, $d);

        // ---------- PART IV : health care professional (date signed = date discharged, complete) ----------
        $this->cells($pdf, self::ACCRED[0], 1115, substr($this->digits($d['professional_accreditation']), 0, 4), 11);
        $this->cells($pdf, self::ACCRED[1], 1115, substr($this->digits($d['professional_accreditation']), 4, 7), 11);
        $this->cells($pdf, self::ACCRED[2], 1115, substr($this->digits($d['professional_accreditation']), 11, 1), 11);
        $this->txt($pdf, 385, 1112, $d['professional_name'], 10, 225);
        $this->signedDate($pdf, 1115, $d['date_discharged'] ?? '', self::SIGNED, 11, true);

        // ---------- PART V : provider ----------
        $this->txt($pdf, 415, 1252, $d['first_case_rate'] ?? '', 11, 140);   // 89221 or 89222
        $this->txt($pdf, 680, 1255, $d['second_case_rate'] ?? '', 11, 170);
        $this->txt($pdf, 40, 1293, $d['hci_rep_name'], 10, 340);
        $this->txt($pdf, 400, 1293, $d['hci_rep_designation'], 10, 200);
        $this->signedDate($pdf, 1295, $d['date_discharged'] ?? '', self::SIGNED, 11, true);
    }

    /** Annex E page 1 (dental claim form): Parts I to IV. Part V and VI are on the next page. */
    /** Annex E page 1 (dental claim form): Parts I to IV. Part V and VI are on the next page. */
    private function pageAnnexE1(Fpdi $pdf, array $d): void
    {
        $rel = strtolower($d['relationship_to_member'] ?? '');
        $patientIsMember = $rel === 'member';

        // ---------- PART I : member information ----------
        $this->pin($pdf, self::AE_PIN_MEMBER, 314, $d['member_pin'] ?? '', 10);

        // 2. Name of member (the first name line sits lower on this form)
        $this->txt($pdf, 64, 364, $d['member_last_name'] ?? '', 10, 152);
        $this->txt($pdf, 246, 379, $d['member_first_name'] ?? '', 10, 128);
        $this->txt($pdf, 380, 364, $d['member_extension'] ?? '', 10, 148);
        $this->txt($pdf, 543, 364, $d['member_middle_name'] ?? '', 10, 122);

        // 3. Date of birth
        $this->date($pdf, self::AE_DOB_MEMBER, 366.5, $d['member_birthdate'] ?? '', 10);

        // 5. Sex
        match (strtolower($d['member_sex'] ?? '')) {
            'male' => $this->check($pdf, 730.5, 434),
            'female' => $this->check($pdf, 786.75, 434),
            default => null,
        };

        // 4. Mailing address (country PHILIPPINES is already printed on the form)
        $this->txt($pdf, 65, 457, $d['addr_unit'] ?? '', 10, 153);
        $this->txt($pdf, 245, 457, $d['addr_building'] ?? '', 10, 128);
        $this->txt($pdf, 380, 457, $d['addr_lot'] ?? '', 10, 148);
        $this->txt($pdf, 543, 457, $d['addr_street'] ?? '', 10, 122);
        $this->txt($pdf, 673, 457, $d['addr_subdivision'] ?? '', 10, 178);
        $this->txt($pdf, 65, 502, $d['addr_barangay'] ?? '', 10, 153);
        $this->txt($pdf, 245, 503, $d['addr_city'] ?? '', 10, 128);
        $this->txt($pdf, 380, 502, $d['addr_province'] ?? '', 10, 148);
        $this->txt($pdf, 673, 504, $d['addr_zip'] ?? '', 10, 178);

        // 6. Contact information
        $this->txt($pdf, 64, 566, $d['contact_landline'] ?? '', 10, 290);
        $this->txt($pdf, 380, 568, $d['contact_mobile'] ?? '', 10, 250);
        $this->txt($pdf, 668, 568, $d['contact_email'] ?? '', 10, 185);

        // 7. Patient is the member?
        if ($patientIsMember) {
            $this->check($pdf, 257.8, 617);          // Yes, proceed to Part III
        } elseif ($rel !== '') {
            $this->check($pdf, 430.5, 617);          // No, proceed to Part II
        }

        // ---------- PART II : patient / client information ----------
        // Left completely blank when the patient is the member (proceed to Part III).
        if (!$patientIsMember) {
            $this->pin($pdf, self::AE_PIN_DEP, 692.7, $d['dependent_pin'] ?? '', 10);

            $this->txt($pdf, 64, 757, $d['patient_last_name'] ?? '', 10, 152);
            $this->txt($pdf, 245, 757, $d['patient_first_name'] ?? '', 10, 112);
            $this->txt($pdf, 362, 757, $d['patient_extension'] ?? '', 10, 152);
            $this->txt($pdf, 530, 757, $d['patient_middle_name'] ?? '', 10, 125);

            $this->date($pdf, self::AE_DOB_PATIENT, 717.3, $d['patient_birthdate'] ?? '', 10);

            // 4. Relationship to member (Child / Parent / Spouse)
            if (isset(self::AE_REL[$rel])) {
                $this->check($pdf, ...self::AE_REL[$rel]);
            }

            // 5. Sex of the patient
            match (strtolower($d['patient_sex'] ?? '')) {
                'male' => $this->check($pdf, 597, 820),
                'female' => $this->check($pdf, 597, 846.6),
                default => null,
            };
        }

        // ---------- PART III : health care institution / facility (same as CF-2) ----------
        $pan = preg_replace('/[^A-Za-z0-9]/', '', (string) ($d['facility_pan'] ?? ''));
        $this->cells($pdf, self::AE_PAN, 944, $pan, 10);
        $this->txt($pdf, 366, 969, $d['facility_name'] ?? '', 10, 295);
        $this->twoLines($pdf, 138, 995, $d['facility_street'] ?? '', 154);
        $this->txt($pdf, 322, 995, $d['facility_city'] ?? '', 10, 170);
        $this->txt($pdf, 515, 995, $d['facility_province'] ?? '', 10, 105);

        // ---------- PART IV : health care professional information and signature ----------
        // The template has a stray "PARAYNO" printed above "Middle Name": cover it.
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(707 * $this->k, 1065 * $this->k, 66 * $this->k, 14 * $this->k, 'F');

        $this->txt($pdf, 336, 1073, $d['annex_provider_last_name'] ?? '', 10, 105);
        $this->txt($pdf, 449, 1073, $d['annex_provider_first_name'] ?? '', 10, 100);
        $this->txt($pdf, 715, 1073, $d['annex_provider_middle_name'] ?? '', 10, 100);

        $acc = $this->digits($d['annex_professional_accreditation'] ?? '');
        $this->cells($pdf, self::AE_ACCRED[0], 1119, substr($acc, 0, 4), 9);
        $this->cells($pdf, self::AE_ACCRED[1], 1119, substr($acc, 4, 7), 9);
        $this->cells($pdf, self::AE_ACCRED[2], 1119, substr($acc, 11, 1), 9);

        $this->txt($pdf, 236, 1148, $d['annex_professional_name'] ?? '', 10, 270);
        $this->signedDate($pdf, 1152, $d['date_discharged'] ?? '', self::AE_SIGNED, 9, true);
    }

    /** Annex E page 2 (PDF page 5): Part V (services availed) and Part VI (copayment, consent, certification). */
    private function pageAnnexE2(Fpdi $pdf, array $d): void
    {
        $discharged = $d['date_discharged'] ?? '';

        // ---------- PART V ----------
        // 1. Referred by another HCI/facility: always NO
        $this->check($pdf, ...self::AE2_REF_NO_1);

        // 2. Referred by other institution: always NO
        $this->check($pdf, ...self::AE2_REF_NO_2);

        // 3. Date provision of service/s = date admitted
        $this->date($pdf, self::AE2_DATE_SERVICE, self::AE2_DATE_SERVICE_Y, $d['date_admitted'] ?? '', 10);

        // 4. Patient/client disposition: always IMPROVED
        $this->check($pdf, ...self::AE2_IMPROVED);

        // 5. Working diagnosis/es
        [$x, $y, $w] = self::AE2_WORKING_DX;
        $this->txt($pdf, $x, $y, $d['ae_working_diagnosis'] ?? '', 10, $w);

        // 6. Final diagnosis/es + ICD 10 code/s
        [$x, $y, $w] = self::AE2_FINAL_DX;
        $this->txt($pdf, $x, $y, $d['ae_final_diagnosis'] ?? '', 10, $w);
        [$x, $y, $w] = self::AE2_FINAL_ICD;
        $this->txt($pdf, $x, $y, $d['ae_final_icd10'] ?? '', 10, $w);

        // 7. Procedure/s done + RVS/package code/s
        [$x, $y, $w] = self::AE2_PROCEDURE;
        $this->txt($pdf, $x, $y, $d['ae_procedure'] ?? '', 10, $w);
        [$x, $y, $w] = self::AE2_PROC_CODE;
        $this->txt($pdf, $x, $y, $d['ae_procedure_code'] ?? '', 10, $w);

        // 8. Dental/oral health: a tooth number automatically gets dental service X (Extraction)
        foreach ($d['ae_teeth'] as $i => $tooth) {
            if ($tooth === '' || !isset(self::AE2_TOOTH_Y[$i]))
                continue;
            $this->centered($pdf, self::AE2_TOOTH_X, self::AE2_TOOTH_Y[$i], $tooth, 10, 120);
            $this->centered($pdf, self::AE2_SERVICE_X, self::AE2_TOOTH_Y[$i], 'X', 10, 120);
        }

        // 9. PhilHealth benefit claim: RVS / package code
        [$x, $y, $w] = self::AE2_PACKAGE;
        $this->txt($pdf, $x, $y, $d['ae_package_code'] ?? '', 10, $w);

        // ---------- PART VI ----------
        // 1. Certification: always NO COPAYMENT
        $this->check($pdf, ...self::AE2_NO_COPAY);

        // 2. Details of copayment: total charges and discount are free input, benefit and copayment are always 0.00
        $amounts = [
            $this->plainAmount($d['ae_total_charges'] ?? ''),
            $this->plainAmount($d['ae_discount'] ?? ''),
            '0.00',
            '0.00',
        ];
        foreach ($amounts as $i => $amount) {
            $this->txt($pdf, self::AE2_COPAY_X[$i], self::AE2_COPAY_Y, $amount, 10, self::AE2_COPAY_W);
        }

        // 3. Consent: patient (or representative) signs, date signed = complete date discharged
        $byRep = ($d['part3_signer'] ?? 'patient') === 'representative';
        $signer = $byRep ? ($d['representative_name'] ?? '') : $this->fullName($d, 'patient', true);
        [$x, $y, $w] = self::AE2_CONSENT_NAME;
        $this->txt($pdf, $x, $y, $signer, 10, $w);
        $this->signedDate($pdf, self::AE2_CONSENT_DATE_Y, $discharged, self::AE2_SIGNED_3, 9, true);

        // Certification of consumption: authorized HCI representative, complete date discharged
        [$x, $y, $w] = self::AE2_CERT_NAME;
        $this->txt($pdf, $x, $y, $d['hci_rep_name'] ?? '', 10, $w);
        $this->signedDate($pdf, self::AE2_CERT_DATE_Y, $discharged, self::AE2_SIGNED_CERT, 9, true);
    }

    /** Part I item 9: same signer choice and representative details as Part III. */
    private function partI9(Fpdi $pdf, array $d): void
    {
        $byRep = ($d['part3_signer'] ?? 'patient') === 'representative';

        if (!$byRep) {
            $this->check($pdf, 45, 657);                         // Member box
            $this->txt($pdf, 90, 530, $this->fullName($d, 'member', true), 10, 280);   // middle initial
            $this->signedDate($pdf, 568, $d['date_discharged'] ?? '', self::SIGNED_I9_MEMBER, 11, true);
            return;
        }

        $this->check($pdf, 116, 657);                            // Representative box
        $this->txt($pdf, 468, 530, $d['representative_name'] ?? '', 10, 380);
        $this->signedDate($pdf, 568, $d['date_discharged'] ?? '', self::SIGNED_I9_REP, 11, true);

        switch (strtolower($d['representative_relationship'] ?? '')) {
            case 'spouse':
                $this->check($pdf, 588, 599);
                break;
            case 'child':
                $this->check($pdf, 655, 599);
                break;
            case 'parent':
                $this->check($pdf, 712, 599);
                break;
            case 'sibling':
                $this->check($pdf, 588, 619);
                break;
            case 'others':
                $this->check($pdf, 655, 619);
                $this->txt($pdf, 740, 613, $d['representative_relationship_other'] ?? '', 9, 105);
                break;
        }

        if (($d['representative_reason'] ?? '') === 'incapacitated') {
            $this->check($pdf, 588, 641);
        } elseif (($d['representative_reason'] ?? '') === 'other') {
            $this->check($pdf, 588, 662);
            $this->txt($pdf, 672, 663, $d['representative_reason_other'] ?? '', 9, 185);
        }
    }

    private function partIII(Fpdi $pdf, array $d): void
    {
        $byRep = ($d['part3_signer'] ?? 'patient') === 'representative';
        $this->signedDate($pdf, 965, $d['date_discharged'] ?? '', self::SIGNED_III, 11, true);

        if ($byRep) {
            $this->check($pdf, 111, 1058);                       // Representative box
            $this->txt($pdf, 95, 958, $d['representative_name'] ?? '', 10, 410);

            switch (strtolower($d['representative_relationship'] ?? '')) {
                case 'spouse':
                    $this->check($pdf, 588, 1001);
                    break;
                case 'child':
                    $this->check($pdf, 655, 1001);
                    break;
                case 'parent':
                    $this->check($pdf, 712, 1001);
                    break;
                case 'sibling':
                    $this->check($pdf, 588, 1022);
                    break;
                case 'others':
                    $this->check($pdf, 655, 1022);
                    $this->txt($pdf, 740, 1017, $d['representative_relationship_other'] ?? '', 9, 105);
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
            $this->txt($pdf, 95, 962, $this->fullName($d, 'patient', true), 10, 410);   // middle initial
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

        // Part II-3 confinement period (admitted and discharged are both complete dates)
        $cf2Date = ['m' => [289, 307], 'd' => [335, 353], 'y' => [381, 399, 417, 435]];
        $this->date($pdf, $cf2Date, 532, $d['date_admitted'] ?? '', 10);
        $this->date($pdf, $cf2Date, 554, $d['date_discharged'] ?? '', 10);

        // Time admitted / discharged, AM-PM from the entered time
        $this->time($pdf, 532, 696, 758, $d['time_admitted'] ?? '');
        $this->time($pdf, 554, 696, 758, $d['time_discharged'] ?? '', 557);

        // Part II-4 patient disposition: always IMPROVED
        $this->check($pdf, 56, 604);

        // Part II-5 accommodation: always NON-PRIVATE
        $this->check($pdf, 299, 692);

        // 6. Admission diagnosis (two lines under the heading)
        $this->wrap($pdf, 52, [736, 752], $d['admission_diagnosis'] ?? '', 800, 10);

        // 7. Discharge diagnoses: group a (rows 813/833/852) and group b (872/892/911). Free input.
        $this->diagnosisTable($pdf, $d['dx_a'], [813, 833, 852]);
        $this->diagnosisTable($pdf, $d['dx_b'], [872, 892, 911]);

        // 8. Special considerations: Intensive / Maintenance boxes are intentionally NOT checked.

        // 8b. Z-Benefit Package Code (free input)
        [$zx, $zy, $zw] = self::Z_BENEFIT_POS;
        $this->txt($pdf, $zx, $zy, $d['z_benefit_code'] ?? '', 10, $zw);

        // 9. Benefits (first case rate is free input on the CF-2)
        $this->txt($pdf, 255, 1327, $d['cf2_first_case_rate'] ?? '', 11, 240);
        $this->txt($pdf, 640, 1327, $d['second_case_rate'] ?? '', 11, 210);
    }

    /** One diagnosis per printed underline; ICD, procedure, RVS and date on the same row. */
    private function diagnosisTable(Fpdi $pdf, array $rows, array $ys): void
    {
        foreach ($rows as $i => $r) {
            $y = $ys[$i] ?? null;
            if ($y === null)
                break;

            $this->twoLines($pdf, 64, $y, $r['diagnosis'], 108);
            $this->txt($pdf, 184, $y, $r['icd10'], 8, 80);
            $this->txt($pdf, 295, $y, $r['procedure'], 8, 175);
            $this->txt($pdf, 478, $y, $r['rvs'], 8, 95);
            $this->txt($pdf, 580, $y, $this->mdy($r['date']), 8, 90);
        }
    }

    private function pageCf2Back(Fpdi $pdf, array $d): void
    {
        $signed = $d['date_discharged'] ?? '';   // every date signed on page 3 = the complete DATE DISCHARGED
        $acc = $this->digits($d['cf2_professional_accreditation']);

        // Part 10: health care professional (first block): accreditation, name, date signed
        $y = self::CF2B_ACCRED_Y + $this->nudge['acc'];
        $sz = self::CF2B_ACCRED_SIZE;
        $this->cells($pdf, self::CF2B_ACCRED[0], $y, substr($acc, 0, 4), $sz);
        $this->cells($pdf, self::CF2B_ACCRED[1], $y, substr($acc, 4, 7), $sz);
        $this->cells($pdf, self::CF2B_ACCRED[2], $y, substr($acc, 11, 1), $sz);
        [$x, $ny, $w] = self::CF2B_PROF_NAME;
        $this->txt($pdf, $x, $ny + $this->nudge['name'], $d['cf2_professional_name'], 10, $w);
        $this->signedDate($pdf, self::CF2B_PROF_DATE_Y + $this->nudge['date'], $signed, self::CF2B_SIGNED, self::CF2B_DATE_SIZE, true);

        // Part III-A: only the FIRST box is checked
        $this->check($pdf, self::CF2B_BENEFIT_BOX[0], self::CF2B_BENEFIT_BOX[1]);

        // Part III-A "Total Actual Charges" column (right-aligned at 805)
        $this->money($pdf, 805, 514, $d['hci_charges'] ?? '');          // 2,925.00 or 4,875.00
        $this->money($pdf, 805, 536, $d['professional_fees'] ?? '');    // always 0.00
        $this->money($pdf, 805, 557, $d['grand_total'] ?? '');          // same as institution fees

        // Part III-A second table (co-pay)
        $this->copayTable($pdf, $d);

        // Part III-B: signatory copied from CSF Part III (date signed)
        $byRep = ($d['part3_signer'] ?? 'patient') === 'representative';
        $signer = $byRep ? ($d['representative_name'] ?? '') : $this->fullName($d, 'patient', true);
        [$x, $ny, $w] = self::CF2B_PATIENT_NAME;
        $this->txt($pdf, $x, $ny + $this->nudge['name'], $signer, 10, $w);
        $this->signedDate($pdf, self::CF2B_PATIENT_DATE_Y + $this->nudge['date'], $signed, self::CF2B_SIGNED, self::CF2B_DATE_SIZE, true);

        // Part III-B: relationship / reason / patient-or-representative boxes, same as CSF Part III
        $this->cf2Consent($pdf, $d, $byRep);

        // Part IV: HCI representative (date signed)
        [$x, $ny, $w] = self::CF2B_HCI_NAME;
        $this->txt($pdf, $x, $ny + $this->nudge['name'], $d['hci_rep_name'], 10, $w);
        [$x, $ny, $w] = self::CF2B_HCI_DESIG;
        $this->txt($pdf, $x, $ny + $this->nudge['name'], $d['hci_rep_designation'], 10, $w);
        $this->signedDate($pdf, self::CF2B_HCI_DATE_Y + $this->nudge['date'], $signed, self::CF2B_SIGNED_IV, self::CF2B_DATE_SIZE, true);
    }

    /** CF-2 page 3 Part III-B: mirrors CSF Part III (representative relationship, reason, and who signs). */
    private function cf2Consent(Fpdi $pdf, array $d, bool $byRep): void
    {
        if (!$byRep) {
            $this->check($pdf, ...self::CF2B_WHO['patient']);
            return;
        }

        $this->check($pdf, ...self::CF2B_WHO['representative']);

        $rel = strtolower($d['representative_relationship'] ?? '');
        if (isset(self::CF2B_REL[$rel])) {
            $this->check($pdf, ...self::CF2B_REL[$rel]);
            if ($rel === 'others') {
                [$x, $y, $w] = self::CF2B_REL_OTHER;
                $this->txt($pdf, $x, $y, $d['representative_relationship_other'] ?? '', 9, $w);
            }
        }

        $reason = $d['representative_reason'] ?? '';
        if (isset(self::CF2B_REASON[$reason])) {
            $this->check($pdf, ...self::CF2B_REASON[$reason]);
            if ($reason === 'other') {
                [$x, $y, $w] = self::CF2B_REASON_OTHER;
                $this->txt($pdf, $x, $y, $d['representative_reason_other'] ?? '', 9, $w);
            }
        }
    }

    /** Part III-A second table: row 1 is free input (Others always checked), row 2 is always N/A. */
    private function copayTable(Fpdi $pdf, array $d): void
    {
        $cols = self::COPAY_COLS;

        // Row 1: Total Health Care Institution Fees
        foreach (['copay_hci_actual', 'copay_hci_discount', 'copay_hci_benefit'] as $i => $key) {
            $this->centered($pdf, $cols[$i], self::COPAY_HCI_Y, $this->plainAmount($d[$key] ?? ''));
        }
        [$x, $y, $w] = self::COPAY_HCI_AMT;
        $this->txt($pdf, $x, $y, $this->plainAmount($d['copay_hci_amount'] ?? ''), 10, $w);
        $this->check($pdf, self::COPAY_HCI_OTHERS[0], self::COPAY_HCI_OTHERS[1]);   // "Others" always checked

        // Row 2: Total Professional Fees (always N/A)
        foreach ($cols as $cx) {
            $this->centered($pdf, $cx, self::COPAY_PF_Y, 'N/A');
        }
        [$x, $y, $w] = self::COPAY_PF_AMT;
        $this->txt($pdf, $x, $y, 'N/A', 10, $w);
    }

    /** Numbers get two decimals (1,234.50); any other text is printed as typed. */
    private function plainAmount(?string $value): string
    {
        $value = trim((string) $value);
        $clean = str_replace(',', '', $value);

        return ($value !== '' && is_numeric($clean)) ? number_format((float) $clean, 2) : $value;
    }

    /** Uppercase text centred on $xc (px), shrunk to fit $maxW (px). */
    private function centered(Fpdi $pdf, float $xc, float $yc, string $text, float $size = 10, float $maxW = 110): void
    {
        $text = mb_strtoupper(trim($text));
        if ($text === '')
            return;

        $pdf->SetFont('helvetica', '', $size);
        while ($size > 6 && $pdf->GetStringWidth($text) > $maxW * $this->k) {
            $size -= 0.5;
            $pdf->SetFont('helvetica', '', $size);
        }
        $this->put($pdf, ($xc - $maxW / 2) * $this->k, $yc * $this->k, $maxW * $this->k, $text, 'C');
    }

    /* ----------------------------- helpers ----------------------------- */

    /** Place one cell of text, vertically centred on $ycMm. */
    private function put(Fpdi $pdf, float $xMm, float $ycMm, float $wMm, string $text, string $align = 'L', int $stretch = 0): void
    {
        $h = 6;
        $pdf->SetXY($xMm, $ycMm - $h / 2);
        $pdf->Cell($wMm, $h, $text, 0, 0, $align, false, '', $stretch, false, 'T', 'M');
    }

    /** Uppercase text, shrunk to fit $maxW (px) if given. */
    private function txt(Fpdi $pdf, float $x, float $yc, ?string $text, float $size = 10, ?float $maxW = null, bool $fit = false): void
    {
        $text = mb_strtoupper(trim((string) $text));
        if ($text === '')
            return;

        $pdf->SetFont('helvetica', '', $size);

        // $fit: keep the font size and squeeze the text to exactly the underline width (only if too long)
        if ($fit && $maxW) {
            $this->put($pdf, $x * $this->k, $yc * $this->k, $maxW * $this->k, $text, 'L', 1);
            return;
        }

        if ($maxW) {
            $limit = $maxW * $this->k;
            while ($size > 6 && $pdf->GetStringWidth($text) > $limit) {
                $size -= 0.5;
                $pdf->SetFont('helvetica', '', $size);
            }
        }

        $this->put($pdf, $x * $this->k, $yc * $this->k, ($maxW ?? 400) * $this->k + 5, $text, 'L');
    }

    /**
     * Diagnosis inside ONE underline: one line if it fits, otherwise two small lines
     * (font shrinks from 6pt down to 4.5pt until it fits on two lines; a leftover is squeezed).
     * $y is the vertical centre used for a single line; two lines sit just above the underline.
     */
    private function twoLines(Fpdi $pdf, float $x, float $y, ?string $text, float $maxW): void
    {
        $text = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $text)));
        if ($text === '')
            return;

        $limit = $maxW * $this->k;
        $lines = [];

        for ($size = 6.0; $size >= 4.5; $size -= 0.25) {
            $pdf->SetFont('helvetica', '', $size);
            $lines = $this->breakWords($pdf, $text, $limit);
            if (count($lines) <= 2)
                break;
        }

        if (count($lines) > 2) {                       // still too long: squeeze the rest into line 2
            $lines = [$lines[0], implode(' ', array_slice($lines, 1))];
        }

        $pdf->SetFont('helvetica', '', $size);

        if (count($lines) === 1) {
            $this->put($pdf, $x * $this->k, $y * $this->k, $limit, $lines[0], 'L', 1);
            return;
        }

        $this->put($pdf, $x * $this->k, ($y - 6) * $this->k, $limit, $lines[0], 'L', 1);
        $this->put($pdf, $x * $this->k, ($y + 2) * $this->k, $limit, $lines[1], 'L', 1);
    }

    /** Greedy word wrap using the current font. */
    private function breakWords(Fpdi $pdf, string $text, float $limitMm): array
    {
        $lines = [];
        $cur = '';
        foreach (explode(' ', $text) as $word) {
            $try = $cur === '' ? $word : "$cur $word";
            if ($cur === '' || $pdf->GetStringWidth($try) <= $limitMm) {
                $cur = $try;
            } else {
                $lines[] = $cur;
                $cur = $word;
            }
        }
        $lines[] = $cur;

        return $lines;
    }

    /** Word-wrap across fixed baselines (one y per line). */
    private function wrap(Fpdi $pdf, float $x, array $ys, ?string $text, float $maxW, float $size): void
    {
        $text = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $text)));
        if ($text === '')
            return;

        $pdf->SetFont('helvetica', '', $size);
        $limit = $maxW * $this->k;
        $lines = $this->breakWords($pdf, $text, $limit);

        foreach (array_slice($lines, 0, count($ys)) as $i => $line) {
            $this->put($pdf, $x * $this->k, $ys[$i] * $this->k, $maxW * $this->k + 5, $line, 'L');
        }
    }

    /**
     * Right-aligned money value with a peso sign. TCPDF's built-in Helvetica has no peso glyph,
     * so DejaVu Sans (bundled with TCPDF) is used. Set $peso to '' to print the number only,
     * or to 'P ' if you prefer a plain letter P.
     */
    private function money(Fpdi $pdf, float $xRight, float $yc, ?string $value, string $peso = '₱ '): void
    {
        $value = trim((string) $value);
        if ($value === '')
            return;
        $clean = str_replace(',', '', $value);
        if (is_numeric($clean))
            $value = number_format((float) $clean, 2);

        $pdf->SetFont('dejavusans', '', 10);
        $w = 200 * $this->k;
        $this->put($pdf, $xRight * $this->k - $w, $yc * $this->k, $w, $peso . $value, 'R');
    }

    /** One character centred in each 18px box ($xs = left edges). */
    private function cells(Fpdi $pdf, array $xs, float $yc, ?string $text, float $size = 11, float $w = 18): void
    {
        if ($text === null || $text === '')
            return;
        $pdf->SetFont('helvetica', '', $size);
        foreach (mb_str_split(mb_strtoupper($text)) as $i => $ch) {
            if (!isset($xs[$i]))
                break;
            $this->put($pdf, $xs[$i] * $this->k, $yc * $this->k, $w * $this->k, $ch, 'C');
        }
    }

    private function digits(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    /** $groups = [2-box, 9-box, 1-box]; needs 12 digits. */
    private function pin(Fpdi $pdf, array $groups, float $yc, ?string $pin, float $size = 11): void
    {
        $digits = $this->digits($pin);
        if (strlen($digits) < 12)
            return;
        $this->cells($pdf, $groups[0], $yc, substr($digits, 0, 2), $size);
        $this->cells($pdf, $groups[1], $yc, substr($digits, 2, 9), $size);
        $this->cells($pdf, $groups[2], $yc, substr($digits, 11, 1), $size);
    }

    /** $cells = ['m'=>[..],'d'=>[..],'y'=>[..]]; value is Y-m-d from <input type=date>. */
    private function date(Fpdi $pdf, array $cells, float $yc, ?string $value, float $size = 11, bool $blankDay = false): void
    {
        if (!$value || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m))
            return;
        $this->cells($pdf, $cells['m'], $yc, $m[2], $size);
        if (!$blankDay) {
            $this->cells($pdf, $cells['d'], $yc, $m[3], $size);
        }
        $this->cells($pdf, $cells['y'], $yc, $m[1], $size);
    }

    /**
     * "Date signed" = DATE DISCHARGED month + year.
     * The day is blank by default; pass $withDay = true to print the full date (used everywhere now).
     */
    private function signedDate(Fpdi $pdf, float $yc, ?string $discharged, ?array $cells = null, float $size = 11, bool $withDay = false): void
    {
        $cells ??= self::SIGNED;
        if (!$discharged || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $discharged, $m))
            return;
        $this->cells($pdf, $cells['m'], $yc, $m[2], $size);
        if ($withDay) {
            $this->cells($pdf, $cells['d'], $yc, $m[3], $size);
        }
        $this->cells($pdf, $cells['y'], $yc, $m[1], $size);
    }

    /** $value "HH:MM" (24h from <input type=time>) → hour/min cells + AM/PM check. */
    private function time(Fpdi $pdf, float $yc, float $amX, float $pmX, ?string $value, ?float $ampmY = null): void
    {
        if (!$value || !preg_match('/^(\d{1,2}):(\d{2})/', $value, $m))
            return;

        $h24 = (int) $m[1];
        $ampm = $h24 >= 12 ? 'PM' : 'AM';
        $h12 = $h24 % 12 ?: 12;

        $this->cells($pdf, [571, 589], $yc, str_pad((string) $h12, 2, '0', STR_PAD_LEFT), 10);
        $this->cells($pdf, [617, 635], $yc, $m[2], 10);
        $this->check($pdf, $ampm === 'AM' ? $amX : $pmX, $ampmY ?? $yc);
    }

    private function mdy(?string $value): string
    {
        return ($value && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m))
            ? "{$m[2]}-{$m[3]}-{$m[1]}" : '';
    }

    /** Full name; with $initial = true the middle name becomes an initial (e.g. "JUAN D. CRUZ"). */
    private function fullName(array $d, string $prefix, bool $initial = false): string
    {
        $mid = trim((string) ($d["{$prefix}_middle_name"] ?? ''));
        if ($initial && $mid !== '') {
            $mid = mb_strtoupper(mb_substr($mid, 0, 1)) . '.';
        }

        return trim(implode(' ', array_filter([
            $d["{$prefix}_first_name"] ?? '',
            $mid,
            $d["{$prefix}_last_name"] ?? '',
            $d["{$prefix}_extension"] ?? '',
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