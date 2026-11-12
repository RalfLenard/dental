<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'

const BASE = '/mental-pdf-records'

const breadcrumbs = [
    {
        title: 'PhilHealth Mental',
        href: BASE,
    },
]

const props = defineProps({
    records: { type: Array, default: () => [] },
    record: { type: Object, default: null },
})

const blankRows = () => [0, 1, 2].map(() => ({ diagnosis: '', icd10: '', procedure: '', rvs: '', date: '' }))
const blankFollowUps = () => Array.from({ length: 12 }, () => ({ visit: '', next: '' }))
const blankMeds = () => Array.from({ length: 7 }, () => ({ name: '', dosage: '', preparation: '', date: '' }))

// Annex J.1 service checklist (key, label). Screening and assessment are ticked by default.
const J1_SERVICES = [
    ['j1_screening', 'Screening'],
    ['j1_assessment', 'Assessment'],
]
const J1_DIAGNOSTICS = [
    ['j1_cbc', 'Complete Blood Count (CBC) w/ platelet'],
    ['j1_urinalysis', 'Urinalysis'],
    ['j1_fbg', 'Fasting Blood Glucose'],
    ['j1_lipid', 'Lipid Profile'],
    ['j1_renal', 'Renal Function Test'],
    ['j1_creatinine', 'Creatinine'],
    ['j1_cxr', 'Radiology: Chest X-ray (PA or AP)'],
]

// Annex L.1 requirements
const L1_ITEMS = [
    'Transmittal Form of Claims for Mental Health (Annex H)',
    'Checklist of Requirements for Reimbursement (Annex L.1)',
    'Properly accomplished Claim Form (CF) 2',
    'Checklist of Mandatory and Other Services (Annex J.1)',
    'Properly accomplished MH Satisfaction Questionnaire (Annex K)',
    'Photocopy of the Mental Health Passport (Annex D)',
    'Original or Certified True Copy (CTC) of the Statement of Account',
]

// Annex K choices
const K_AGE = [
    ['19_below', '19 years old and below'],
    ['20_35', 'Between 20 to 35'],
    ['36_45', 'Between 36 to 45'],
    ['46_55', 'Between 46 to 55'],
    ['56_65', 'Between 56 to 65'],
    ['above_65', 'Above 65 years old'],
]
const K_QUESTIONS = [
    {
        key: 'k_q3',
        label: '3. Availability of medicines or supplies needed for the treatment',
        options: [['adequate', 'Adequate'], ['inadequate', 'Inadequate'], ['dont_know', "Don't Know"]],
    },
    {
        key: 'k_q4',
        label: "4. Patient's or family's involvement in the care (patient empowerment)",
        options: [['excellent', 'Excellent'], ['satisfactory', 'Satisfactory'], ['unsatisfactory', 'Unsatisfactory'], ['dont_know', "Don't Know"]],
    },
    {
        key: 'k_q5',
        label: '5. Health care professionals (doctor-patient relationship)',
        options: [['excellent', 'Excellent'], ['satisfactory', 'Satisfactory'], ['unsatisfactory', 'Unsatisfactory'], ['dont_know', "Don't Know"]],
    },
    {
        key: 'k_q6',
        label: '6. How much health facility expenses were lessened by the PhilHealth benefit',
        options: [['less_than_half', 'Less than half'], ['half', 'By half'], ['more_than_half', 'More than half'], ['dont_know', "Don't know"]],
    },
    {
        key: 'k_q7',
        label: '7. Overall patient satisfaction (PS mark)',
        options: [['excellent', 'Excellent'], ['satisfactory', 'Satisfactory'], ['unsatisfactory', 'Unsatisfactory'], ['dont_know', "Don't know"]],
    },
]

