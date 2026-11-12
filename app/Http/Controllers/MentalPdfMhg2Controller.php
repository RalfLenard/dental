<?php

namespace App\Http\Controllers;

use App\Models\MentalPdfMhg2;
use App\Models\MentalPdfRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * MHG2 (Tranche 2) continuation of a MentalPdfRecord.
 *
 * Everything is copied from the parent (tranche 1) record. Only these change:
 *   - CF-2 item 8b  Z-Benefit Package Code      (default MHG02)
 *   - CF-2 item 7 / 9  RVS / ICD code           (default MHG02)
 *   - Annex H claim rows (up to 5), each with its own package code (default MHG2)
 *   - Annex J.2 follow-up dates (optional)
 *   - Annex D follow-up visits and medications: typed for MHG2 only, NOT copied from tranche 1 (blank until filled)
 *   - CF-2 page 3 Part III-A charges and co-pay table: typed for MHG2 only, NOT copied from tranche 1
 *   - Annex C (Mental Health Registry) overrides (optional, MHG2 only)
 *
 * Template: storage/app/pdf-templates/cf2_mhg2_template.pdf (9 pages)
 *   1 CF-2 front, 2 CF-2 back, 3 Annex C (Mental Health Registry), 4 Annex D, 5-6 Annex K,
 *   7 Annex J.2, 8 Annex L.2, 9 Annex H
 */
class MentalPdfMhg2Controller extends Controller
{
    /** CF-2 pages: coordinates are "pixels" of a page rendered 893px wide. */
    private const REF_W = 893;
    /** Annex C (Letter landscape): 1100px wide. */
    private const REF_W_C = 1100;
    /** Annex D (Letter portrait): 950px wide. */
    private const REF_W_D = 950;
    /** Annex H (Letter landscape): 1240px wide. */
    private const REF_W_H = 1240;
    /** Annex K / J.2 / L.2 (A4 portrait): 900px wide. */
    private const REF_W_A4 = 900;

    private const LAST_PAGE = 9;

    private const FILL_DEPENDENT_PIN_WHEN_MEMBER = true;

    /** The only things that differ from tranche 1. Used when the field is left empty. */
    private const T2_DEFAULTS = [
        'z_benefit_code' => 'MHG02',   // CF-2 item 8b
        'rvs_code' => 'MHG02',         // CF-2 item 7 (RVS code) and item 9 (first case rate)
        'h_package_code' => 'MHG2',    // Annex H, Package Code column
    ];

    /** Optional Annex C overrides. Empty = automatic. */
    private const C_FIELDS = ['c_diagnosis', 'c_diagnostics', 'c_medications', 'c_physician'];

    /** CF-2 page 3, Part III-A charges. MHG2 has its own values (blank until typed). */
    private const CHARGE_FIELDS = ['hci_charges', 'copay_hci_actual', 'copay_hci_discount', 'copay_hci_benefit', 'copay_hci_amount'];

