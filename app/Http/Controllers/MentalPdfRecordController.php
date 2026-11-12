<?php

namespace App\Http\Controllers;

use App\Models\MentalPdfRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use setasign\Fpdi\Tcpdf\Fpdi;

class MentalPdfRecordController extends Controller
{
    /** CSF / CF-2 pages: coordinates are "pixels" of a page rendered 893px wide. */
    private const REF_W = 893;

    /** Annex D (Letter portrait): coordinates are pixels of the page rendered 950px wide. */
    private const REF_W_D = 950;

    /** Annex H (Letter landscape): pixels of the page rendered 1240px wide. */
    private const REF_W_H = 1240;

    /** Annex J.1 / K / L.1 (A4 portrait): pixels of the page rendered 900px wide. */
    private const REF_W_A4 = 900;

    /**
     * Template pages (csf_cf2_merged_removed.pdf):
     * 1 CSF, 2 CF-2 front, 3 CF-2 back, 4 Annex D, 5 Annex H, 6 Annex J.1, 7-8 Annex K, 9 Annex L.1
     */
    private const LAST_PAGE = 9;

    /** When the patient IS the member: copy member PIN into the dependent PIN boxes? */
    private const FILL_DEPENDENT_PIN_WHEN_MEMBER = true;

    /** Fixed values used when the field is left empty. */
    private const DEFAULTS = [
        'professional_accreditation' => '1501-1638387-1',
        'professional_name' => 'DR. GLADYS LOURDES BENGCO, M.D.',

        // CF-2 page 3 (item 10) health care professional
        'cf2_professional_accreditation' => '1501-1638387-1',
        'cf2_professional_name' => 'DR. GLADYS LOURDES BENGCO, M.D.',

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
     |  CF-2 BACK (page 3), same layout as the dental form
     * ------------------------------------------------------------- */
    private const CF2B_ACCRED = [[145, 163, 181, 199], [228, 246, 263, 281, 299, 317, 335], [364]];
    private const CF2B_ACCRED_Y = 104;
    private const CF2B_ACCRED_SIZE = 10;
    private const CF2B_ACCRED_DY = -2;
    private const CF2B_DATE_SIZE = 10;
    private const CF2B_DATE_DY = -3;
    private const CF2B_NAME_DY = 1;
    private const CF2B_PROF_NAME = [83, 126, 340];
    private const CF2B_PROF_DATE_Y = 170;
    private const CF2B_SIGNED = ['m' => [183, 201], 'd' => [229, 247], 'y' => [276, 294, 311, 329]];

    private const CF2B_BENEFIT_BOX = [60, 460];

    private const CF2B_PATIENT_NAME = [47, 1058, 345];
    private const CF2B_PATIENT_DATE_Y = 1107;

    private const CF2B_HCI_NAME = [47, 1291, 300];
    private const CF2B_HCI_DESIG = [360, 1291, 240];
    private const CF2B_HCI_DATE_Y = 1298;
    private const CF2B_SIGNED_IV = ['m' => [686, 704], 'd' => [732, 750], 'y' => [778, 796, 814, 832]];

    private const CF2B_REL = [
        'spouse' => [240, 1146],
        'child' => [308, 1146],
        'parent' => [368, 1146],
        'sibling' => [240, 1163],
        'others' => [308, 1163],
    ];
    private const CF2B_REL_OTHER = [397, 1160, 105];
    private const CF2B_REASON = ['incapacitated' => [240, 1186], 'other' => [240, 1198]];
    private const CF2B_REASON_OTHER = [335, 1195, 170];
    private const CF2B_WHO = ['patient' => [530, 1186], 'representative' => [530, 1199]];

    private const COPAY_COLS = [254, 393, 542];
    private const COPAY_HCI_Y = 722;
    private const COPAY_PF_Y = 790;
    private const COPAY_HCI_AMT = [675, 695, 140];
    private const COPAY_PF_AMT = [695, 766, 140];
    private const COPAY_HCI_OTHERS = [627, 745];

    /** CF-2 front, item 8b: Z-Benefit Package Code (free input). */
    private const Z_BENEFIT_POS = [430, 1065, 205];

    /* ---------------------------------------------------------------
     |  ANNEX D: MENTAL HEALTH PASSPORT (PDF page 4)
     |  ESTIMATED from the template image, px @ 950 wide (Letter portrait).
     |  Open the PDF with ?grid=1 and adjust any value that is off.
     |  Text lines are [x, y, maxW]. PIN boxes are CENTRES, not left edges.
     * ------------------------------------------------------------- */
    private const AD_CASE_NO = [208, 181, 175];
    private const AD_FACILITY = [118, 226, 370];
    private const AD_ASSESS_DATE = [510, 226, 335];
    private const AD_ADDRESS = [215, 252, 415];
    private const AD_AGE = [702, 262, 85];

    /** A. Patient */
    private const AD_PATIENT_NAME = [253, 308, 380];
    private const AD_SEX = ['male' => [657, 305], 'female' => [736, 305]];
    private const AD_PIN_PATIENT_Y = 338;
    private const AD_PIN_PATIENT = [[510.5, 533.5], [577.6, 601, 624.3, 647.6, 670.9, 694.2, 717.5, 740.8, 764.1], [808.5]];

    /** B. Member (answer only if patient is a dependent, otherwise "SAME AS ABOVE") */
    private const AD_MEMBER_NAME = [253, 432, 590];
    private const AD_PIN_MEMBER_Y = 463;
    private const AD_PIN_MEMBER = [[508.5, 528.5], [559, 577, 595, 613, 631, 649, 667, 685, 702.6], [730.5]];

    /** A. Follow-up visits: 12 rows. Centre x of the two date columns, first row centre and row pitch. */
    private const AD_FU_X = [264, 399.5];
    private const AD_FU_W = 120;
    private const AD_FU_Y0 = 626;
    private const AD_FU_DY = 22.36;

    /** B. Medications: 7 rows. Name (left edge), dosage / preparation / date given (centre x). */
    private const AD_MED_NAME = [112, 198];
    private const AD_MED_DOSAGE_X = 359.5;
    private const AD_MED_PREP_X = 461;
    private const AD_MED_DATE_X = 569.5;
    private const AD_MED_Y = [1014, 1036, 1058, 1080, 1102, 1124, 1146];

    /* ---------------------------------------------------------------
     |  ANNEX H: TRANSMITTAL FORM (PDF page 5), px @ 1240 wide (Letter landscape)
     |  Measured from the template. Text lines are [x, y, maxW]; table columns are CENTRES.
     * ------------------------------------------------------------- */
    private const AH_FACILITY = [94, 272, 520];
    private const AH_ADDRESS = [643, 272, 480];

    /** First table row centre and row pitch (5 rows, we fill row 1 only). */
    private const AH_ROW_Y0 = 517.5;
    private const AH_ROW_DY = 21;
    private const AH_COL_CASE = [118, 118];       // left edge, maxW
    private const AH_COL_PIN = [358, 225];        // centre, maxW
    private const AH_COL_FROM = [556, 150];
    private const AH_COL_TO = [722, 160];
    private const AH_COL_CODE = [884, 145];
    private const AH_COL_REMARKS = [1048, 150];
    private const AH_ROWS = 5;

    private const AH_SIGN_NAME = [100, 670, 245];
    private const AH_SIGN_DESIG = [360, 677, 245];
    private const AH_SIGN_DATE = [360, 725, 245];

    /* ---------------------------------------------------------------
     |  ANNEX J.1, K and L.1 (A4 portrait), px @ 900 wide, measured from the template.
     * ------------------------------------------------------------- */

    /** PIN boxes shared by J.1 and L.1 (box CENTRES). */
    private const A4_PIN = [[477, 495], [529, 547.1, 565.2, 583.3, 601.4, 619.5, 637.6, 655.7, 673.8], [708]];

    /** Annex J.1 (page 6) */
    private const AJ_CASE = [172, 230, 180];
    private const AJ_FACILITY = [305, 258, 520];
    private const AJ_ADDRESS = [242, 280, 580];
    private const AJ_NAME = [213, 321, 405];
    private const AJ_SEX = ['male' => [644, 322], 'female' => [725, 322]];
    private const AJ_PIN_Y = 350;
    private const AJ_MEMBER_NAME = [213, 441, 600];
    private const AJ_MEMBER_PIN_Y = 464;
    private const AJ_MEDS = [305, 976.5, 515];

    private const AJ_BOX = [
        'screening' => [99.5, 638],
        'assessment' => [99.5, 660],
        'diagnostics' => [99.5, 681.5],
        'cbc' => [313, 681.5],
        'urinalysis' => [313, 702.5],
        'fbg' => [313, 724],
        'lipid' => [313, 745.5],
        'renal' => [313, 766.5],
        'creatinine' => [313, 788],
        'cxr' => [313, 809.5],
        'followup' => [99.5, 831],
        'medicines' => [99.5, 976.5],
    ];
    private const AJ_FU_X = 505;
    private const AJ_FU_W = 200;
    private const AJ_FU_Y = [831, 854, 878.5, 903, 927.5, 952];

    private const AJ_CONFORME_NAME = [92, 1042, 345];
    private const AJ_CONFORME_DATE = [318, 1131, 120];
    private const AJ_PHYSICIAN_NAME = [455, 1042, 370];
    private const AJ_PHYSICIAN_DATE = [681, 1131, 120];
    private const AJ_PHYSICIAN_ACC_Y = 1106;
    /** 4 digits | dot box | 7 digits | dot box | 1 digit (box CENTRES). */
    private const AJ_PHYSICIAN_ACC = [[539.3, 558.3, 577.2, 596.1], [634, 652.9, 671.8, 690.7, 709.5, 728.3, 747.2], [808]];

    /** Annex K (pages 7 and 8) */
    private const AK1_X = 117.5;
    private const AK1_AGE = ['19_below' => 482, '20_35' => 502, '36_45' => 524, '46_55' => 544, '56_65' => 564, 'above_65' => 585];
    private const AK1_SEX = ['male' => 647, 'female' => 668];
    private const AK1_Q3 = ['adequate' => 812, 'inadequate' => 832, 'dont_know' => 854];
    private const AK1_Q4 = ['excellent' => 936, 'satisfactory' => 956, 'unsatisfactory' => 977, 'dont_know' => 998];
    private const AK1_Q5 = ['excellent' => 1080, 'satisfactory' => 1101, 'unsatisfactory' => 1122, 'dont_know' => 1142];

    private const AK2_X = 121;
    private const AK2_Q6 = ['less_than_half' => 182, 'half' => 202, 'more_than_half' => 224, 'dont_know' => 244];
    private const AK2_Q7 = ['excellent' => 327, 'satisfactory' => 348, 'unsatisfactory' => 368, 'dont_know' => 388];
    private const AK2_COMMENT = [112, 705];
    private const AK2_COMMENT_Y = [447, 469, 489, 509, 529];
    private const AK2_NAME = [671.5, 686, 300];
    private const AK2_DATE = [753, 771, 145];

    /** Annex L.1 (page 9) */
    private const AL_CASE = [162, 234, 125];
    private const AL_FACILITY = [305, 258, 485];
    private const AL_ADDRESS = [242, 300, 550];
    private const AL_NAME = [213, 361, 405];
    private const AL_SEX = ['male' => [644, 364], 'female' => [715, 364]];
    private const AL_PIN_Y = 392;
    private const AL_MEMBER_NAME = [213, 482, 585];
    private const AL_MEMBER_PIN_Y = 512;

    private const AL_CHECK_X = 735.5;
    private const AL_CHECK_Y = [641.5, 662.5, 684, 705.5, 727, 748.5, 770];

    private const AL_PHYSICIAN_NAME = [91, 834, 345];
    private const AL_PHYSICIAN_DATE = [316, 938, 120];
    private const AL_HEAD_NAME = [450, 834, 345];
    private const AL_HEAD_DATE = [676, 938, 120];
    private const AL_ACC_Y = 906;
    private const AL_ACC_LEFT = [[185.9, 204.7, 223.8, 242.8], [280.6, 299.5, 318.4, 337.3, 356.2, 375, 393.9], [431.7]];
    private const AL_ACC_RIGHT = [[545.7, 564.5, 583.5, 602.3], [640, 659, 677.9, 696.7, 715.8, 734.8, 753.7], [791.5]];

    private const AL_CONFORME_NAME = [455, 1024, 340];
    private const AL_CONFORME_DATE = [681, 1088, 115];

    private float $k = 1;

    /** Size (pt) of the check mark; smaller on the A4 annexes whose boxes are smaller. */
    private float $checkSize = 11;

    /** Live nudges for page 3 (px). Override in the URL: ?acc_dy=-2&date_dy=-3&name_dy=-4 */
    private array $nudge = ['acc' => self::CF2B_ACCRED_DY, 'date' => self::CF2B_DATE_DY, 'name' => self::CF2B_NAME_DY];

   private function listing()
    {
        return MentalPdfRecord::query()
            ->with('mhg2:id,mental_pdf_record_id,updated_at')
            ->latest()->limit(100)
            ->get(['id', 'record_no', 'patient_name', 'created_at']);
    }

    public function index()
    {
        return Inertia::render('MentalPdfRecords/Index', [
            'records' => $this->listing(),
            'record' => null,
        ]);
    }

    public function edit(MentalPdfRecord $record)
    {
        return Inertia::render('MentalPdfRecords/Index', [
            'records' => $this->listing(),
            'record' => $record->load('mhg2'),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'patient_name' => ['nullable', 'string', 'max:255'],
            'form_data' => ['required', 'array'],
        ]);

        // The name shown in the records table is ALWAYS rebuilt from the current form data.
        $fd = $this->resolve($data['form_data']);
        $data['patient_name'] = trim(
            trim((string) ($fd['patient_last_name'] ?? '')) . ', ' . trim((string) ($fd['patient_first_name'] ?? '')),
            ' ,'
        ) ?: null;

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $record = MentalPdfRecord::create($data);
        $record->update([
            'record_no' => 'MH-' . now()->format('Ym') . '-' . str_pad((string) $record->id, 5, '0', STR_PAD_LEFT),
        ]);

        return redirect()->route('mental-pdf-records.edit', $record);
    }

    public function update(Request $request, MentalPdfRecord $record)
    {
        $record->update($this->validated($request));

        return redirect()->route('mental-pdf-records.edit', $record);
    }

    /** Apply fixed defaults and the "patient is the member" rule. */
    private function resolve(array $d): array
    {
        foreach (self::DEFAULTS as $key => $value) {
            if (blank($d[$key] ?? null)) {
                $d[$key] = $value;
            }
        }

        // Signer is automatic: a representative name means the representative signs, otherwise the patient signs.
        $hasRep = ($d['has_representative'] ?? 'no') === 'yes' && filled($d['representative_name'] ?? null);
        $d['part3_signer'] = $hasRep ? 'representative' : 'patient';
        if (!$hasRep) {
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

        // Annex D: always 12 follow-up rows and 7 medication rows
        $d['follow_ups'] = $this->fixedRows($d['follow_ups'] ?? null, 12, ['visit', 'next']);
        $d['medications'] = $this->fixedRows($d['medications'] ?? null, 7, ['name', 'dosage', 'preparation', 'date']);

        // Annex J.1: 6 follow-up dates. Empty rows fall back to the Annex D "date of visit" of the same number.
        $j1 = is_array($d['j1_follow_ups'] ?? null) ? array_values($d['j1_follow_ups']) : [];
        $dates = [];
        for ($i = 0; $i < 6; $i++) {
            $typed = trim((string) ($j1[$i] ?? ''));
            $dates[] = $typed !== '' ? $typed : $d['follow_ups'][$i]['visit'];
        }
        $d['j1_follow_ups'] = $dates;

        // Annex L.1: seven requirement rows, every one ticked unless explicitly switched off.
        $l1 = is_array($d['l1_checks'] ?? null) ? array_values($d['l1_checks']) : [];
        $d['l1_checks'] = [];
        for ($i = 0; $i < 7; $i++) {
            $d['l1_checks'][] = array_key_exists($i, $l1) ? filter_var($l1[$i], FILTER_VALIDATE_BOOLEAN) : true;
        }

        return $d;
    }

    /** Normalize a list of rows to exactly $count rows with the given string keys. */
    private function fixedRows($rows, int $count, array $keys): array
    {
        $rows = is_array($rows) ? array_values($rows) : [];
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $r = is_array($rows[$i] ?? null) ? $rows[$i] : [];
            $row = [];
            foreach ($keys as $key) {
                $row[$key] = trim((string) ($r[$key] ?? ''));
            }
            $out[] = $row;
        }

        return $out;
    }

    /** CF-2 item 7: three rows per group, all free input. */
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

    public function preview(Request $request, MentalPdfRecord $record)
    {
        $template = collect([
            storage_path('app/pdf-templates/csf_cf2_merged_removed.pdf'),
            storage_path('app/pdf-templates/csf_cf2_mental.pdf'),
            storage_path('app/pdf-template/csf_cf2_merged_removed.pdf'),
            storage_path('app/private/pdf-templates/csf_cf2_merged_removed.pdf'),
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

        $pageCount = min($pdf->setSourceFile($template), self::LAST_PAGE);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tpl = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tpl);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            $refW = match (true) {
                $pageNo === 4 => self::REF_W_D,
                $pageNo === 5 => self::REF_W_H,
                $pageNo >= 6 => self::REF_W_A4,
                default => self::REF_W,
            };
            $this->k = $size['width'] / $refW;
            $this->checkSize = $pageNo >= 6 ? 9 : 11;
            $pdf->SetTextColor(0, 0, 0);

            if ($request->boolean('grid')) {
                $this->grid($pdf, $size, $refW);
            }

            match ($pageNo) {
                1 => $this->pageCsf($pdf, $d),
                2 => $this->pageCf2Front($pdf, $d),
                3 => $this->pageCf2Back($pdf, $d),
                4 => $this->pageAnnexD($pdf, $d),
                5 => $this->pageAnnexH($pdf, $d),
                6 => $this->pageAnnexJ1($pdf, $d),
                7 => $this->pageAnnexK1($pdf, $d),
                8 => $this->pageAnnexK2($pdf, $d),
                9 => $this->pageAnnexL1($pdf, $d),
                default => null,
            };
        }

        $content = $pdf->Output('mental-csf-cf2-' . $record->id . '.pdf', 'S');

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="mental-csf-cf2-' . $record->id . '.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /* ---------------------------------------------------------------
     |  CSF PAGE MAPS  (x = left edge, y = vertical CENTER of the text, px @ 893 wide)
     * ------------------------------------------------------------- */

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

        // 4. Dependent PIN
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

        // 7. Confinement period / 8. Patient DOB
        $this->date($pdf, self::ADMITTED, 457, $d['date_admitted'] ?? '');
        $this->date($pdf, self::DISCHARGED, 457, $d['date_discharged'] ?? '');
        $this->date($pdf, self::DOB_PATIENT, 457, $d['patient_birthdate'] ?? '');

        // 9. Certification of member
        $this->partI9($pdf, $d);

        // ---------- PART II (employer): intentionally left blank ----------

        // ---------- PART III ----------
        $this->partIII($pdf, $d);

        // ---------- PART IV ----------
        $this->cells($pdf, self::ACCRED[0], 1115, substr($this->digits($d['professional_accreditation']), 0, 4), 11);
        $this->cells($pdf, self::ACCRED[1], 1115, substr($this->digits($d['professional_accreditation']), 4, 7), 11);
        $this->cells($pdf, self::ACCRED[2], 1115, substr($this->digits($d['professional_accreditation']), 11, 1), 11);
        $this->txt($pdf, 385, 1112, $d['professional_name'], 10, 225);
        $this->signedDate($pdf, 1115, $d['date_discharged'] ?? '', self::SIGNED, 11, true);

        // ---------- PART V ----------
        $this->txt($pdf, 415, 1252, $d['first_case_rate'] ?? '', 11, 140);
        $this->txt($pdf, 680, 1255, $d['second_case_rate'] ?? '', 11, 170);
        $this->txt($pdf, 40, 1293, $d['hci_rep_name'], 10, 340);
        $this->txt($pdf, 400, 1293, $d['hci_rep_designation'], 10, 200);
        $this->signedDate($pdf, 1295, $d['date_discharged'] ?? '', self::SIGNED, 11, true);
    }

    /** Annex D (Mental Health Passport): PDF page 4. */
    private function pageAnnexD(Fpdi $pdf, array $d): void
    {
        $patientIsMember = strtolower($d['relationship_to_member'] ?? '') === 'member';

        // Header block
        [$x, $y, $w] = self::AD_CASE_NO;
        $this->txt($pdf, $x, $y, $d['case_no'] ?? '', 10, $w);

        [$x, $y, $w] = self::AD_FACILITY;
        $this->txt($pdf, $x, $y, $d['facility_name'] ?? '', 10, $w);

        // Date of assessment (mm/dd/yyyy). Falls back to the date admitted when left empty.
        [$x, $y, $w] = self::AD_ASSESS_DATE;
        $this->txt($pdf, $x, $y, $this->mdySlash($this->assessmentDate($d)), 10, $w);

        // Address of HF (street, city, province)
        [$x, $y, $w] = self::AD_ADDRESS;
        $this->txt($pdf, $x, $y, $this->facilityAddress($d), 9, $w);

        // Age (typed value wins, otherwise computed from the patient birthdate)
        [$x, $y, $w] = self::AD_AGE;
        $this->txt($pdf, $x, $y, $this->age($d), 10, $w);

        // ---------- A. PATIENT ----------
        // "Last Name, First Name, Suffix, Middle Name"
        [$x, $y, $w] = self::AD_PATIENT_NAME;
        $this->txt($pdf, $x, $y, $this->commaName($d, 'patient', ['last_name', 'first_name', 'extension', 'middle_name']), 10, $w);

        match (strtolower($d['patient_sex'] ?? '')) {
            'male' => $this->check($pdf, ...self::AD_SEX['male']),
            'female' => $this->check($pdf, ...self::AD_SEX['female']),
            default => null,
        };

        // Patient PIN: the member PIN when the patient is the member, otherwise the dependent PIN
        $this->pinCentres($pdf, self::AD_PIN_PATIENT, self::AD_PIN_PATIENT_Y, $this->patientPin($d));

        // ---------- B. MEMBER ----------
        [$x, $y, $w] = self::AD_MEMBER_NAME;
        if ($patientIsMember) {
            // The form says: answer only if the patient is a dependent, otherwise write "same as above"
            $this->txt($pdf, $x, $y, 'SAME AS ABOVE', 10, $w);
        } else {
            // "Last Name, First Name, Middle Name, Suffix"
            $this->txt($pdf, $x, $y, $this->commaName($d, 'member', ['last_name', 'first_name', 'middle_name', 'extension']), 10, $w);
            $this->pinCentres($pdf, self::AD_PIN_MEMBER, self::AD_PIN_MEMBER_Y, $d['member_pin'] ?? '');
        }

        // ---------- A. FOLLOW-UP VISITS ----------
        foreach ($d['follow_ups'] as $i => $row) {
            $yc = self::AD_FU_Y0 + self::AD_FU_DY * $i;
            $this->centered($pdf, self::AD_FU_X[0], $yc, $this->mdySlash($row['visit']), 10, self::AD_FU_W);
            $this->centered($pdf, self::AD_FU_X[1], $yc, $this->mdySlash($row['next']), 10, self::AD_FU_W);
        }

        // ---------- B. MEDICATIONS ----------
        foreach ($d['medications'] as $i => $row) {
            $yc = self::AD_MED_Y[$i] ?? null;
            if ($yc === null) {
                break;
            }
            $this->txt($pdf, self::AD_MED_NAME[0], $yc, $row['name'], 9, self::AD_MED_NAME[1]);
            $this->centered($pdf, self::AD_MED_DOSAGE_X, $yc, $row['dosage'], 9, 78);
            $this->centered($pdf, self::AD_MED_PREP_X, $yc, $row['preparation'], 9, 110);
            $this->centered($pdf, self::AD_MED_DATE_X, $yc, $this->mdySlash($row['date']), 9, 92);
        }
    }

    /** Annex H (Transmittal Form of Claims): PDF page 5, one claim in row 1. */
    private function pageAnnexH(Fpdi $pdf, array $d): void
    {
        [$x, $y, $w] = self::AH_FACILITY;
        $this->txt($pdf, $x, $y, $d['facility_name'] ?? '', 10, $w);

        [$x, $y, $w] = self::AH_ADDRESS;
        $this->txt($pdf, $x, $y, $this->facilityAddress($d), 9, $w);

        $y = self::AH_ROW_Y0;

        // Case number
        [$x, $w] = self::AH_COL_CASE;
        $this->txt($pdf, $x, $y, $d['case_no'] ?? '', 10, $w);

        // PIN of the patient (the dependent PIN when the patient is a dependent)
        [$xc, $w] = self::AH_COL_PIN;
        $this->centered($pdf, $xc, $y, $this->formatPin($this->patientPin($d)), 10, $w);

        // Period of availment: date admitted to date discharged (mm/dd/yyyy)
        [$xc, $w] = self::AH_COL_FROM;
        $this->centered($pdf, $xc, $y, $this->mdySlash($d['date_admitted'] ?? ''), 10, $w);
        [$xc, $w] = self::AH_COL_TO;
        $this->centered($pdf, $xc, $y, $this->mdySlash($d['date_discharged'] ?? ''), 10, $w);

        // Package code (e.g. MHG1) and remarks
        [$xc, $w] = self::AH_COL_CODE;
        $this->centered($pdf, $xc, $y, $d['first_case_rate'] ?? '', 10, $w);
        [$xc, $w] = self::AH_COL_REMARKS;
        $this->remarksColumn($pdf, $d['h_remarks'] ?? '');

        // Certified correct by the authorized representative of the HF
        [$x, $yy, $w] = self::AH_SIGN_NAME;
        $this->txt($pdf, $x, $yy, $d['hci_rep_name'] ?? '', 10, $w);
        [$x, $yy, $w] = self::AH_SIGN_DESIG;
        $this->txt($pdf, $x, $yy, $d['hci_rep_designation'] ?? '', 10, $w);
        [$x, $yy, $w] = self::AH_SIGN_DATE;
        $this->txt($pdf, $x, $yy, $this->mdySlash($d['date_discharged'] ?? ''), 10, $w);
    }

    /**
     * Remarks: wrapped inside the Remarks column, one line per table row (rows 1 to 5).
     * The font shrinks (8pt down to 5.5pt) until everything fits in 5 lines.
     */
    private function remarksColumn(Fpdi $pdf, ?string $text): void
    {
        $text = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $text)));
        if ($text === '') {
            return;
        }

        [$xc, $w] = self::AH_COL_REMARKS;
        $limit = $w * $this->k;
        $lines = [];

        for ($size = 8.0; $size >= 5.5; $size -= 0.5) {
            $pdf->SetFont('helvetica', '', $size);
            $lines = $this->breakLongWords($pdf, $this->breakWords($pdf, $text, $limit), $limit);
            if (count($lines) <= self::AH_ROWS) {
                break;
            }
        }

        // Still too long at the smallest size: keep 5 lines, the last one takes the rest (shrunk to fit)
        if (count($lines) > self::AH_ROWS) {
            $rest = implode(' ', array_slice($lines, self::AH_ROWS - 1));
            $lines = array_slice($lines, 0, self::AH_ROWS - 1);
            $lines[] = $rest;
        }

        foreach ($lines as $i => $line) {
            $yc = self::AH_ROW_Y0 + self::AH_ROW_DY * $i;
            $this->put($pdf, ($xc - $w / 2) * $this->k, $yc * $this->k, $limit, $line, 'C', 1);
        }
    }

    /** Split any single word that is wider than the limit (e.g. a very long unbroken string). */
    private function breakLongWords(Fpdi $pdf, array $lines, float $limitMm): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ($pdf->GetStringWidth($line) <= $limitMm) {
                $out[] = $line;
                continue;
            }
            $cur = '';
            foreach (mb_str_split($line) as $ch) {
                if ($cur !== '' && $pdf->GetStringWidth($cur . $ch) > $limitMm) {
                    $out[] = $cur;
                    $cur = '';
                }
                $cur .= $ch;
            }
            if ($cur !== '') {
                $out[] = $cur;
            }
        }

        return $out;
    }

    /** Annex J.1 (Checklist of Mandatory and Other Services, Tranche 1): PDF page 6. */
    private function pageAnnexJ1(Fpdi $pdf, array $d): void
    {
        $this->a4Header($pdf, $d, [
            'case' => self::AJ_CASE,
            'facility' => self::AJ_FACILITY,
            'address' => self::AJ_ADDRESS,
            'name' => self::AJ_NAME,
            'sex' => self::AJ_SEX,
            'pinY' => self::AJ_PIN_Y,
            'memberName' => self::AJ_MEMBER_NAME,
            'memberPinY' => self::AJ_MEMBER_PIN_Y,
        ]);

        // Follow-up dates (typed here, otherwise taken from Annex D)
        $dates = $d['j1_follow_ups'];
        $hasFollowUp = count(array_filter($dates, fn($v) => trim((string) $v) !== '')) > 0;

        // Medicines are "provided" when ticked or when Annex D lists at least one medicine
        $hasMeds = count(array_filter($d['medications'], fn($r) => $r['name'] !== '')) > 0;

        $diag = ['cbc', 'urinalysis', 'fbg', 'lipid', 'renal', 'creatinine', 'cxr'];
        $on = fn(string $k) => filter_var($d["j1_{$k}"] ?? false, FILTER_VALIDATE_BOOLEAN);

        $anyDiag = false;
        foreach ($diag as $k) {
            if ($on($k)) {
                $anyDiag = true;
                $this->check($pdf, ...self::AJ_BOX[$k]);
            }
        }

        if ($on('screening')) {
            $this->check($pdf, ...self::AJ_BOX['screening']);
        }
        if ($on('assessment')) {
            $this->check($pdf, ...self::AJ_BOX['assessment']);
        }
        if ($anyDiag) {
            $this->check($pdf, ...self::AJ_BOX['diagnostics']);
        }
        if ($hasFollowUp) {
            $this->check($pdf, ...self::AJ_BOX['followup']);
        }
        if ($on('medicines') || $hasMeds) {
            $this->check($pdf, ...self::AJ_BOX['medicines']);

            // Typed medicine names win; otherwise use the names listed in Annex D
            $list = trim((string) ($d['j1_medicines_list'] ?? ''));
            if ($list === '') {
                $list = implode(', ', array_filter(array_map(fn($r) => $r['name'], $d['medications'])));
            }

            [$x, $y, $w] = self::AJ_MEDS;
            $this->txt($pdf, $x, $y, $list, 10, $w);
        }

        foreach ($dates as $i => $date) {
            $this->txt($pdf, self::AJ_FU_X, self::AJ_FU_Y[$i], $this->mdySlash($date), 10, self::AJ_FU_W);
        }

        $signed = $this->mdySlash($d['date_discharged'] ?? '');

        // Conforme by: parent / guardian / patient
        [$x, $y, $w] = self::AJ_CONFORME_NAME;
        $this->txt($pdf, $x, $y, $this->signerName($d), 10, $w);
        [$x, $y, $w] = self::AJ_CONFORME_DATE;
        $this->txt($pdf, $x, $y, $signed, 10, $w);

        // Certified correct by: attending physician
        [$x, $y, $w] = self::AJ_PHYSICIAN_NAME;
        $this->txt($pdf, $x, $y, $d['cf2_professional_name'] ?? '', 10, $w);
        $this->pinCentres($pdf, self::AJ_PHYSICIAN_ACC, self::AJ_PHYSICIAN_ACC_Y, $d['cf2_professional_accreditation'] ?? '');
        [$x, $y, $w] = self::AJ_PHYSICIAN_DATE;
        $this->txt($pdf, $x, $y, $signed, 10, $w);
    }

    /** Annex K, page 1 of 2 (PDF page 7): respondent age, sex and questions 3 to 5. */
    private function pageAnnexK1(Fpdi $pdf, array $d): void
    {
        $x = self::AK1_X;

        $age = $this->ageGroup($d);
        if (isset(self::AK1_AGE[$age])) {
            $this->check($pdf, $x, self::AK1_AGE[$age]);
        }

        $sex = $this->respondentSex($d);
        if (isset(self::AK1_SEX[$sex])) {
            $this->check($pdf, $x, self::AK1_SEX[$sex]);
        }

        $this->tickChoice($pdf, $x, self::AK1_Q3, $d['k_q3'] ?? '');
        $this->tickChoice($pdf, $x, self::AK1_Q4, $d['k_q4'] ?? '');
        $this->tickChoice($pdf, $x, self::AK1_Q5, $d['k_q5'] ?? '');
    }

    /** Annex K, page 2 of 2 (PDF page 8): questions 6 to 8, signature name and date. */
    private function pageAnnexK2(Fpdi $pdf, array $d): void
    {
        $x = self::AK2_X;
        $this->tickChoice($pdf, $x, self::AK2_Q6, $d['k_q6'] ?? '');
        $this->tickChoice($pdf, $x, self::AK2_Q7, $d['k_q7'] ?? '');

        // 8. Comments, wrapped over the five lines
        [$cx, $cw] = self::AK2_COMMENT;
        $this->wrap($pdf, $cx, self::AK2_COMMENT_Y, $d['k_comments'] ?? '', $cw, 10);

        // Signature over printed name (typed name wins, otherwise the signer of the claim)
        $name = filled($d['k_name'] ?? null) ? $d['k_name'] : $this->signerName($d);
        [$xc, $y, $w] = self::AK2_NAME;
        $this->centered($pdf, $xc, $y, $name, 10, $w);

        // Date accomplished (typed date wins, otherwise the date discharged)
        $date = filled($d['k_date'] ?? null) ? $d['k_date'] : ($d['date_discharged'] ?? '');
        [$xc, $y, $w] = self::AK2_DATE;
        $this->centered($pdf, $xc, $y, $this->mdySlash($date), 10, $w);
    }

    /** Annex L.1 (Checklist of Requirements for Reimbursement, Tranche 1): PDF page 9. */
    private function pageAnnexL1(Fpdi $pdf, array $d): void
    {
        $this->a4Header($pdf, $d, [
            'case' => self::AL_CASE,
            'facility' => self::AL_FACILITY,
            'address' => self::AL_ADDRESS,
            'name' => self::AL_NAME,
            'sex' => self::AL_SEX,
            'pinY' => self::AL_PIN_Y,
            'memberName' => self::AL_MEMBER_NAME,
            'memberPinY' => self::AL_MEMBER_PIN_Y,
        ]);

        // Requirements 1 to 7
        foreach ($d['l1_checks'] as $i => $on) {
            if ($on && isset(self::AL_CHECK_Y[$i])) {
                $this->check($pdf, self::AL_CHECK_X, self::AL_CHECK_Y[$i]);
            }
        }

        $signed = $this->mdySlash($d['date_discharged'] ?? '');

        // Certified correct by: attending physician
        [$x, $y, $w] = self::AL_PHYSICIAN_NAME;
        $this->txt($pdf, $x, $y, $d['cf2_professional_name'] ?? '', 10, $w);
        $this->pinCentres($pdf, self::AL_ACC_LEFT, self::AL_ACC_Y, $d['cf2_professional_accreditation'] ?? '');
        [$x, $y, $w] = self::AL_PHYSICIAN_DATE;
        $this->txt($pdf, $x, $y, $signed, 10, $w);

        // Certified correct by: head of the health facility
        [$x, $y, $w] = self::AL_HEAD_NAME;
        $this->txt($pdf, $x, $y, $d['head_hf_name'] ?? '', 10, $w);
        $this->pinCentres($pdf, self::AL_ACC_RIGHT, self::AL_ACC_Y, $d['head_hf_accreditation'] ?? '');
        if (filled($d['head_hf_name'] ?? null)) {
            [$x, $y, $w] = self::AL_HEAD_DATE;
            $this->txt($pdf, $x, $y, $signed, 10, $w);
        }

        // Conforme by: patient / parent / guardian
        [$x, $y, $w] = self::AL_CONFORME_NAME;
        $this->txt($pdf, $x, $y, $this->signerName($d), 10, $w);
        [$x, $y, $w] = self::AL_CONFORME_DATE;
        $this->txt($pdf, $x, $y, $signed, 10, $w);
    }

    /** Header block shared by Annex J.1 and L.1 (case no., facility, patient, member). */
    private function a4Header(Fpdi $pdf, array $d, array $m): void
    {
        $patientIsMember = strtolower($d['relationship_to_member'] ?? '') === 'member';

        [$x, $y, $w] = $m['case'];
        $this->txt($pdf, $x, $y, $d['case_no'] ?? '', 10, $w);

        [$x, $y, $w] = $m['facility'];
        $this->txt($pdf, $x, $y, $d['facility_name'] ?? '', 10, $w);

        [$x, $y, $w] = $m['address'];
        $this->txt($pdf, $x, $y, $this->facilityAddress($d), 9, $w);

        // A. Patient: "Last Name, First Name, Middle Name, Suffix"
        [$x, $y, $w] = $m['name'];
        $this->txt($pdf, $x, $y, $this->commaName($d, 'patient', ['last_name', 'first_name', 'middle_name', 'extension']), 10, $w);

        match (strtolower($d['patient_sex'] ?? '')) {
            'male' => $this->check($pdf, ...$m['sex']['male']),
            'female' => $this->check($pdf, ...$m['sex']['female']),
            default => null,
        };

        $this->pinCentres($pdf, self::A4_PIN, $m['pinY'], $this->patientPin($d));

        // B. Member: "same as above" when the patient is the member
        [$x, $y, $w] = $m['memberName'];
        if ($patientIsMember) {
            $this->txt($pdf, $x, $y, 'SAME AS ABOVE', 10, $w);
        } else {
            $this->txt($pdf, $x, $y, $this->commaName($d, 'member', ['last_name', 'first_name', 'middle_name', 'extension']), 10, $w);
            $this->pinCentres($pdf, self::A4_PIN, $m['memberPinY'], $d['member_pin'] ?? '');
        }
    }

    /** Part I item 9: same signer choice and representative details as Part III. */
    private function partI9(Fpdi $pdf, array $d): void
    {
        $byRep = ($d['part3_signer'] ?? 'patient') === 'representative';

        if (!$byRep) {
            $this->check($pdf, 45, 657);
            $this->txt($pdf, 90, 530, $this->fullName($d, 'member', true), 10, 280);
            $this->signedDate($pdf, 568, $d['date_discharged'] ?? '', self::SIGNED_I9_MEMBER, 11, true);
            return;
        }

        $this->check($pdf, 116, 657);
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
            $this->check($pdf, 111, 1058);
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
            $this->check($pdf, 45, 1058);
            $this->txt($pdf, 95, 962, $this->fullName($d, 'patient', true), 10, 410);
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

        // Part II-3 confinement period
        $cf2Date = ['m' => [289, 307], 'd' => [335, 353], 'y' => [381, 399, 417, 435]];
        $this->date($pdf, $cf2Date, 532, $d['date_admitted'] ?? '', 10);
        $this->date($pdf, $cf2Date, 554, $d['date_discharged'] ?? '', 10);

        // Time admitted / discharged
        $this->time($pdf, 532, 696, 758, $d['time_admitted'] ?? '');
        $this->time($pdf, 554, 696, 758, $d['time_discharged'] ?? '', 557);

        // Part II-4 patient disposition: always IMPROVED
        $this->check($pdf, 56, 604);

        // Part II-5 accommodation: always NON-PRIVATE
        $this->check($pdf, 299, 692);

        // 6. Admission diagnosis
        $this->wrap($pdf, 52, [736, 752], $d['admission_diagnosis'] ?? '', 800, 10);

        // 7. Discharge diagnoses (free input)
        $this->diagnosisTable($pdf, $d['dx_a'], [813, 833, 852]);
        $this->diagnosisTable($pdf, $d['dx_b'], [872, 892, 911]);

        // 8b. Z-Benefit Package Code (free input)
        [$zx, $zy, $zw] = self::Z_BENEFIT_POS;
        $this->txt($pdf, $zx, $zy, $d['z_benefit_code'] ?? '', 10, $zw);

        // 9. Benefits
        $this->txt($pdf, 255, 1327, $d['cf2_first_case_rate'] ?? '', 11, 240);
        $this->txt($pdf, 640, 1327, $d['second_case_rate'] ?? '', 11, 210);
    }

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
        $signed = $d['date_discharged'] ?? '';
        $acc = $this->digits($d['cf2_professional_accreditation']);

        // Part 10: health care professional
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

        $this->money($pdf, 805, 514, $d['hci_charges'] ?? '');
        $this->money($pdf, 805, 536, $d['professional_fees'] ?? '');
        $this->money($pdf, 805, 557, $d['grand_total'] ?? '');

        // Part III-A second table (co-pay)
        $this->copayTable($pdf, $d);

        // Part III-B
        $byRep = ($d['part3_signer'] ?? 'patient') === 'representative';
        $signer = $byRep ? ($d['representative_name'] ?? '') : $this->fullName($d, 'patient', true);
        [$x, $ny, $w] = self::CF2B_PATIENT_NAME;
        $this->txt($pdf, $x, $ny + $this->nudge['name'], $signer, 10, $w);
        $this->signedDate($pdf, self::CF2B_PATIENT_DATE_Y + $this->nudge['date'], $signed, self::CF2B_SIGNED, self::CF2B_DATE_SIZE, true);

        $this->cf2Consent($pdf, $d, $byRep);

        // Part IV: HCI representative
        [$x, $ny, $w] = self::CF2B_HCI_NAME;
        $this->txt($pdf, $x, $ny + $this->nudge['name'], $d['hci_rep_name'], 10, $w);
        [$x, $ny, $w] = self::CF2B_HCI_DESIG;
        $this->txt($pdf, $x, $ny + $this->nudge['name'], $d['hci_rep_designation'], 10, $w);
        $this->signedDate($pdf, self::CF2B_HCI_DATE_Y + $this->nudge['date'], $signed, self::CF2B_SIGNED_IV, self::CF2B_DATE_SIZE, true);
    }

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

    /** Part III-A second table: row 1 is free input (Others checked only when a value exists), row 2 is N/A in that case. */
    private function copayTable(Fpdi $pdf, array $d): void
    {
        $cols = self::COPAY_COLS;
        $hciFields = ['copay_hci_actual', 'copay_hci_discount', 'copay_hci_benefit'];
        $hasHciValue = false;

        foreach ($hciFields as $i => $key) {
            $value = trim((string) ($d[$key] ?? ''));
            if ($value !== '') {
                $hasHciValue = true;
            }
            $this->centered($pdf, $cols[$i], self::COPAY_HCI_Y, $value !== '' ? $this->plainAmount($value) : '');
        }

        [$x, $y, $w] = self::COPAY_HCI_AMT;
        $hciAmount = trim((string) ($d['copay_hci_amount'] ?? ''));
        if ($hciAmount !== '') {
            $this->txt($pdf, $x, $y, $this->plainAmount($hciAmount), 10, $w);
        }

        if ($hasHciValue) {
            $this->check($pdf, self::COPAY_HCI_OTHERS[0], self::COPAY_HCI_OTHERS[1]);

            foreach ($cols as $cx) {
                $this->centered($pdf, $cx, self::COPAY_PF_Y, 'N/A');
            }

            [$x, $y, $w] = self::COPAY_PF_AMT;
            $this->txt($pdf, $x, $y, 'N/A', 10, $w);
        }
    }

    /* ----------------------------- helpers ----------------------------- */

    /** Tick the box of the chosen answer (key => y), all in column $x. */
    private function tickChoice(Fpdi $pdf, float $x, array $map, ?string $choice): void
    {
        $choice = strtolower(trim((string) $choice));
        if (isset($map[$choice])) {
            $this->check($pdf, $x, $map[$choice]);
        }
    }

    /** PIN of the patient: the member PIN when the patient is the member, otherwise the dependent PIN. */
    private function patientPin(array $d): string
    {
        $isMember = strtolower($d['relationship_to_member'] ?? '') === 'member';

        return (string) ($isMember ? ($d['member_pin'] ?? '') : ($d['dependent_pin'] ?? ''));
    }

    /** "NN-NNNNNNNNN-N" when 12 digits are available, otherwise the text as typed. */
    private function formatPin(?string $pin): string
    {
        $digits = $this->digits($pin);

        return strlen($digits) === 12
            ? substr($digits, 0, 2) . '-' . substr($digits, 2, 9) . '-' . substr($digits, 11, 1)
            : trim((string) $pin);
    }

    /** Street, city, province of the health facility. */
    private function facilityAddress(array $d): string
    {
        return implode(', ', array_filter([
            trim((string) ($d['facility_street'] ?? '')),
            trim((string) ($d['facility_city'] ?? '')),
            trim((string) ($d['facility_province'] ?? '')),
        ]));
    }

    /** Date of assessment; falls back to the date admitted when left empty. */
    private function assessmentDate(array $d): string
    {
        return filled($d['assessment_date'] ?? null) ? (string) $d['assessment_date'] : (string) ($d['date_admitted'] ?? '');
    }

    /** Name of whoever signs the claim: the representative, otherwise the patient (middle name as initial). */
    private function signerName(array $d): string
    {
        return ($d['part3_signer'] ?? 'patient') === 'representative'
            ? (string) ($d['representative_name'] ?? '')
            : $this->fullName($d, 'patient', true);
    }

    /** Annex K item 1: typed choice wins; otherwise the patient's age when the patient answers the survey. */
    private function ageGroup(array $d): string
    {
        $typed = strtolower(trim((string) ($d['k_age_group'] ?? '')));
        if ($typed !== '') {
            return $typed;
        }
        if (($d['part3_signer'] ?? 'patient') === 'representative') {
            return '';
        }

        $age = $this->age($d);
        if ($age === '' || !is_numeric($age)) {
            return '';
        }

        $a = (int) $age;

        return match (true) {
            $a <= 19 => '19_below',
            $a <= 35 => '20_35',
            $a <= 45 => '36_45',
            $a <= 55 => '46_55',
            $a <= 65 => '56_65',
            default => 'above_65',
        };
    }

    /** Annex K item 2: typed choice wins; otherwise the patient's sex when the patient answers the survey. */
    private function respondentSex(array $d): string
    {
        $typed = strtolower(trim((string) ($d['k_sex'] ?? '')));
        if ($typed !== '') {
            return $typed;
        }

        return ($d['part3_signer'] ?? 'patient') === 'representative' ? '' : strtolower((string) ($d['patient_sex'] ?? ''));
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

    /** One diagnosis inside ONE underline: one line if it fits, otherwise two small lines. */
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

        if (count($lines) > 2) {
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

    /** Right-aligned money value with a peso sign (DejaVu Sans has the glyph). */
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

    /** $groups = [2-box, 9-box, 1-box] LEFT edges; needs 12 digits. */
    private function pin(Fpdi $pdf, array $groups, float $yc, ?string $pin, float $size = 11): void
    {
        $digits = $this->digits($pin);
        if (strlen($digits) < 12)
            return;
        $this->cells($pdf, $groups[0], $yc, substr($digits, 0, 2), $size);
        $this->cells($pdf, $groups[1], $yc, substr($digits, 2, 9), $size);
        $this->cells($pdf, $groups[2], $yc, substr($digits, 11, 1), $size);
    }

    /**
     * Same as pin() but the groups are box CENTRES (Annex D, J.1, L.1).
     * Also used for the accreditation number, whose groups are 4 + 7 + 1 digits.
     */
    private function pinCentres(Fpdi $pdf, array $groups, float $yc, ?string $pin, float $size = 10): void
    {
        $digits = $this->digits($pin);
        if (strlen($digits) < 12)
            return;

        $left = array_map(fn($g) => array_map(fn($c) => $c - 9, $g), $groups);
        $at = 0;
        foreach ($left as $group) {
            $len = count($group);
            $this->cells($pdf, $group, $yc, substr($digits, $at, $len), $size);
            $at += $len;
        }
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

    /** "Date signed" = DATE DISCHARGED (full date when $withDay = true). */
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

    /** $value "HH:MM" (24h) → hour/min cells + AM/PM check. */
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

    /** mm/dd/yyyy (Annex format). */
    private function mdySlash(?string $value): string
    {
        return ($value && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m))
            ? "{$m[2]}/{$m[3]}/{$m[1]}" : '';
    }

    /** Typed age wins; otherwise years between the patient birthdate and the assessment date (or today). */
    private function age(array $d): string
    {
        if (filled($d['age'] ?? null)) {
            return trim((string) $d['age']);
        }

        $dob = (string) ($d['patient_birthdate'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $dob)) {
            return '';
        }

        $ref = (string) ($d['assessment_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $ref)) {
            $ref = (string) ($d['date_admitted'] ?? '');
        }

        try {
            $refDate = preg_match('/^\d{4}-\d{2}-\d{2}/', $ref) ? Carbon::parse(substr($ref, 0, 10)) : now();

            return (string) (int) floor(abs(Carbon::parse(substr($dob, 0, 10))->diffInYears($refDate)));
        } catch (\Throwable) {
            return '';
        }
    }

    /** "A, B, C" from name parts in the given order, skipping blanks. */
    private function commaName(array $d, string $prefix, array $parts): string
    {
        $vals = array_map(fn($p) => trim((string) ($d["{$prefix}_{$p}"] ?? '')), $parts);

        return implode(', ', array_filter($vals, fn($v) => $v !== ''));
    }

    /** Full name; with $initial = true the middle name becomes an initial. */
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
        $pdf->SetFont('zapfdingbats', '', $this->checkSize);
        $w = 6;
        $this->put($pdf, $cx * $this->k - $w / 2, $cy * $this->k, $w, '4', 'C');
    }

    private function grid(Fpdi $pdf, array $size, float $refW): void
    {
        $pdf->SetFont('helvetica', '', 5);
        $pdf->SetDrawColor(255, 0, 0);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->SetLineWidth(0.05);
        for ($px = 0; $px <= $refW; $px += 50) {
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