const blankData = () => ({
    member_pin: '',
    member_last_name: '',
    member_first_name: '',
    member_extension: '',
    member_middle_name: '',
    member_birthdate: '',
    member_sex: '',
    dependent_pin: '',
    patient_last_name: '',
    patient_first_name: '',
    patient_extension: '',
    patient_middle_name: '',
    patient_sex: '',
    relationship_to_member: '',
    date_admitted: '',
    date_discharged: '',
    patient_birthdate: '',

    // Part III - consent
    has_representative: 'no',
    representative_name: '',
    representative_relationship: '',
    representative_relationship_other: '',
    representative_reason: '',
    representative_reason_other: '',

    // CSF Part IV - health care professional
    professional_accreditation: '1501-1638387-1',
    professional_name: 'DR. GLADYS LOURDES BENGCO, M.D.',

    // CF-2 page 3 (item 10) health care professional
    cf2_professional_accreditation: '1501-1638387-1',
    cf2_professional_name: 'DR. GLADYS LOURDES BENGCO, M.D.',

    // Annex D (Mental Health Passport)
    case_no: '',
    assessment_date: '',
    age: '',
    follow_ups: blankFollowUps(),
    medications: blankMeds(),

    // Annex H (Transmittal form)
    h_remarks: '',

    // Annex J.1 (Checklist of mandatory and other services)
    j1_screening: true,
    j1_assessment: true,
    j1_cbc: false,
    j1_urinalysis: false,
    j1_fbg: false,
    j1_lipid: false,
    j1_renal: false,
    j1_creatinine: false,
    j1_cxr: false,
    j1_medicines: false,
    j1_medicines_list: '',
    j1_follow_ups: Array.from({ length: 6 }, () => ''),

    // Annex K (Satisfaction questionnaire)
    k_age_group: '',
    k_sex: '',
    k_q3: '',
    k_q4: '',
    k_q5: '',
    k_q6: '',
    k_q7: '',
    k_comments: '',
    k_name: '',
    k_date: '',

    // Annex L.1 (Checklist of requirements)
    l1_checks: Array.from({ length: 7 }, () => true),
    head_hf_name: '',
    head_hf_accreditation: '',

    // Part V - provider
    hci_rep_name: 'ROXAN B. VILLANUEVA',
    hci_rep_designation: 'ADMINISTRATIVE AIDE',

    facility_pan: 'P03033905',
    facility_name: 'CONCEPCION RURAL HEALTH UNIT I',
    facility_street: 'L. CORTEZ STREET SAN NICOLAS POBLACION',
    facility_city: 'CONCEPCION',
    facility_province: 'TARLAC',
    time_admitted: '',
    time_discharged: '',
    admission_diagnosis: '',
    dx_a: blankRows(),
    dx_b: blankRows(),

    first_case_rate: '',
    second_case_rate: '',

    // CF-2 free inputs
    z_benefit_code: '',
    cf2_first_case_rate: '',

    // CF-2 page 3
    hci_charges: '',
    copay_hci_actual: '',
    copay_hci_discount: '',
    copay_hci_benefit: '',
    copay_hci_amount: '',
})

// Saved empty strings should not wipe the fixed defaults.
const withDefaults = (saved) => {
    const base = blankData()
    const merged = { ...base, ...(saved ?? {}) }

    for (const key of ['dx_a', 'dx_b']) {
        const rows = Array.isArray(saved?.[key]) ? saved[key] : []
        merged[key] = [0, 1, 2].map((i) => ({ ...blankRows()[0], ...(rows[i] ?? {}) }))
    }

    // Annex D: always 12 follow-up rows and 7 medication rows
    const fu = Array.isArray(saved?.follow_ups) ? saved.follow_ups : []
    merged.follow_ups = Array.from({ length: 12 }, (_, i) => ({ visit: '', next: '', ...(fu[i] ?? {}) }))
    const meds = Array.isArray(saved?.medications) ? saved.medications : []
    merged.medications = Array.from({ length: 7 }, (_, i) => ({ name: '', dosage: '', preparation: '', date: '', ...(meds[i] ?? {}) }))

    // Annex J.1: six follow-up dates. Annex L.1: seven requirement ticks (ticked unless saved otherwise)
    const j1fu = Array.isArray(saved?.j1_follow_ups) ? saved.j1_follow_ups : []
    merged.j1_follow_ups = Array.from({ length: 6 }, (_, i) => j1fu[i] ?? '')
    const l1 = Array.isArray(saved?.l1_checks) ? saved.l1_checks : []
    merged.l1_checks = Array.from({ length: 7 }, (_, i) => (i in l1 ? !!l1[i] : true))

    for (const key of ['professional_accreditation', 'professional_name', 'cf2_professional_accreditation', 'cf2_professional_name', 'hci_rep_name', 'hci_rep_designation', 'facility_pan', 'facility_name', 'facility_street', 'facility_city', 'facility_province']) {
        if (!merged[key]) merged[key] = base[key]
    }

    return merged
}

// ---- MHG2 (Tranche 2) ----------------------------------------------------
const blankMhg2 = () => ({
    z_benefit_code: 'MHG02',
    rvs_code: 'MHG02',
    h_package_code: 'MHG2',
    h_remarks: '',
    j2_follow_ups: Array.from({ length: 6 }, () => ''),
    // Annex C (Mental Health Registry), MHG2 only. Empty = automatic.
    c_diagnosis: '',
    c_diagnostics: '',
    c_medications: '',
    c_physician: '',
})

const withMhg2Defaults = (saved) => {
    const base = blankMhg2()
    const merged = { ...base, ...(saved ?? {}) }
    for (const k of ['z_benefit_code', 'rvs_code', 'h_package_code']) {
        if (!merged[k]) merged[k] = base[k]
    }
    for (const k of ['c_diagnosis', 'c_diagnostics', 'c_medications', 'c_physician']) {
        merged[k] = merged[k] ?? ''
    }
    const fu = Array.isArray(saved?.j2_follow_ups) ? saved.j2_follow_ups : []
    merged.j2_follow_ups = Array.from({ length: 6 }, (_, i) => fu[i] ?? '')
    return merged
}

const form = useForm({
    patient_name: props.record?.patient_name ?? '',
    form_data: withDefaults(props.record?.form_data),
})