    private const DEFAULTS = [
        'professional_accreditation' => '1501-1638387-1',
        'professional_name' => 'DR. GLADYS LOURDES BENGCO, M.D.',
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

    private const CF2_NAME_SIZE = 7.5;

    /* ---------------------------------------------------------------
     |  CF-2 BACK (page 2 of this template)
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

    /** CF-2 front, item 8b: Z-Benefit Package Code. */
    private const Z_BENEFIT_POS = [430, 1065, 205];

    /* ---------------------------------------------------------------
     |  ANNEX C: MENTAL HEALTH REGISTRY (page 3), px @ 1100 wide
     |  PIN, sex and physician go on row 1. Diagnosis, diagnostics and
     |  medications run down the 11 table rows: one item per row, long
     |  items wrap onto the next row.
     |  Columns are CENTRES, [centre x, max width].
     * ------------------------------------------------------------- */
    private const AC_FACILITY = [270, 191, 710];
    private const AC_ADDRESS = [270, 213, 710];
    /** Centre of table row 1 and the row pitch (measured on the template). */
    private const AC_ROW_Y = 293.5;
    private const AC_ROW_DY = 18.5;
    private const AC_ROWS = 11;
    private const AC_COL_PIN = [187.5, 148];
    private const AC_COL_SEX = [296, 56];
    private const AC_COL_DIAGNOSIS = [418.25, 172];
    private const AC_COL_DIAGNOSTICS = [591.1, 156];
    private const AC_COL_MEDS = [753.35, 150];
    private const AC_COL_PHYSICIAN = [910.5, 148];

    /** Labels printed in the "Diagnostics ordered" column (keys match the Annex J.1 tick boxes). */
    private const AC_DIAGNOSTIC_LABELS = [
        'cbc' => 'CBC W/ PLATELET',
        'urinalysis' => 'URINALYSIS',
        'fbg' => 'FASTING BLOOD GLUCOSE',
        'lipid' => 'LIPID PROFILE',
        'renal' => 'RENAL FUNCTION TEST',
        'creatinine' => 'CREATININE',
        'cxr' => 'CHEST X-RAY',
    ];

    /* ---------------------------------------------------------------
     |  ANNEX D: MENTAL HEALTH PASSPORT (page 4), px @ 950 wide
     * ------------------------------------------------------------- */
    private const AD_CASE_NO = [208, 181, 175];
    private const AD_FACILITY = [118, 226, 370];
    private const AD_ASSESS_DATE = [510, 226, 335];
    // x moved right so the text no longer overlaps the "ADDRESS OF HF" label
    private const AD_ADDRESS = [268, 252, 365];
    private const AD_AGE = [702, 262, 85];
    private const AD_PATIENT_NAME = [253, 308, 380];
    private const AD_SEX = ['male' => [657, 305], 'female' => [736, 305]];
    private const AD_PIN_PATIENT_Y = 338;
    private const AD_PIN_PATIENT = [[510.5, 533.5], [577.6, 601, 624.3, 647.6, 670.9, 694.2, 717.5, 740.8, 764.1], [808.5]];
    private const AD_MEMBER_NAME = [253, 432, 590];
    private const AD_PIN_MEMBER_Y = 463;
    private const AD_PIN_MEMBER = [[508.5, 528.5], [559, 577, 595, 613, 631, 649, 667, 685, 702.6], [730.5]];
    private const AD_FU_X = [264, 399.5];
    private const AD_FU_W = 120;
    private const AD_FU_Y0 = 626;
    private const AD_FU_DY = 22.36;
    private const AD_MED_NAME = [112, 198];
    private const AD_MED_DOSAGE_X = 359.5;
    private const AD_MED_PREP_X = 461;
    private const AD_MED_DATE_X = 569.5;
    private const AD_MED_Y = [1014, 1036, 1058, 1080, 1102, 1124, 1146];

    /* ---------------------------------------------------------------
     |  ANNEX H: TRANSMITTAL FORM (page 9), px @ 1240 wide
     * ------------------------------------------------------------- */
    private const AH_FACILITY = [94, 272, 520];
    private const AH_ADDRESS = [643, 272, 480];
    /** Centre of claim row 1 and the row pitch (measured on the template). */
    private const AH_ROW_Y0 = 518;
    private const AH_ROW_DY = 20.75;
    private const AH_COL_CASE = [122, 114];
    private const AH_COL_PIN = [358, 225];
    private const AH_COL_FROM = [558, 155];
    private const AH_COL_TO = [724, 155];
    private const AH_COL_CODE = [888, 150];
    private const AH_COL_REMARKS = [1050, 152];
    private const AH_ROWS = 5;
    private const AH_ROW_KEYS = ['case_no', 'patient_pin', 'date_from', 'date_to', 'package_code', 'remarks'];
    private const AH_SIGN_NAME = [100, 670, 245];
    private const AH_SIGN_DESIG = [360, 677, 245];
    private const AH_SIGN_DATE = [360, 725, 245];

    /* ---------------------------------------------------------------
     |  A4 annexes (K, J.2, L.2), px @ 900 wide
     * ------------------------------------------------------------- */

    /** PIN boxes shared by J.2 and L.2 (box CENTRES). */
    private const A4_PIN = [[477, 495], [529, 547.1, 565.2, 583.3, 601.4, 619.5, 637.6, 655.7, 673.8], [708]];

    /** Annex K (pages 5 and 6): same layout as the tranche 1 questionnaire. */
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

    /** Annex J.2 (page 7). Measured on the J.2 template, tune with ?grid=1 if needed. */
    private const AJ2_CASE = [172, 259, 180];
    private const AJ2_FACILITY = [305, 302, 520];
    private const AJ2_ADDRESS = [242, 342, 580];
    private const AJ2_NAME = [213, 406, 405];
    private const AJ2_SEX = ['male' => [645, 407], 'female' => [725, 407]];
    private const AJ2_PIN_Y = 435;
    private const AJ2_MEMBER_NAME = [213, 527, 600];
    private const AJ2_MEMBER_PIN_Y = 556;
    private const AJ2_FU_BOX = [99.5, 749];
    private const AJ2_FU_X = 510;
    private const AJ2_FU_W = 200;
    private const AJ2_FU_Y = [751, 772, 799, 824, 848, 873];
    private const AJ2_CONFORME_NAME = [92, 972, 345];
    private const AJ2_CONFORME_DATE = [318, 1037, 120];
    private const AJ2_PHYSICIAN_NAME = [466, 972, 410];
    private const AJ2_PHYSICIAN_DATE = [676, 1065, 120];
    private const AJ2_ACC_Y = 1039.5;
    /** 4 digits | dot cell | 7 digits | dot cell | 1 digit (box CENTRES). */
    private const AJ2_ACC = [[574.4, 595.9, 617.4, 638.9], [681.7, 703.2, 724.7, 746.2, 767.7, 789.2, 810.7], [853.6]];

    /** Annex L.2 (page 8). */
    private const AL2_CASE = [162, 280, 125];
    private const AL2_FACILITY = [305, 301, 485];
    private const AL2_ADDRESS = [242, 342, 550];
    private const AL2_NAME = [213, 407, 405];
    private const AL2_SEX = ['male' => [645, 408], 'female' => [713, 408]];
    private const AL2_PIN_Y = 435;
    private const AL2_MEMBER_NAME = [213, 528, 585];
    private const AL2_MEMBER_PIN_Y = 556;
    private const AL2_CHECK_X = 735.5;
    private const AL2_CHECK_Y = [683, 706, 728, 749.5, 770.5, 792, 813];
    private const AL2_PHYSICIAN_NAME = [91, 878, 345];
    private const AL2_PHYSICIAN_DATE = [316, 980, 120];
    private const AL2_HEAD_NAME = [450, 878, 345];
    private const AL2_HEAD_DATE = [676, 980, 120];
    private const AL2_ACC_Y = 951;
    private const AL2_ACC_LEFT = [[185.9, 204.7, 223.8, 242.8], [280.6, 299.5, 318.4, 337.3, 356.2, 375, 393.9], [431.7]];
    private const AL2_ACC_RIGHT = [[545.7, 564.5, 583.5, 602.3], [640, 659, 677.9, 696.7, 715.8, 734.8, 753.7], [791.5]];
    private const AL2_CONFORME_NAME = [455, 1065, 340];
    private const AL2_CONFORME_DATE = [672, 1130, 115];

    private float $k = 1;
    private float $checkSize = 11;
    private array $nudge = ['acc' => self::CF2B_ACCRED_DY, 'date' => self::CF2B_DATE_DY, 'name' => self::CF2B_NAME_DY];

    /* ---------------------------------------------------------------
     |  ROUTES: create / save / preview
     * ------------------------------------------------------------- */

    /** "+ MHG2" button: create the continuation for a record (once). */
    public function store(MentalPdfRecord $record)
    {
        MentalPdfMhg2::firstOrCreate(
            ['mental_pdf_record_id' => $record->id],
            ['form_data' => self::T2_DEFAULTS + [
                'h_remarks' => '',
                'j2_follow_ups' => array_fill(0, 6, ''),
                'd_follow_ups' => array_fill(0, 12, ['visit' => '', 'next' => '']),
                'd_medications' => array_fill(0, 7, ['name' => '', 'dosage' => '', 'preparation' => '', 'date' => '']),
                'c_diagnosis' => '',
                'c_diagnostics' => '',
                'c_medications' => '',
                'c_physician' => '',
                'hci_charges' => '',
                'copay_hci_actual' => '',
                'copay_hci_discount' => '',
                'copay_hci_benefit' => '',
                'copay_hci_amount' => '',
            ]]
        );

        return redirect()->route('mental-pdf-records.edit', $record);
    }

    public function update(Request $request, MentalPdfRecord $record)
    {
        $request->validate([
            'form_data' => ['required', 'array'],
            'form_data.z_benefit_code' => ['nullable', 'string', 'max:50'],
            'form_data.rvs_code' => ['nullable', 'string', 'max:50'],
            'form_data.h_package_code' => ['nullable', 'string', 'max:50'],
            'form_data.h_remarks' => ['nullable', 'string', 'max:500'],
            'form_data.j2_follow_ups' => ['nullable', 'array', 'max:6'],
            'form_data.j2_follow_ups.*' => ['nullable', 'date'],
            'form_data.c_diagnosis' => ['nullable', 'string', 'max:300'],
            'form_data.c_diagnostics' => ['nullable', 'string', 'max:300'],
            'form_data.c_medications' => ['nullable', 'string', 'max:300'],
            'form_data.c_physician' => ['nullable', 'string', 'max:150'],
            'form_data.hci_charges' => ['nullable', 'string', 'max:30'],
            'form_data.copay_hci_actual' => ['nullable', 'string', 'max:30'],
            'form_data.copay_hci_discount' => ['nullable', 'string', 'max:30'],
            'form_data.copay_hci_benefit' => ['nullable', 'string', 'max:30'],
            'form_data.copay_hci_amount' => ['nullable', 'string', 'max:30'],
            'form_data.h_rows' => ['nullable', 'array', 'max:' . self::AH_ROWS],
            'form_data.h_rows.*.case_no' => ['nullable', 'string', 'max:50'],
            'form_data.h_rows.*.patient_pin' => ['nullable', 'string', 'max:20'],
            'form_data.h_rows.*.date_from' => ['nullable', 'date'],
            'form_data.h_rows.*.date_to' => ['nullable', 'date'],
            'form_data.h_rows.*.package_code' => ['nullable', 'string', 'max:50'],
            'form_data.h_rows.*.remarks' => ['nullable', 'string', 'max:300'],
            'form_data.d_follow_ups' => ['nullable', 'array', 'max:12'],
            'form_data.d_follow_ups.*.visit' => ['nullable', 'date'],
            'form_data.d_follow_ups.*.next' => ['nullable', 'date'],
            'form_data.d_medications' => ['nullable', 'array', 'max:7'],
            'form_data.d_medications.*.name' => ['nullable', 'string', 'max:150'],
            'form_data.d_medications.*.dosage' => ['nullable', 'string', 'max:50'],
            'form_data.d_medications.*.preparation' => ['nullable', 'string', 'max:50'],
            'form_data.d_medications.*.date' => ['nullable', 'date'],
        ]);

        $fd = Arr::only($request->input('form_data', []), array_merge([
            'z_benefit_code', 'rvs_code', 'h_package_code', 'h_remarks', 'j2_follow_ups',
        ], self::C_FIELDS, self::CHARGE_FIELDS));

        // Annex D follow-up visits (MHG2 only): always 12 rows of visit / next.
        $fd['d_follow_ups'] = $this->fixedRows($request->input('form_data.d_follow_ups'), 12, ['visit', 'next']);
        // Annex D medications (MHG2 only): always 7 rows.
        $fd['d_medications'] = $this->fixedRows($request->input('form_data.d_medications'), 7, ['name', 'dosage', 'preparation', 'date']);

        // Annex H claim rows: keep only the known keys, at most 5 rows.
        $rows = $request->input('form_data.h_rows');
        if (is_array($rows)) {
            $fd['h_rows'] = array_map(
                fn($r) => array_map(fn($k) => trim((string) (is_array($r) ? ($r[$k] ?? '') : '')), array_combine(self::AH_ROW_KEYS, self::AH_ROW_KEYS)),
                array_slice(array_values($rows), 0, self::AH_ROWS)
            );
        }

        MentalPdfMhg2::updateOrCreate(['mental_pdf_record_id' => $record->id], ['form_data' => $fd]);

        return redirect()->route('mental-pdf-records.edit', $record);
    }

    public function preview(Request $request, MentalPdfRecord $record)
    {
        $mhg2 = MentalPdfMhg2::where('mental_pdf_record_id', $record->id)->first();
        abort_unless($mhg2, 404, 'Add MHG2 to this record first.');

        $template = collect([
            storage_path('app/pdf-templates/cf2_mhg2_template.pdf'),
            storage_path('app/pdf-template/cf2_mhg2_template.pdf'),
            storage_path('app/private/pdf-templates/cf2_mhg2_template.pdf'),
        ])->first(fn($p) => is_file($p));

        abort_unless($template, 404, 'cf2_mhg2_template.pdf was not found in storage/app/pdf-templates.');

        $d = $this->resolve($record->form_data ?? [], $mhg2->form_data ?? []);

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
                $pageNo === 3 => self::REF_W_C,
                $pageNo === 4 => self::REF_W_D,
                $pageNo === 9 => self::REF_W_H,
                $pageNo >= 5 => self::REF_W_A4,
                default => self::REF_W,
            };
            $this->k = $size['width'] / $refW;
            $this->checkSize = in_array($pageNo, [5, 6, 7, 8], true) ? 9 : 11;
            $pdf->SetTextColor(0, 0, 0);

            if ($request->boolean('grid')) {
                $this->grid($pdf, $size, $refW);
            }

            match ($pageNo) {
                1 => $this->pageCf2Front($pdf, $d),
                2 => $this->pageCf2Back($pdf, $d),
                3 => $this->pageAnnexC($pdf, $d),
                4 => $this->pageAnnexD($pdf, $d),
                5 => $this->pageAnnexK1($pdf, $d),
                6 => $this->pageAnnexK2($pdf, $d),
                7 => $this->pageAnnexJ2($pdf, $d),
                8 => $this->pageAnnexL2($pdf, $d),
                9 => $this->pageAnnexH($pdf, $d),
                default => null,
            };
        }

        $content = $pdf->Output('mental-mhg2-' . $record->id . '.pdf', 'S');

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="mental-mhg2-' . $record->id . '.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /* ---------------------------------------------------------------
     |  DATA: parent record + the few MHG2 overrides
     * ------------------------------------------------------------- */

    private function resolve(array $d, array $t2): array
    {
        foreach (self::DEFAULTS as $key => $value) {
            if (blank($d[$key] ?? null)) {
                $d[$key] = $value;
            }
        }

        $hasRep = ($d['has_representative'] ?? 'no') === 'yes' && filled($d['representative_name'] ?? null);
        $d['part3_signer'] = $hasRep ? 'representative' : 'patient';
        if (!$hasRep) {
            foreach (['representative_name', 'representative_relationship', 'representative_relationship_other', 'representative_reason', 'representative_reason_other'] as $f) {
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

        $d['professional_fees'] = '0';
        $d['grand_total'] = $d['hci_charges'] ?? '';

        $d['dx_a'] = $this->diagnosisRows($d, 'dx_a');
        $d['dx_b'] = $this->diagnosisRows($d, 'dx_b');
        $d['follow_ups'] = $this->fixedRows($d['follow_ups'] ?? null, 12, ['visit', 'next']);
        $d['medications'] = $this->fixedRows($d['medications'] ?? null, 7, ['name', 'dosage', 'preparation', 'date']);

        $l1 = is_array($d['l1_checks'] ?? null) ? array_values($d['l1_checks']) : [];
        $d['l1_checks'] = [];
        for ($i = 0; $i < 7; $i++) {
            $d['l1_checks'][] = array_key_exists($i, $l1) ? filter_var($l1[$i], FILTER_VALIDATE_BOOLEAN) : true;
        }

        // ---------- MHG2 overrides ----------
        $pick = fn(string $k) => trim((string) ($t2[$k] ?? '')) !== '' ? trim((string) $t2[$k]) : self::T2_DEFAULTS[$k];

        $rvs = $pick('rvs_code');
        $d['z_benefit_code'] = $pick('z_benefit_code');
        $d['h_package_code'] = $pick('h_package_code');
        $d['h_remarks'] = trim((string) ($t2['h_remarks'] ?? ''));

        // CF-2 page 3 charges: MHG2 values only, never copied from tranche 1 (blank = blank).
        // Professional fees stay 0.00 and the grand total equals the institution fees.
        foreach (self::CHARGE_FIELDS as $f) {
            $d[$f] = trim((string) ($t2[$f] ?? ''));
        }
        $d['grand_total'] = $d['hci_charges'];

        // Annex C overrides (empty = automatic, handled in pageAnnexC)
        foreach (self::C_FIELDS as $f) {
            $d[$f] = trim((string) ($t2[$f] ?? ''));
        }

        // CF-2 item 7: every row that had an RVS code in tranche 1 now carries the MHG2 code.
        foreach (['dx_a', 'dx_b'] as $g) {
            foreach ($d[$g] as $i => $row) {
                if ($row['rvs'] !== '') {
                    $d[$g][$i]['rvs'] = $rvs;
                }
            }
        }
        // CF-2 item 9 (ICD 10 or RVS code, first case rate): only replaced when tranche 1 had a value.
        if (filled($d['cf2_first_case_rate'] ?? null)) {
            $d['cf2_first_case_rate'] = $rvs;
        }

        // Annex D follow-up visits and medications: MHG2 has its own lists, never copied
        // from tranche 1 (blank until typed). Date of assessment and age stay as tranche 1.
        // The MHG2 medicines also feed Annex C "Medications Given".
        $t1FollowUps = $d['follow_ups'];
        $d['follow_ups'] = $this->fixedRows($t2['d_follow_ups'] ?? null, 12, ['visit', 'next']);
        $d['medications'] = $this->fixedRows($t2['d_medications'] ?? null, 7, ['name', 'dosage', 'preparation', 'date']);

        // Annex J.2: 6 follow-up dates. Empty rows fall back to the tranche 1 Annex D visits 7 to 12.
        $typed = is_array($t2['j2_follow_ups'] ?? null) ? array_values($t2['j2_follow_ups']) : [];
        $dates = [];
        for ($i = 0; $i < 6; $i++) {
            $v = trim((string) ($typed[$i] ?? ''));
            $dates[] = $v !== '' ? $v : $t1FollowUps[6 + $i]['visit'];
        }
        $d['j2_follow_ups'] = $dates;

        // Annex H: all claim rows (up to 5)
        $d['h_rows'] = $this->annexHRows($d, $t2);

        return $d;
    }

    /**
     * Annex H claim rows. Row 1 falls back to this record (case no., patient PIN,
     * date admitted / discharged) for any field left blank. Every row with data
     * gets the MHG2 package code when its own code is empty. Empty rows are dropped.
     */
    private function annexHRows(array $d, array $t2): array
    {
        $saved = is_array($t2['h_rows'] ?? null) ? array_values($t2['h_rows']) : null;
        $source = $saved ?: [[]];

        $rows = [];
        foreach (array_slice($source, 0, self::AH_ROWS) as $r) {
            $r = is_array($r) ? $r : [];
            $row = [];
            foreach (self::AH_ROW_KEYS as $k) {
                $row[$k] = trim((string) ($r[$k] ?? ''));
            }
            $rows[] = $row;
        }

        $fallback = [
            'case_no' => trim((string) ($d['case_no'] ?? '')),
            'patient_pin' => $this->patientPin($d),
            'date_from' => trim((string) ($d['date_admitted'] ?? '')),
            'date_to' => trim((string) ($d['date_discharged'] ?? '')),
        ];
        foreach ($fallback as $k => $v) {
            if ($rows[0][$k] === '') {
                $rows[0][$k] = $v;
            }
        }
        // Older MHG2 records saved a single remarks field instead of rows.
        if ($saved === null && $rows[0]['remarks'] === '') {
            $rows[0]['remarks'] = $d['h_remarks'] ?? '';
        }

        $out = [];
        foreach ($rows as $row) {
            $hasData = false;
            foreach (['case_no', 'patient_pin', 'date_from', 'date_to', 'remarks'] as $k) {
                if ($row[$k] !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) {
                continue;
            }
            if ($row['package_code'] === '') {
                $row['package_code'] = $d['h_package_code'];
            }
            $out[] = $row;
        }

        return $out;
    }

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

    /* ---------------------------------------------------------------
     |  PAGES
     * ------------------------------------------------------------- */

    private function pageCf2Front(Fpdi $pdf, array $d): void
    {
        $pan = preg_replace('/[^A-Za-z0-9]/', '', (string) ($d['facility_pan'] ?? ''));
        $this->cells($pdf, [482, 500, 518, 536, 554, 571, 589, 607, 625], 290, $pan, 11);
        $this->txt($pdf, 258, 315, $d['facility_name'] ?? '', 10, 590);
        $this->txt($pdf, 122, 339, $d['facility_street'] ?? '', 10, 300);
        $this->txt($pdf, 435, 339, $d['facility_city'] ?? '', 10, 215);
        $this->txt($pdf, 662, 339, $d['facility_province'] ?? '', 10, 200);

        $this->txt($pdf, 185, 406, $d['patient_last_name'] ?? '', self::CF2_NAME_SIZE, 170);
        $this->txt($pdf, 363, 406, $d['patient_first_name'] ?? '', self::CF2_NAME_SIZE, 175);
        $this->txt($pdf, 552, 406, $d['patient_extension'] ?? '', self::CF2_NAME_SIZE, 125);
        $this->txt($pdf, 689, 406, $d['patient_middle_name'] ?? '', self::CF2_NAME_SIZE, 170);

        $this->check($pdf, 56, 490);

        $cf2Date = ['m' => [289, 307], 'd' => [335, 353], 'y' => [381, 399, 417, 435]];
        $this->date($pdf, $cf2Date, 532, $d['date_admitted'] ?? '', 10);
        $this->date($pdf, $cf2Date, 554, $d['date_discharged'] ?? '', 10);

        $this->time($pdf, 532, 696, 758, $d['time_admitted'] ?? '');
        $this->time($pdf, 554, 696, 758, $d['time_discharged'] ?? '', 557);

        $this->check($pdf, 56, 604);   // improved
        $this->check($pdf, 299, 692);  // non-private

        $this->wrap($pdf, 52, [736, 752], $d['admission_diagnosis'] ?? '', 800, 10);

        $this->diagnosisTable($pdf, $d['dx_a'], [813, 833, 852]);
        $this->diagnosisTable($pdf, $d['dx_b'], [872, 892, 911]);

        // 8b. Z-Benefit Package Code (MHG02)
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
            if ($y === null) {
                break;
            }

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

        $y = self::CF2B_ACCRED_Y + $this->nudge['acc'];
        $sz = self::CF2B_ACCRED_SIZE;
        $this->cells($pdf, self::CF2B_ACCRED[0], $y, substr($acc, 0, 4), $sz);
        $this->cells($pdf, self::CF2B_ACCRED[1], $y, substr($acc, 4, 7), $sz);
        $this->cells($pdf, self::CF2B_ACCRED[2], $y, substr($acc, 11, 1), $sz);
        [$x, $ny, $w] = self::CF2B_PROF_NAME;
        $this->txt($pdf, $x, $ny + $this->nudge['name'], $d['cf2_professional_name'], 10, $w);
        $this->signedDate($pdf, self::CF2B_PROF_DATE_Y + $this->nudge['date'], $signed, self::CF2B_SIGNED, self::CF2B_DATE_SIZE, true);

        $this->check($pdf, self::CF2B_BENEFIT_BOX[0], self::CF2B_BENEFIT_BOX[1]);

        $this->money($pdf, 805, 514, $d['hci_charges'] ?? '');
        $this->money($pdf, 805, 536, $d['professional_fees'] ?? '');
        $this->money($pdf, 805, 557, $d['grand_total'] ?? '');

        $this->copayTable($pdf, $d);

        $byRep = ($d['part3_signer'] ?? 'patient') === 'representative';
        $signer = $byRep ? ($d['representative_name'] ?? '') : $this->fullName($d, 'patient', true);
        [$x, $ny, $w] = self::CF2B_PATIENT_NAME;
        $this->txt($pdf, $x, $ny + $this->nudge['name'], $signer, 10, $w);
        $this->signedDate($pdf, self::CF2B_PATIENT_DATE_Y + $this->nudge['date'], $signed, self::CF2B_SIGNED, self::CF2B_DATE_SIZE, true);

        $this->cf2Consent($pdf, $d, $byRep);

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

    private function copayTable(Fpdi $pdf, array $d): void
    {
        $cols = self::COPAY_COLS;
        $hasHciValue = false;

        foreach (['copay_hci_actual', 'copay_hci_discount', 'copay_hci_benefit'] as $i => $key) {
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

    /**
     * Annex C (Mental Health Registry): page 3, one row for this patient.
     * Diagnosis, diagnostics, medications and physician use the MHG2 override when typed,
     * otherwise they are derived from the rest of the record.
     */
    private function pageAnnexC(Fpdi $pdf, array $d): void
    {
        [$x, $y, $w] = self::AC_FACILITY;
        $this->txt($pdf, $x, $y, $d['facility_name'] ?? '', 10, $w);
        [$x, $y, $w] = self::AC_ADDRESS;
        $this->txt($pdf, $x, $y, $this->facilityAddress($d), 9, $w);

        $y = self::AC_ROW_Y;

        // PhilHealth ID number of the patient (dependent PIN when a dependent)
        [$xc, $w] = self::AC_COL_PIN;
        $this->fitCentered($pdf, $xc, $y, $this->formatPin($this->patientPin($d)), $w, 8);

        [$xc, $w] = self::AC_COL_SEX;
        $this->fitCentered($pdf, $xc, $y, (string) ($d['patient_sex'] ?? ''), $w, 8);

        // Diagnosis: override (one per line), else admission diagnosis, else each discharge diagnosis
        $dx = $this->splitItems($d['c_diagnosis'] ?? '', false);
        if (!$dx) {
            $dx = $this->splitItems($d['admission_diagnosis'] ?? '', false);
        }
        if (!$dx) {
            foreach (array_merge($d['dx_a'], $d['dx_b']) as $r) {
                if ($r['diagnosis'] !== '') {
                    $dx[] = $r['diagnosis'];
                }
            }
        }
        $this->columnRows($pdf, self::AC_COL_DIAGNOSIS, $dx);

        // Diagnostics ordered: override, else each test ticked on Annex J.1 on its own row (N/A when none)
        $diag = $this->splitItems($d['c_diagnostics'] ?? '', true);
        if (!$diag) {
            foreach (self::AC_DIAGNOSTIC_LABELS as $key => $label) {
                if (filter_var($d["j1_{$key}"] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $diag[] = $label;
                }
            }
        }
        $this->columnRows($pdf, self::AC_COL_DIAGNOSTICS, $diag ?: ['N/A']);

        // Medications given: override, else each MHG2 Annex D medicine, else the typed J.1 list, else N/A
        $meds = $this->splitItems($d['c_medications'] ?? '', true);
        if (!$meds) {
            $meds = array_values(array_filter(array_map(fn($r) => $r['name'], $d['medications'])));
        }
        if (!$meds) {
            $meds = $this->splitItems($d['j1_medicines_list'] ?? '', true);
        }
        $this->columnRows($pdf, self::AC_COL_MEDS, $meds ?: ['N/A']);

        // Attending physician: override, else the CF-2 page 3 professional
        $doc = trim((string) ($d['c_physician'] ?? ''));
        if ($doc === '') {
            $doc = (string) ($d['cf2_professional_name'] ?? '');
        }
        [$xc, $w] = self::AC_COL_PHYSICIAN;
        $this->fitCentered($pdf, $xc, $y, $doc, $w, 8);
    }

    /**
     * Splits typed text into list items. New lines and semicolons always separate items;
     * commas too when $commas is true (test and medicine lists, not diagnoses).
     */
    private function splitItems(?string $text, bool $commas): array
    {
        $pattern = $commas ? '/[\r\n;,]+/' : '/[\r\n;]+/';
        $parts = preg_split($pattern, (string) $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
    }

    /**
     * Annex C: writes $items down one column of the registry table, starting on row 1.
     * Each item starts on a new row; an item too wide for the cell wraps onto the next row.
     * The font shrinks (8pt down to 6pt) until everything fits in the 11 rows; anything
     * still left over is condensed into the last row.
     */
    private function columnRows(Fpdi $pdf, array $col, array $items): void
    {
        [$xc, $w] = $col;
        $items = array_values(array_filter(
            array_map(fn($v) => mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $v))), $items),
            fn($v) => $v !== ''
        ));
        if (!$items) {
            return;
        }

        $limit = $w * $this->k;
        $lines = [];
        for ($size = 8.0; $size >= 6.0; $size -= 0.5) {
            $pdf->SetFont('helvetica', '', $size);
            $lines = [];
            foreach ($items as $item) {
                foreach ($this->breakLongWords($pdf, $this->breakWords($pdf, $item, $limit), $limit) as $line) {
                    $lines[] = $line;
                }
            }
            if (count($lines) <= self::AC_ROWS) {
                break;
            }
        }

        if (count($lines) > self::AC_ROWS) {
            $rest = implode(' ', array_slice($lines, self::AC_ROWS - 1));
            $lines = array_slice($lines, 0, self::AC_ROWS - 1);
            $lines[] = $rest;
        }

        foreach ($lines as $i => $line) {
            $yc = self::AC_ROW_Y + self::AC_ROW_DY * $i;
            $this->put($pdf, ($xc - $w / 2) * $this->k, $yc * $this->k, $limit, $line, 'C', 1);
        }
    }

    /** Uppercase, centred on $xc (px), font shrinks to 6pt, then the text is condensed to fit $maxW. */
    private function fitCentered(Fpdi $pdf, float $xc, float $yc, string $text, float $maxW, float $size = 8): void
    {
        $text = mb_strtoupper(trim($text));
        if ($text === '') {
            return;
        }

        $limit = $maxW * $this->k;
        $pdf->SetFont('helvetica', '', $size);
        while ($size > 6 && $pdf->GetStringWidth($text) > $limit) {
            $size -= 0.5;
            $pdf->SetFont('helvetica', '', $size);
        }
        $this->put($pdf, ($xc - $maxW / 2) * $this->k, $yc * $this->k, $limit, $text, 'C', 1);
    }

    /** Annex D (Mental Health Passport): page 4. Same data as tranche 1, except the follow-up visits and medications (MHG2 only). */
    private function pageAnnexD(Fpdi $pdf, array $d): void
    {
        $patientIsMember = strtolower($d['relationship_to_member'] ?? '') === 'member';

        [$x, $y, $w] = self::AD_CASE_NO;
        $this->txt($pdf, $x, $y, $d['case_no'] ?? '', 10, $w);
        [$x, $y, $w] = self::AD_FACILITY;
        $this->txt($pdf, $x, $y, $d['facility_name'] ?? '', 10, $w);
        [$x, $y, $w] = self::AD_ASSESS_DATE;
        $this->txt($pdf, $x, $y, $this->mdySlash($this->assessmentDate($d)), 10, $w);
        [$x, $y, $w] = self::AD_ADDRESS;
        $this->txt($pdf, $x, $y, $this->facilityAddress($d), 9, $w);
        [$x, $y, $w] = self::AD_AGE;
        $this->txt($pdf, $x, $y, $this->age($d), 10, $w);

        [$x, $y, $w] = self::AD_PATIENT_NAME;
        $this->txt($pdf, $x, $y, $this->commaName($d, 'patient', ['last_name', 'first_name', 'extension', 'middle_name']), 10, $w);

        match (strtolower($d['patient_sex'] ?? '')) {
            'male' => $this->check($pdf, ...self::AD_SEX['male']),
            'female' => $this->check($pdf, ...self::AD_SEX['female']),
            default => null,
        };

        $this->pinCentres($pdf, self::AD_PIN_PATIENT, self::AD_PIN_PATIENT_Y, $this->patientPin($d));

        [$x, $y, $w] = self::AD_MEMBER_NAME;
        if ($patientIsMember) {
            $this->txt($pdf, $x, $y, 'SAME AS ABOVE', 10, $w);
        } else {
            $this->txt($pdf, $x, $y, $this->commaName($d, 'member', ['last_name', 'first_name', 'middle_name', 'extension']), 10, $w);
            $this->pinCentres($pdf, self::AD_PIN_MEMBER, self::AD_PIN_MEMBER_Y, $d['member_pin'] ?? '');
        }

        foreach ($d['follow_ups'] as $i => $row) {
            $yc = self::AD_FU_Y0 + self::AD_FU_DY * $i;
            $this->centered($pdf, self::AD_FU_X[0], $yc, $this->mdySlash($row['visit']), 10, self::AD_FU_W);
            $this->centered($pdf, self::AD_FU_X[1], $yc, $this->mdySlash($row['next']), 10, self::AD_FU_W);
        }

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

    /** Annex K, page 1 of 2 (PDF page 5). */
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

    /** Annex K, page 2 of 2 (PDF page 6). */
    private function pageAnnexK2(Fpdi $pdf, array $d): void
    {
        $x = self::AK2_X;
        $this->tickChoice($pdf, $x, self::AK2_Q6, $d['k_q6'] ?? '');
        $this->tickChoice($pdf, $x, self::AK2_Q7, $d['k_q7'] ?? '');

        [$cx, $cw] = self::AK2_COMMENT;
        $this->wrap($pdf, $cx, self::AK2_COMMENT_Y, $d['k_comments'] ?? '', $cw, 10);

        $name = filled($d['k_name'] ?? null) ? $d['k_name'] : $this->signerName($d);
        [$xc, $y, $w] = self::AK2_NAME;
        $this->centered($pdf, $xc, $y, $name, 10, $w);

        $date = filled($d['k_date'] ?? null) ? $d['k_date'] : ($d['date_discharged'] ?? '');
        [$xc, $y, $w] = self::AK2_DATE;
        $this->centered($pdf, $xc, $y, $this->mdySlash($date), 10, $w);
    }

    /** Annex J.2 (Checklist of Mandatory and Other Services, Tranche 2): page 7. */
    private function pageAnnexJ2(Fpdi $pdf, array $d): void
    {
        $this->a4Header($pdf, $d, [
            'case' => self::AJ2_CASE,
            'facility' => self::AJ2_FACILITY,
            'address' => self::AJ2_ADDRESS,
            'name' => self::AJ2_NAME,
            'sex' => self::AJ2_SEX,
            'pinY' => self::AJ2_PIN_Y,
            'memberName' => self::AJ2_MEMBER_NAME,
            'memberPinY' => self::AJ2_MEMBER_PIN_Y,
        ]);

        $dates = $d['j2_follow_ups'];
        if (count(array_filter($dates, fn($v) => trim((string) $v) !== '')) > 0) {
            $this->check($pdf, ...self::AJ2_FU_BOX);
        }

        foreach ($dates as $i => $date) {
            $this->txt($pdf, self::AJ2_FU_X, self::AJ2_FU_Y[$i], $this->mdySlash($date), 10, self::AJ2_FU_W);
        }

        $signed = $this->mdySlash($d['date_discharged'] ?? '');

        [$x, $y, $w] = self::AJ2_CONFORME_NAME;
        $this->txt($pdf, $x, $y, $this->signerName($d), 10, $w);
        [$x, $y, $w] = self::AJ2_CONFORME_DATE;
        $this->txt($pdf, $x, $y, $signed, 10, $w);

        [$x, $y, $w] = self::AJ2_PHYSICIAN_NAME;
        $this->txt($pdf, $x, $y, $d['cf2_professional_name'] ?? '', 10, $w);
        $this->pinCentres($pdf, self::AJ2_ACC, self::AJ2_ACC_Y, $d['cf2_professional_accreditation'] ?? '');
        [$x, $y, $w] = self::AJ2_PHYSICIAN_DATE;
        $this->txt($pdf, $x, $y, $signed, 10, $w);
    }

    /** Annex L.2 (Checklist of Requirements for Reimbursement, Tranche 2): page 8. */
    private function pageAnnexL2(Fpdi $pdf, array $d): void
    {
        $this->a4Header($pdf, $d, [
            'case' => self::AL2_CASE,
            'facility' => self::AL2_FACILITY,
            'address' => self::AL2_ADDRESS,
            'name' => self::AL2_NAME,
            'sex' => self::AL2_SEX,
            'pinY' => self::AL2_PIN_Y,
            'memberName' => self::AL2_MEMBER_NAME,
            'memberPinY' => self::AL2_MEMBER_PIN_Y,
        ]);

        foreach ($d['l1_checks'] as $i => $on) {
            if ($on && isset(self::AL2_CHECK_Y[$i])) {
                $this->check($pdf, self::AL2_CHECK_X, self::AL2_CHECK_Y[$i]);
            }
        }

        $signed = $this->mdySlash($d['date_discharged'] ?? '');

        [$x, $y, $w] = self::AL2_PHYSICIAN_NAME;
        $this->txt($pdf, $x, $y, $d['cf2_professional_name'] ?? '', 10, $w);
        $this->pinCentres($pdf, self::AL2_ACC_LEFT, self::AL2_ACC_Y, $d['cf2_professional_accreditation'] ?? '');
        [$x, $y, $w] = self::AL2_PHYSICIAN_DATE;
        $this->txt($pdf, $x, $y, $signed, 10, $w);

        [$x, $y, $w] = self::AL2_HEAD_NAME;
        $this->txt($pdf, $x, $y, $d['head_hf_name'] ?? '', 10, $w);
        $this->pinCentres($pdf, self::AL2_ACC_RIGHT, self::AL2_ACC_Y, $d['head_hf_accreditation'] ?? '');
        if (filled($d['head_hf_name'] ?? null)) {
            [$x, $y, $w] = self::AL2_HEAD_DATE;
            $this->txt($pdf, $x, $y, $signed, 10, $w);
        }

        [$x, $y, $w] = self::AL2_CONFORME_NAME;
        $this->txt($pdf, $x, $y, $this->signerName($d), 10, $w);
        [$x, $y, $w] = self::AL2_CONFORME_DATE;
        $this->txt($pdf, $x, $y, $signed, 10, $w);
    }

    /** Annex H (Transmittal Form of Claims): page 9, one line per claim row (up to 5). */
    private function pageAnnexH(Fpdi $pdf, array $d): void
    {
        [$x, $y, $w] = self::AH_FACILITY;
        $this->txt($pdf, $x, $y, $d['facility_name'] ?? '', 10, $w);
        [$x, $y, $w] = self::AH_ADDRESS;
        $this->txt($pdf, $x, $y, $this->facilityAddress($d), 9, $w);

        foreach (array_slice($d['h_rows'] ?? [], 0, self::AH_ROWS) as $i => $row) {
            $y = self::AH_ROW_Y0 + self::AH_ROW_DY * $i;

            [$x, $w] = self::AH_COL_CASE;
            $this->txt($pdf, $x, $y, $row['case_no'], 10, $w);

            [$xc, $w] = self::AH_COL_PIN;
            $this->centered($pdf, $xc, $y, $this->formatPin($row['patient_pin']), 10, $w);

            [$xc, $w] = self::AH_COL_FROM;
            $this->centered($pdf, $xc, $y, $this->mdySlash($row['date_from']), 10, $w);
            [$xc, $w] = self::AH_COL_TO;
            $this->centered($pdf, $xc, $y, $this->mdySlash($row['date_to']), 10, $w);

            // Package code of this row (MHG2 unless typed otherwise)
            [$xc, $w] = self::AH_COL_CODE;
            $this->centered($pdf, $xc, $y, $row['package_code'], 10, $w);

            // Remarks stay inside this row: shrink to 6pt, then condense
            [$xc, $w] = self::AH_COL_REMARKS;
            $this->fitCentered($pdf, $xc, $y, preg_replace('/\s+/', ' ', $row['remarks']), $w, 8);
        }

        [$x, $yy, $w] = self::AH_SIGN_NAME;
        $this->txt($pdf, $x, $yy, $d['hci_rep_name'] ?? '', 10, $w);
        [$x, $yy, $w] = self::AH_SIGN_DESIG;
        $this->txt($pdf, $x, $yy, $d['hci_rep_designation'] ?? '', 10, $w);
        [$x, $yy, $w] = self::AH_SIGN_DATE;
        $this->txt($pdf, $x, $yy, $this->mdySlash($d['date_discharged'] ?? ''), 10, $w);
    }

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

    /** Header block shared by Annex J.2 and L.2 (case no., facility, patient, member). */
    private function a4Header(Fpdi $pdf, array $d, array $m): void
    {
        $patientIsMember = strtolower($d['relationship_to_member'] ?? '') === 'member';

        [$x, $y, $w] = $m['case'];
        $this->txt($pdf, $x, $y, $d['case_no'] ?? '', 10, $w);
        [$x, $y, $w] = $m['facility'];
        $this->txt($pdf, $x, $y, $d['facility_name'] ?? '', 10, $w);
        [$x, $y, $w] = $m['address'];
        $this->txt($pdf, $x, $y, $this->facilityAddress($d), 9, $w);

        [$x, $y, $w] = $m['name'];
        $this->txt($pdf, $x, $y, $this->commaName($d, 'patient', ['last_name', 'first_name', 'middle_name', 'extension']), 10, $w);

        match (strtolower($d['patient_sex'] ?? '')) {
            'male' => $this->check($pdf, ...$m['sex']['male']),
            'female' => $this->check($pdf, ...$m['sex']['female']),
            default => null,
        };

        $this->pinCentres($pdf, self::A4_PIN, $m['pinY'], $this->patientPin($d));

        [$x, $y, $w] = $m['memberName'];
        if ($patientIsMember) {
            $this->txt($pdf, $x, $y, 'SAME AS ABOVE', 10, $w);
        } else {
            $this->txt($pdf, $x, $y, $this->commaName($d, 'member', ['last_name', 'first_name', 'middle_name', 'extension']), 10, $w);
            $this->pinCentres($pdf, self::A4_PIN, $m['memberPinY'], $d['member_pin'] ?? '');
        }
    }

    /* ----------------------------- helpers ----------------------------- */

    private function tickChoice(Fpdi $pdf, float $x, array $map, ?string $choice): void
    {
        $choice = strtolower(trim((string) $choice));
        if (isset($map[$choice])) {
            $this->check($pdf, $x, $map[$choice]);
        }
    }

    private function patientPin(array $d): string
    {
        $isMember = strtolower($d['relationship_to_member'] ?? '') === 'member';

        return (string) ($isMember ? ($d['member_pin'] ?? '') : ($d['dependent_pin'] ?? ''));
    }

    private function formatPin(?string $pin): string
    {
        $digits = $this->digits($pin);

        return strlen($digits) === 12
            ? substr($digits, 0, 2) . '-' . substr($digits, 2, 9) . '-' . substr($digits, 11, 1)
            : trim((string) $pin);
    }

    private function facilityAddress(array $d): string
    {
        return implode(', ', array_filter([
            trim((string) ($d['facility_street'] ?? '')),
            trim((string) ($d['facility_city'] ?? '')),
            trim((string) ($d['facility_province'] ?? '')),
        ]));
    }

    private function assessmentDate(array $d): string
    {
        return filled($d['assessment_date'] ?? null) ? (string) $d['assessment_date'] : (string) ($d['date_admitted'] ?? '');
    }

    private function signerName(array $d): string
    {
        return ($d['part3_signer'] ?? 'patient') === 'representative'
            ? (string) ($d['representative_name'] ?? '')
            : $this->fullName($d, 'patient', true);
    }

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

    private function respondentSex(array $d): string
    {
        $typed = strtolower(trim((string) ($d['k_sex'] ?? '')));
        if ($typed !== '') {
            return $typed;
        }

        return ($d['part3_signer'] ?? 'patient') === 'representative' ? '' : strtolower((string) ($d['patient_sex'] ?? ''));
    }

    private function plainAmount(?string $value): string
    {
        $value = trim((string) $value);
        $clean = str_replace(',', '', $value);

        return ($value !== '' && is_numeric($clean)) ? number_format((float) $clean, 2) : $value;
    }

    private function centered(Fpdi $pdf, float $xc, float $yc, string $text, float $size = 10, float $maxW = 110): void
    {
        $text = mb_strtoupper(trim($text));
        if ($text === '') {
            return;
        }

        $pdf->SetFont('helvetica', '', $size);
        while ($size > 6 && $pdf->GetStringWidth($text) > $maxW * $this->k) {
            $size -= 0.5;
            $pdf->SetFont('helvetica', '', $size);
        }
        $this->put($pdf, ($xc - $maxW / 2) * $this->k, $yc * $this->k, $maxW * $this->k, $text, 'C');
    }

    private function put(Fpdi $pdf, float $xMm, float $ycMm, float $wMm, string $text, string $align = 'L', int $stretch = 0): void
    {
        $h = 6;
        $pdf->SetXY($xMm, $ycMm - $h / 2);
        $pdf->Cell($wMm, $h, $text, 0, 0, $align, false, '', $stretch, false, 'T', 'M');
    }

    private function txt(Fpdi $pdf, float $x, float $yc, ?string $text, float $size = 10, ?float $maxW = null, bool $fit = false): void
    {
        $text = mb_strtoupper(trim((string) $text));
        if ($text === '') {
            return;
        }

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

    private function twoLines(Fpdi $pdf, float $x, float $y, ?string $text, float $maxW): void
    {
        $text = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $text)));
        if ($text === '') {
            return;
        }

        $limit = $maxW * $this->k;
        $lines = [];

        for ($size = 6.0; $size >= 4.5; $size -= 0.25) {
            $pdf->SetFont('helvetica', '', $size);
            $lines = $this->breakWords($pdf, $text, $limit);
            if (count($lines) <= 2) {
                break;
            }
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

    private function wrap(Fpdi $pdf, float $x, array $ys, ?string $text, float $maxW, float $size): void
    {
        $text = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $text)));
        if ($text === '') {
            return;
        }

        $pdf->SetFont('helvetica', '', $size);
        $limit = $maxW * $this->k;
        $lines = $this->breakWords($pdf, $text, $limit);

        foreach (array_slice($lines, 0, count($ys)) as $i => $line) {
            $this->put($pdf, $x * $this->k, $ys[$i] * $this->k, $maxW * $this->k + 5, $line, 'L');
        }
    }

    private function money(Fpdi $pdf, float $xRight, float $yc, ?string $value, string $peso = '₱ '): void
    {
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }
        $clean = str_replace(',', '', $value);
        if (is_numeric($clean)) {
            $value = number_format((float) $clean, 2);
        }

        $pdf->SetFont('dejavusans', '', 10);
        $w = 200 * $this->k;
        $this->put($pdf, $xRight * $this->k - $w, $yc * $this->k, $w, $peso . $value, 'R');
    }

    /** One character centred in each box ($xs = left edges). */
    private function cells(Fpdi $pdf, array $xs, float $yc, ?string $text, float $size = 11, float $w = 18): void
    {
        if ($text === null || $text === '') {
            return;
        }
        $pdf->SetFont('helvetica', '', $size);
        foreach (mb_str_split(mb_strtoupper($text)) as $i => $ch) {
            if (!isset($xs[$i])) {
                break;
            }
            $this->put($pdf, $xs[$i] * $this->k, $yc * $this->k, $w * $this->k, $ch, 'C');
        }
    }

    private function digits(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    /** Groups are box CENTRES (Annex D, J.2, L.2). Also used for accreditation numbers (4 + 7 + 1). */
    private function pinCentres(Fpdi $pdf, array $groups, float $yc, ?string $pin, float $size = 10): void
    {
        $digits = $this->digits($pin);
        if (strlen($digits) < 12) {
            return;
        }

        $left = array_map(fn($g) => array_map(fn($c) => $c - 9, $g), $groups);
        $at = 0;
        foreach ($left as $group) {
            $len = count($group);
            $this->cells($pdf, $group, $yc, substr($digits, $at, $len), $size);
            $at += $len;
        }
    }

    private function date(Fpdi $pdf, array $cells, float $yc, ?string $value, float $size = 11, bool $blankDay = false): void
    {
        if (!$value || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return;
        }
        $this->cells($pdf, $cells['m'], $yc, $m[2], $size);
        if (!$blankDay) {
            $this->cells($pdf, $cells['d'], $yc, $m[3], $size);
        }
        $this->cells($pdf, $cells['y'], $yc, $m[1], $size);
    }

    private function signedDate(Fpdi $pdf, float $yc, ?string $discharged, array $cells, float $size = 11, bool $withDay = false): void
    {
        if (!$discharged || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $discharged, $m)) {
            return;
        }
        $this->cells($pdf, $cells['m'], $yc, $m[2], $size);
        if ($withDay) {
            $this->cells($pdf, $cells['d'], $yc, $m[3], $size);
        }
        $this->cells($pdf, $cells['y'], $yc, $m[1], $size);
    }

    private function time(Fpdi $pdf, float $yc, float $amX, float $pmX, ?string $value, ?float $ampmY = null): void
    {
        if (!$value || !preg_match('/^(\d{1,2}):(\d{2})/', $value, $m)) {
            return;
        }

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

    private function mdySlash(?string $value): string
    {
        return ($value && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m))
            ? "{$m[2]}/{$m[3]}/{$m[1]}" : '';
    }

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

    private function commaName(array $d, string $prefix, array $parts): string
    {
        $vals = array_map(fn($p) => trim((string) ($d["{$prefix}_{$p}"] ?? '')), $parts);

        return implode(', ', array_filter($vals, fn($v) => $v !== ''));
    }

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