const mhg2Form = useForm({
    form_data: withMhg2Defaults(props.record?.mhg2?.form_data),
})

const previewTab = ref('main')

// ---- Modal -------------------------------------------------------------
const showModal = ref(!!props.record)

watch(() => props.record, (record) => {
    form.patient_name = record?.patient_name ?? ''
    form.form_data = withDefaults(record?.form_data)
    mhg2Form.form_data = withMhg2Defaults(record?.mhg2?.form_data)
    if (!record?.mhg2) previewTab.value = 'main'
    form.clearErrors()
    showModal.value = !!record
})

watch(showModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
})

function openNew() {
    if (props.record) {
        router.get(BASE, {}, {
            preserveScroll: true,
            onSuccess: () => { showModal.value = true },
        })
        return
    }
    form.patient_name = ''
    form.form_data = withDefaults(null)
    form.clearErrors()
    showModal.value = true
}

function openRecord(id) {
    router.get(`${BASE}/${id}/edit`, {}, { preserveScroll: true })
}

function closeModal() {
    showModal.value = false
    if (props.record) {
        router.get(BASE, {}, { preserveScroll: true })
    }
}

const onKey = (e) => { if (e.key === 'Escape' && showModal.value) closeModal() }
onMounted(() => {
    window.addEventListener('keydown', onKey)
    if (showModal.value) document.body.style.overflow = 'hidden'
})
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey)
    document.body.style.overflow = ''
})

// ---- Records table -------------------------------------------------------
const search = ref('')
const filteredRecords = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.records
    return props.records.filter((r) =>
        `${r.record_no ?? ''} ${r.patient_name ?? ''}`.toLowerCase().includes(q)
    )
})

const formatDate = (value) => {
    if (!value) return '—'
    const d = new Date(value)
    return Number.isNaN(d.getTime())
        ? '—'
        : d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' })
}

const isMember = computed(() => form.form_data.relationship_to_member === 'Member')
const byRep = computed(() => form.form_data.has_representative === 'yes')

const stamp = ref(Date.now())

const previewUrl = computed(() =>
    props.record?.id
        ? `${BASE}/${props.record.id}/preview?v=${encodeURIComponent(props.record.updated_at ?? '')}-${stamp.value}`
        : null
)

const mhg2PreviewUrl = computed(() =>
    props.record?.mhg2
        ? `${BASE}/${props.record.id}/mhg2/preview?v=${encodeURIComponent(props.record.mhg2.updated_at ?? '')}-${stamp.value}`
        : null
)

const activePreview = computed(() =>
    previewTab.value === 'mhg2' && mhg2PreviewUrl.value ? mhg2PreviewUrl.value : previewUrl.value
)

function save() {
    if (props.record?.id) {
        form.put(`${BASE}/${props.record.id}`, { onSuccess: () => { stamp.value = Date.now() } })
    } else {
        form.post(BASE)
    }
}

// Create the MHG2 (Tranche 2) continuation for a record
const addMhg2 = (id) => router.post(`${BASE}/${id}/mhg2`, {}, { preserveScroll: true })

// Save the record (new or existing), then add MHG2 to it
function saveAndAddMhg2() {
    const after = (page) => {
        const id = page.props.record?.id
        if (id) addMhg2(id)
    }
    if (props.record?.id) {
        form.put(`${BASE}/${props.record.id}`, { preserveScroll: true, onSuccess: after })
    } else {
        form.post(BASE, { preserveScroll: true, onSuccess: after })
    }
}

function saveMhg2() {
    mhg2Form.put(`${BASE}/${props.record.id}/mhg2`, {
        preserveScroll: true,
        onSuccess: () => { stamp.value = Date.now() },
    })
}
</script>

<template>
    <Head title="PhilHealth Mental Health CSF + CF-2 Generator" />

     <AppLayout :breadcrumbs="breadcrumbs">

     <main class="w-full space-y-6 px-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">PhilHealth Mental Health: CSF + CF-2 + Annexes C (MHG2), D, H, J.1/J.2, K, L.1/L.2</h1>
                <p class="text-sm text-gray-500">Standalone PDF form system</p>
            </div>
            <button type="button" @click="openNew" class="rounded bg-blue-700 px-4 py-2 font-semibold text-white hover:bg-blue-800">
                + New record
            </button>
        </header>

        <!-- RECORDS TABLE -->
        <section class="rounded-xl border">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                <h2 class="font-semibold">Saved records <span class="text-sm font-normal text-gray-500">({{ filteredRecords.length }})</span></h2>
                <input v-model="search" type="search" placeholder="Search record no. or patient…" class="w-full rounded border p-2 text-sm sm:w-72" />
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">Record no.</th>
                            <th class="px-4 py-3">Patient</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in filteredRecords" :key="item.id"
                            class="border-t hover:bg-gray-50" :class="{ 'bg-blue-50': record?.id === item.id }">
                            <td class="px-4 py-3 text-gray-500">{{ index + 1 }}</td>
                            <td class="px-4 py-3 font-medium">{{ item.record_no || `Record #${item.id}` }}</td>
                            <td class="px-4 py-3">{{ item.patient_name || 'Unnamed patient' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ formatDate(item.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <button type="button" @click="openRecord(item.id)" class="rounded border px-3 py-1 text-blue-700 hover:bg-blue-50">
                                        Edit
                                    </button>
                                    <a :href="`${BASE}/${item.id}/preview`" target="_blank" class="rounded bg-gray-800 px-3 py-1 text-white hover:bg-gray-900">
                                        PDF
                                    </a>
                                    <a v-if="item.mhg2" :href="`${BASE}/${item.id}/mhg2/preview`" target="_blank"
                                       class="rounded bg-emerald-700 px-3 py-1 text-white hover:bg-emerald-800">
                                        MHG2 PDF
                                    </a>
                                    <button v-else type="button" @click="addMhg2(item.id)"
                                            class="rounded border border-emerald-700 px-3 py-1 text-emerald-700 hover:bg-emerald-50">
                                        + MHG2
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filteredRecords.length">
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                {{ records.length ? 'No records match your search.' : 'No records yet. Click "New record" to create one.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- MODAL -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-3 sm:p-6"
             @mousedown.self="closeModal">
            <div class="flex max-h-[95vh] w-full max-w-7xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between border-b px-5 py-3">
                    <h2 class="text-lg font-bold">
                        {{ record ? `Edit ${record.record_no || `record #${record.id}`}` : 'New record' }}
                    </h2>
                    <button type="button" @click="closeModal" class="rounded px-3 py-1 text-xl leading-none text-gray-500 hover:bg-gray-100" aria-label="Close">&times;</button>
                </div>

                <div class="overflow-y-auto p-5">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
            <form @submit.prevent="save" class="space-y-6 rounded-xl border p-5">
                <!-- CSF PART I : member -->
                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">CSF Part I — Member information</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Member PIN
                            <input v-model="form.form_data.member_pin" placeholder="12 digits" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Member birthdate
                            <input v-model="form.form_data.member_birthdate" type="date" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Member last name
                            <input v-model="form.form_data.member_last_name" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Member first name
                            <input v-model="form.form_data.member_first_name" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Name extension
                            <input v-model="form.form_data.member_extension" placeholder="JR / SR / III" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Member middle name
                            <input v-model="form.form_data.member_middle_name" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Member sex (Annex C, D, J.1, L.1)
                            <select v-model="form.form_data.member_sex" class="mt-1 w-full rounded border p-2">
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </label>
                    </div>
                </section>

                <!-- CSF PART I : patient -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">CSF Part I — Patient information</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm sm:col-span-2">Patient is the member's…
                            <select v-model="form.form_data.relationship_to_member" class="mt-1 w-full rounded border p-2">
                                <option value="">Select</option>
                                <option value="Member">Member (patient is the member)</option>
                                <option value="Child">Child</option>
                                <option value="Spouse">Spouse</option>
                                <option value="Parent">Parent</option>
                            </select>
                        </label>

                        <p v-if="isMember" class="rounded bg-blue-50 p-3 text-sm text-blue-800 sm:col-span-2">
                            The patient is the member, so the patient name, birthdate, sex and dependent PIN are
                            taken from the member details above. Annex D, J.1 and L.1 section B will say "SAME AS ABOVE".
                        </p>

                        <template v-else>
                            <label class="text-sm">Dependent PIN
                                <input v-model="form.form_data.dependent_pin" placeholder="12 digits" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm">Patient birthdate
                                <input v-model="form.form_data.patient_birthdate" type="date" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm">Patient last name
                                <input v-model="form.form_data.patient_last_name" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm">Patient first name
                                <input v-model="form.form_data.patient_first_name" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm">Patient name extension
                                <input v-model="form.form_data.patient_extension" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm">Patient middle name
                                <input v-model="form.form_data.patient_middle_name" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm">Patient sex (Annex C, D, J.1, L.1)
                                <select v-model="form.form_data.patient_sex" class="mt-1 w-full rounded border p-2">
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </label>
                        </template>
                    </div>
                </section>

                <!-- CSF confinement dates -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">Confinement period</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Date admitted
                            <input v-model="form.form_data.date_admitted" type="date" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Date discharged
                            <input v-model="form.form_data.date_discharged" type="date" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Time admitted (CF-2)
                            <input v-model="form.form_data.time_admitted" type="time" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Time discharged (CF-2)
                            <input v-model="form.form_data.time_discharged" type="time" class="mt-1 w-full rounded border p-2" />
                        </label>
                    </div>
                    <p class="text-xs text-gray-500">
                        Every "Date signed" (CSF, CF-2 page 3 and the annexes) is filled automatically with the complete DATE DISCHARGED.
                    </p>
                </section>

                <section class="space-y-1 border-t pt-5">
                    <h2 class="text-lg font-semibold">CSF Part II — Employer's certification</h2>
                    <p class="text-sm text-gray-500">Left blank. Nothing to enter.</p>
                </section>

                <!-- CSF PART III -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">CSF Part III — Consent to access patient records</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <p class="rounded bg-blue-50 p-3 text-sm text-blue-800 sm:col-span-2">
                            With no representative, the patient signs (and when the patient is the member, the
                            member signs). The middle name is printed as an initial. This also applies to
                            Part I item 9, CF-2 page 3 and the "Conforme by" boxes of Annex J.1 and L.1.
                        </p>
                        <label class="text-sm sm:col-span-2">Does a representative sign?
                            <select v-model="form.form_data.has_representative" class="mt-1 w-full rounded border p-2">
                                <option value="no">No — patient / member signs</option>
                                <option value="yes">Yes — representative signs</option>
                            </select>
                        </label>

                        <template v-if="byRep">
                            <label class="text-sm sm:col-span-2">Representative full name
                                <input v-model="form.form_data.representative_name" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm">Relationship to the patient
                                <select v-model="form.form_data.representative_relationship" class="mt-1 w-full rounded border p-2">
                                    <option value="">Select</option>
                                    <option>Spouse</option><option>Child</option><option>Parent</option>
                                    <option>Sibling</option><option>Others</option>
                                </select>
                            </label>
                            <label v-if="form.form_data.representative_relationship === 'Others'" class="text-sm">Others, specify
                                <input v-model="form.form_data.representative_relationship_other" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm">Reason for signing on behalf of the patient
                                <select v-model="form.form_data.representative_reason" class="mt-1 w-full rounded border p-2">
                                    <option value="">Select</option>
                                    <option value="incapacitated">Patient is incapacitated</option>
                                    <option value="other">Other reasons</option>
                                </select>
                            </label>
                            <label v-if="form.form_data.representative_reason === 'other'" class="text-sm">Other reasons
                                <input v-model="form.form_data.representative_reason_other" class="mt-1 w-full rounded border p-2" />
                            </label>
                        </template>
                    </div>
                </section>

                <!-- CSF PART IV -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">CSF Part IV — Health care professional</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Accreditation No.
                            <input v-model="form.form_data.professional_accreditation" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Signature over printed name
                            <input v-model="form.form_data.professional_name" class="mt-1 w-full rounded border p-2" />
                        </label>
                    </div>
                </section>

                <!-- CSF PART V -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">CSF Part V — Provider information</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">ICD 10 / RVS code — First case rate
                            <input v-model="form.form_data.first_case_rate" placeholder="e.g. MHG1" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Second case rate
                            <input v-model="form.form_data.second_case_rate" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Authorized HCI representative
                            <input v-model="form.form_data.hci_rep_name" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Official capacity / designation
                            <input v-model="form.form_data.hci_rep_designation" class="mt-1 w-full rounded border p-2" />
                        </label>
                    </div>
                </section>

                <!-- CF-2 -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">CF-2 — Facility and confinement</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Facility PAN
                            <input v-model="form.form_data.facility_pan" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Facility name
                            <input v-model="form.form_data.facility_name" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Street address
                            <input v-model="form.form_data.facility_street" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">City / municipality
                            <input v-model="form.form_data.facility_city" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Province
                            <input v-model="form.form_data.facility_province" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Z-Benefit Package Code (item 8b)
                            <input v-model="form.form_data.z_benefit_code" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Item 9 — First case rate (any value)
                            <input v-model="form.form_data.cf2_first_case_rate" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm sm:col-span-2">Admission diagnosis
                            <textarea v-model="form.form_data.admission_diagnosis" rows="2" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <div v-for="g in [{ key: 'dx_a', title: '7a — Discharge diagnoses' }, { key: 'dx_b', title: '7b — Discharge diagnoses' }]"
                             :key="g.key" class="space-y-2 sm:col-span-2">
                            <h3 class="text-sm font-semibold">{{ g.title }} (all optional, leave blank if not needed)</h3>
                            <div v-for="(row, i) in form.form_data[g.key]" :key="i" class="grid gap-2 sm:grid-cols-5">
                                <input v-model="row.diagnosis" :placeholder="`Diagnosis ${i + 1}`" class="w-full rounded border p-2 text-sm sm:col-span-2" />
                                <input v-model="row.icd10" placeholder="ICD-10" class="w-full rounded border p-2 text-sm" />
                                <input v-model="row.procedure" placeholder="Related procedure" class="w-full rounded border p-2 text-sm" />
                                <input v-model="row.rvs" placeholder="RVS code" class="w-full rounded border p-2 text-sm" />
                                <input v-model="row.date" type="date" title="Date of procedure" class="w-full rounded border p-2 text-sm sm:col-span-2" />
                            </div>
                        </div>
                    </div>
                </section>

                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">CF-2 page 3 — Health care professional (item 10)</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Accreditation No.
                            <input v-model="form.form_data.cf2_professional_accreditation" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Signature over printed name
                            <input v-model="form.form_data.cf2_professional_name" class="mt-1 w-full rounded border p-2" />
                        </label>
                    </div>
                    <p class="text-xs text-gray-500">
                        This professional is also the attending physician on Annex C (MHG2), J.1 and L.1.
                    </p>
                </section>

                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">CF-2 — Charges (page 3, Part III-A)</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Total health care institution fees
                            <input v-model="form.form_data.hci_charges" placeholder="e.g. 2925.00" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <p class="rounded bg-blue-50 p-3 text-sm text-blue-800">
                            The first box is always checked. Professional fees are always 0.00. Grand total equals the institution fees.
                        </p>
                    </div>

                    <h3 class="pt-2 text-sm font-semibold">Co-pay table, row 1 — Total Health Care Institution Fees</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Total actual charges
                            <input v-model="form.form_data.copay_hci_actual" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Amount after application of discount
                            <input v-model="form.form_data.copay_hci_discount" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">PhilHealth benefit
                            <input v-model="form.form_data.copay_hci_benefit" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Amount after PhilHealth deduction (Amount P)
                            <input v-model="form.form_data.copay_hci_amount" class="mt-1 w-full rounded border p-2" />
                        </label>
                    </div>
                    <p class="text-xs text-gray-500">
                        "Others" is checked on row 1 when any value is entered. Row 2 (Total Professional Fees) is then N/A.
                    </p>
                </section>

                <!-- ANNEX D -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">Annex D — Mental Health Passport</h2>
                    <p class="rounded bg-blue-50 p-3 text-sm text-blue-800">
                        Health facility, address, patient / member names, PINs and sex come from the details above.
                        Age is computed from the patient birthdate unless you type it. If the date of assessment is empty,
                        the date admitted is used. The Case No. below is also printed on Annex H, J.1 and L.1.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">Case No.
                            <input v-model="form.form_data.case_no" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Date of assessment
                            <input v-model="form.form_data.assessment_date" type="date" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Age (optional override)
                            <input v-model="form.form_data.age" placeholder="auto" class="mt-1 w-full rounded border p-2" />
                        </label>
                    </div>

                    <h3 class="pt-2 text-sm font-semibold">A. Follow-up visits (all optional)</h3>
                    <div class="space-y-2">
                        <div class="grid grid-cols-[2rem_1fr_1fr] gap-2 text-xs text-gray-500">
                            <span></span><span>Date of visit</span><span>Date of next visit</span>
                        </div>
                        <div v-for="(row, i) in form.form_data.follow_ups" :key="i" class="grid grid-cols-[2rem_1fr_1fr] items-center gap-2">
                            <span class="text-sm text-gray-500">{{ i + 1 }}</span>
                            <input v-model="row.visit" type="date" class="w-full rounded border p-2 text-sm" />
                            <input v-model="row.next" type="date" class="w-full rounded border p-2 text-sm" />
                        </div>
                    </div>

                    <h3 class="pt-2 text-sm font-semibold">B. Medications (all optional)</h3>
                    <div class="space-y-2">
                        <div v-for="(row, i) in form.form_data.medications" :key="i" class="grid gap-2 sm:grid-cols-4">
                            <input v-model="row.name" :placeholder="`Medicine ${i + 1}`" class="w-full rounded border p-2 text-sm" />
                            <input v-model="row.dosage" placeholder="Dosage" class="w-full rounded border p-2 text-sm" />
                            <input v-model="row.preparation" placeholder="Preparation" class="w-full rounded border p-2 text-sm" />
                            <input v-model="row.date" type="date" title="Date given" class="w-full rounded border p-2 text-sm" />
                        </div>
                    </div>
                </section>

                <!-- ANNEX H -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">Annex H — Transmittal Form of Claims</h2>
                    <p class="rounded bg-blue-50 p-3 text-sm text-blue-800">
                        Row 1 is filled automatically: Case No. (Annex D), patient PIN, period of availment (date admitted to
                        date discharged), Package Code (the CSF Part V first case rate, e.g. MHG1), and the authorized HCI
                        representative, designation and date discharged under "Certified correct".
                    </p>
                    <label class="block text-sm">Remarks (optional)
                        <input v-model="form.form_data.h_remarks" class="mt-1 w-full rounded border p-2" />
                    </label>
                </section>

                <!-- ANNEX J.1 -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">Annex J.1 — Checklist of Mandatory and Other Services (Tranche 1)</h2>
                    <p class="rounded bg-blue-50 p-3 text-sm text-blue-800">
                        Tick the services that were done. "Diagnostics" is ticked automatically when any test below is ticked.
                        Follow-up visits and "Medicines provided" are ticked automatically from Annex D. Follow-up dates left
                        empty are copied from the Annex D "date of visit" with the same number. The attending physician is the
                        CF-2 page 3 professional. The ticked tests also feed Annex C of the MHG2 PDF.
                    </p>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="[key, label] in J1_SERVICES" :key="key" class="flex items-center gap-2 text-sm">
                            <input v-model="form.form_data[key]" type="checkbox" /> {{ label }}
                        </label>
                    </div>

                    <h3 class="pt-1 text-sm font-semibold">Diagnostics</h3>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="[key, label] in J1_DIAGNOSTICS" :key="key" class="flex items-center gap-2 text-sm">
                            <input v-model="form.form_data[key]" type="checkbox" /> {{ label }}
                        </label>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.form_data.j1_medicines" type="checkbox" /> Medicines provided (also ticked when Annex D lists a medicine)
                    </label>
                    <label v-if="form.form_data.j1_medicines" class="block text-sm">Name of medicine/s provided
                        <input v-model="form.form_data.j1_medicines_list" placeholder="e.g. FLUOXETINE 20 MG, RISPERIDONE 2 MG" class="mt-1 w-full rounded border p-2" />
                    </label>

                    <h3 class="pt-1 text-sm font-semibold">Follow-up visits for psychoeducation and psychosocial support (optional override)</h3>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label v-for="(_, i) in form.form_data.j1_follow_ups" :key="i" class="text-sm">
                            {{ i + 1 }}. Date
                            <input v-model="form.form_data.j1_follow_ups[i]" type="date" class="mt-1 w-full rounded border p-2 text-sm" />
                        </label>
                    </div>
                </section>

                <!-- ANNEX K -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">Annex K — Mental Health Satisfaction Questionnaire</h2>
                    <p class="rounded bg-blue-50 p-3 text-sm text-blue-800">
                        Respondent age and sex follow the patient when the patient signs. When a representative signs, choose
                        them here. Questions 3 to 7 stay unticked until you choose an answer. The signature name defaults to
                        the signer of the claim and the date to the date discharged.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">1. Respondent's age (empty = automatic)
                            <select v-model="form.form_data.k_age_group" class="mt-1 w-full rounded border p-2">
                                <option value="">Automatic</option>
                                <option v-for="[value, label] in K_AGE" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </label>
                        <label class="text-sm">2. Sex of respondent (empty = automatic)
                            <select v-model="form.form_data.k_sex" class="mt-1 w-full rounded border p-2">
                                <option value="">Automatic</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </label>
                        <label v-for="q in K_QUESTIONS" :key="q.key" class="text-sm sm:col-span-2">{{ q.label }}
                            <select v-model="form.form_data[q.key]" class="mt-1 w-full rounded border p-2">
                                <option value="">Leave blank</option>
                                <option v-for="[value, label] in q.options" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </label>
                        <label class="text-sm sm:col-span-2">8. Other comments (optional)
                            <textarea v-model="form.form_data.k_comments" rows="2" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Signature over printed name (optional override)
                            <input v-model="form.form_data.k_name" placeholder="auto" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Date accomplished (optional override)
                            <input v-model="form.form_data.k_date" type="date" class="mt-1 w-full rounded border p-2" />
                        </label>
                    </div>
                </section>

                <!-- ANNEX L.1 -->
                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-lg font-semibold">Annex L.1 — Checklist of Requirements for Reimbursement (Tranche 1)</h2>
                    <p class="rounded bg-blue-50 p-3 text-sm text-blue-800">
                        All seven requirements are ticked by default. The attending physician is the CF-2 page 3 professional.
                        Fill in the head of the health facility below; if the name is empty that box is left blank.
                    </p>
                    <div class="space-y-1">
                        <label v-for="(item, i) in L1_ITEMS" :key="i" class="flex items-start gap-2 text-sm">
                            <input v-model="form.form_data.l1_checks[i]" type="checkbox" class="mt-1" />
                            <span>{{ i + 1 }}. {{ item }}</span>
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Head of the health facility — printed name
                            <input v-model="form.form_data.head_hf_name" class="mt-1 w-full rounded border p-2" />
                        </label>
                        <label class="text-sm">Head of the health facility — PhilHealth accreditation No.
                            <input v-model="form.form_data.head_hf_accreditation" placeholder="12 digits" class="mt-1 w-full rounded border p-2" />
                        </label>
                    </div>
                </section>

                <div v-if="Object.keys(form.errors).length" class="text-sm text-red-600">
                    Please check the fields and try again.
                </div>
                <div class="flex flex-wrap gap-2">
                    <button :disabled="form.processing" class="rounded bg-blue-700 px-5 py-2 font-semibold text-white disabled:opacity-50">
                        {{ form.processing ? 'Saving…' : (record ? 'Update record' : 'Save record') }}
                    </button>
                    <button v-if="!record?.mhg2" type="button" :disabled="form.processing" @click="saveAndAddMhg2"
                            class="rounded border border-emerald-700 px-5 py-2 font-semibold text-emerald-700 disabled:opacity-50">
                        {{ record ? 'Update & add MHG2' : 'Save & add MHG2' }}
                    </button>
                    <button type="button" @click="closeModal" class="rounded border px-5 py-2">Close</button>
                </div>
            </form>

            <aside class="space-y-4 lg:sticky lg:top-0 lg:max-h-[calc(95vh-9rem)] lg:self-start lg:overflow-y-auto lg:pr-1">
                <!-- MHG2 (Tranche 2) -->
                <section v-if="record?.mhg2" class="space-y-3 rounded-xl border border-emerald-600 p-4">
                    <h2 class="font-semibold">MHG2 (Tranche 2)</h2>
                    <p class="text-xs text-gray-500">Everything else is copied from this record. Only these change.</p>
                    <label class="block text-sm">Z-Benefit Package Code (CF-2 item 8b)
                        <input v-model="mhg2Form.form_data.z_benefit_code" class="mt-1 w-full rounded border p-2" />
                    </label>
                    <label class="block text-sm">RVS / ICD code (CF-2 items 7 and 9)
                        <input v-model="mhg2Form.form_data.rvs_code" class="mt-1 w-full rounded border p-2" />
                    </label>
                    <label class="block text-sm">Package code on Annex H
                        <input v-model="mhg2Form.form_data.h_package_code" class="mt-1 w-full rounded border p-2" />
                    </label>
                    <label class="block text-sm">Annex H remarks (optional)
                        <input v-model="mhg2Form.form_data.h_remarks" class="mt-1 w-full rounded border p-2" />
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label v-for="(_, i) in mhg2Form.form_data.j2_follow_ups" :key="i" class="text-xs">
                            J.2 follow-up {{ i + 1 }}
                            <input v-model="mhg2Form.form_data.j2_follow_ups[i]" type="date" class="mt-1 w-full rounded border p-2 text-sm" />
                        </label>
                    </div>
                    <p class="text-xs text-gray-500">Empty J.2 dates use Annex D follow-up visits 7 to 12.</p>

                    <button type="button" @click="saveMhg2" :disabled="mhg2Form.processing"
                            class="rounded bg-emerald-700 px-4 py-2 font-semibold text-white disabled:opacity-50">
                        {{ mhg2Form.processing ? 'Saving…' : 'Save MHG2' }}
                    </button>
                </section>
                <section v-if="record?.mhg2" class="space-y-3 rounded-xl border-2 border-emerald-600 bg-emerald-50 p-4">
                    <h2 class="font-semibold">Annex C — Mental Health Registry (MHG2 only)</h2>
                    <p class="text-xs text-gray-600">
                        Printed on page 3 of the MHG2 PDF. Leave a field empty to fill it automatically.
                        Facility, address, PIN and sex always come from the record.
                    </p>
                    <label class="block text-sm">Diagnosis
                        <input v-model="mhg2Form.form_data.c_diagnosis" placeholder="auto: admission diagnosis" class="mt-1 w-full rounded border bg-white p-2" />
                    </label>
                    <label class="block text-sm">Diagnostics ordered
                        <input v-model="mhg2Form.form_data.c_diagnostics" placeholder="auto: Annex J.1 tests, else N/A" class="mt-1 w-full rounded border bg-white p-2" />
                    </label>
                    <label class="block text-sm">Medications given
                        <input v-model="mhg2Form.form_data.c_medications" placeholder="auto: Annex D medicines, else N/A" class="mt-1 w-full rounded border bg-white p-2" />
                    </label>
                    <label class="block text-sm">Attending physician
                        <input v-model="mhg2Form.form_data.c_physician" placeholder="auto: CF-2 page 3 professional" class="mt-1 w-full rounded border bg-white p-2" />
                    </label>
                    <button type="button" @click="saveMhg2" :disabled="mhg2Form.processing"
                            class="rounded bg-emerald-700 px-4 py-2 font-semibold text-white disabled:opacity-50">
                        {{ mhg2Form.processing ? 'Saving…' : 'Save Annex C' }}
                    </button>
                </section>

                <section v-if="previewUrl" class="space-y-3 rounded-xl border p-4">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">PDF preview</h2>
                        <div v-if="record?.mhg2" class="flex gap-1 text-sm">
                            <button type="button" @click="previewTab = 'main'"
                                    :class="previewTab === 'main' ? 'bg-gray-800 text-white' : 'border'" class="rounded px-3 py-1">Tranche 1</button>
                            <button type="button" @click="previewTab = 'mhg2'"
                                    :class="previewTab === 'mhg2' ? 'bg-emerald-700 text-white' : 'border'" class="rounded px-3 py-1">MHG2</button>
                        </div>
                    </div>
                    <iframe :key="activePreview" :src="activePreview" title="Mental health CSF, CF-2 and annexes PDF preview" class="w-full border" style="height: 65vh"></iframe>
                    <a :href="activePreview" target="_blank" class="inline-block rounded bg-gray-800 px-4 py-2 text-white">
                        Open PDF to print
                    </a>
                </section>
                <section v-else class="rounded-xl border p-4 text-sm text-gray-600">
                    Save the record first. The PDF preview will appear here.
                </section>
            </aside>
        </div>
                </div>
            </div>
        </div>
    </main>
    </AppLayout>
</template>