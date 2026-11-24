<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\ForbiddenHttpException;
use app\components\PermissionFilter;
use app\models\User;

/**
 * ReportController provides authoritative PostgreSQL aggregations and paginated
 * drill-down datasets for standard SIMRS / SIRS Outpatient Reports (FR-08).
 *
 * Supported period modes:
 * - day   : single date (`date=YYYY-MM-DD`)
 * - month : calendar month (`month=YYYY-MM`)
 * - range : custom date range (`from=YYYY-MM-DD&to=YYYY-MM-DD`)
 * - year  : calendar year (`year=YYYY`)
 *
 * Supported report types (SIMRS Reporting Standards):
 * - all        : Laporan Keseluruhan (Executive Summary SIMRS)
 * - visits     : RL 5.2 Kunjungan Rawat Jalan (Poli, Dokter, Baru/Lama, Demografi)
 * - morbidity  : RL 5.4 — 10 Besar Penyakit Rawat Jalan (Morbiditas)
 * - procedures : Laporan Tindakan Medis Rawat Jalan
 * - pharmacy   : Laporan Instalasi Farmasi & Pemakaian Obat
 * - laboratory : Laporan Laboratorium & Penunjang Diagnostik (RL 3.8 / Radiologi)
 * - finance    : Laporan Keuangan, Tagihan & Penerimaan Kasir
 */
class ReportController extends BaseApiController
{
    private const ALLOWED_PERIOD_MODES = ['day', 'month', 'range', 'year'];
    private const ALLOWED_REPORT_TYPES = ['all', 'visits', 'morbidity', 'procedures', 'pharmacy', 'laboratory', 'finance'];
    private const ALLOWED_VISIT_STATUSES = ['registered', 'in_progress', 'completed', 'cancelled'];
    private const ALLOWED_PAYMENT_STATUSES = ['unpaid', 'partially_paid', 'paid'];
    private const ALLOWED_PAYMENT_METHODS = ['cash', 'non_cash'];
    private const ALLOWED_MODALITIES = ['lab_clinical', 'ct_scan', 'xray', 'mri', 'usg', 'ekg', 'other'];
    private const ALLOWED_GENDERS = ['male', 'female'];

    private const REPORT_STANDARDS = [
        'all' => [
            'code' => 'SIMRS-ALL',
            'title' => 'Laporan Keseluruhan Pelayanan Rawat Jalan',
            'description' => 'Rekapitulasi terpadu kunjungan, morbiditas, tindakan medis, farmasi, penunjang, dan keuangan.',
        ],
        'visits' => [
            'code' => 'RL 5.2',
            'title' => 'Laporan Kunjungan Rawat Jalan (RL 5.2)',
            'description' => 'Distribusi kunjungan per poliklinik, dokter, pasien baru/lama, jenis kelamin, dan kelompok umur.',
        ],
        'morbidity' => [
            'code' => 'RL 5.4',
            'title' => 'Laporan 10 Besar Penyakit Rawat Jalan (RL 5.4)',
            'description' => 'Rekapitulasi diagnosis utama terbanyak beserta sebaran kasus baru/lama dan jenis kelamin.',
        ],
        'procedures' => [
            'code' => 'RL 3.x-TIND',
            'title' => 'Laporan Tindakan Medis Rawat Jalan',
            'description' => 'Rekapitulasi volume dan nilai tarif tindakan medis per kategori dan jenis tindakan.',
        ],
        'pharmacy' => [
            'code' => 'RL 3.13-FARM',
            'title' => 'Laporan Farmasi & Pemakaian Obat',
            'description' => 'Rekapitulasi pelayanan resep, pemakaian obat (dispensed), mutasi stok, dan peringatan stok menipis.',
        ],
        'laboratory' => [
            'code' => 'RL 3.8-PENUNJANG',
            'title' => 'Laporan Laboratorium & Penunjang Diagnostik',
            'description' => 'Rekapitulasi pemeriksaan lab klinis & modalitas radiologi (CT-Scan, X-Ray, MRI, USG, EKG) serta status integrasi eksternal.',
        ],
        'finance' => [
            'code' => 'SIMRS-KEU',
            'title' => 'Laporan Keuangan, Billing & Penerimaan Kasir',
            'description' => 'Rekapitulasi tagihan diterbitkan, pendapatan diterima, piutang, komponen tarif, dan metode pembayaran.',
        ],
    ];

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'reports',
            'actions' => [
                'summary' => 'read',
                'details' => 'read',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/reports/summary
     */
    public function actionSummary(): array
    {
        /** @var User $user */
        $user = Yii::$app->user->identity;
        $resolved = $this->resolveAndValidateFilters();
        if (isset($resolved['error'])) {
            return $this->validationError($resolved['error'], 'Invalid report filter parameters.');
        }

        $allowedTypes = $this->getAllowedReportTypes($user);
        $reportType = $resolved['report_type'];

        if (!in_array($reportType, $allowedTypes, true)) {
            throw new ForbiddenHttpException(
                sprintf('Role Anda tidak memiliki akses ke laporan jenis "%s".', $reportType)
            );
        }

        $sectionsToLoad = $reportType === 'all'
            ? array_values(array_diff($allowedTypes, ['all']))
            : [$reportType];

        $data = [
            'report_type' => $reportType,
            'report_standard' => self::REPORT_STANDARDS[$reportType],
            'allowed_report_types' => $allowedTypes,
            'period' => $resolved['period'],
            'filters' => $resolved['filters'],
            'trend' => $this->buildTrend($resolved, $allowedTypes),
            'sections' => [],
        ];

        foreach ($sectionsToLoad as $section) {
            switch ($section) {
                case 'visits':
                    $data['sections']['visits'] = $this->buildVisitsReport($resolved);
                    break;
                case 'morbidity':
                    $data['sections']['morbidity'] = $this->buildMorbidityReport($resolved);
                    break;
                case 'procedures':
                    $data['sections']['procedures'] = $this->buildProceduresReport($resolved);
                    break;
                case 'pharmacy':
                    $data['sections']['pharmacy'] = $this->buildPharmacyReport($resolved);
                    break;
                case 'laboratory':
                    $data['sections']['laboratory'] = $this->buildLaboratoryReport($resolved);
                    break;
                case 'finance':
                    $data['sections']['finance'] = $this->buildFinanceReport($resolved);
                    break;
            }
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * GET /api/reports/details
     * Paginated drill-down records for the requested report_type.
     */
    public function actionDetails(): array
    {
        /** @var User $user */
        $user = Yii::$app->user->identity;
        $resolved = $this->resolveAndValidateFilters();
        if (isset($resolved['error'])) {
            return $this->validationError($resolved['error'], 'Invalid report filter parameters.');
        }

        $allowedTypes = $this->getAllowedReportTypes($user);
        $reportType = $resolved['report_type'];

        if (!in_array($reportType, $allowedTypes, true)) {
            throw new ForbiddenHttpException(
                sprintf('Role Anda tidak memiliki akses ke rincian laporan jenis "%s".', $reportType)
            );
        }

        // For `all`, pick the first detailed report type the user is authorized to inspect
        $detailType = $reportType;
        if ($detailType === 'all') {
            $nonAll = array_values(array_diff($allowedTypes, ['all']));
            $detailType = $nonAll[0] ?? 'visits';
        }

        $request = Yii::$app->request;
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 20)));

        $result = match ($detailType) {
            'visits' => $this->queryVisitsDetails($resolved, $page, $perPage),
            'morbidity' => $this->queryMorbidityDetails($resolved, $page, $perPage),
            'procedures' => $this->queryProceduresDetails($resolved, $page, $perPage),
            'pharmacy' => $this->queryPharmacyDetails($resolved, $page, $perPage),
            'laboratory' => $this->queryLaboratoryDetails($resolved, $page, $perPage),
            'finance' => $this->queryFinanceDetails($resolved, $page, $perPage),
            default => ['items' => [], 'total' => 0],
        };

        return [
            'success' => true,
            'data' => $result['items'],
            'meta' => [
                'report_type' => $detailType,
                'period' => $resolved['period'],
                'total' => $result['total'],
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ];
    }

    /**
     * Enforces least-privilege report section scope per SIMRS role (AGENTS.md Section 5).
     *
     * @return string[]
     */
    private function getAllowedReportTypes(User $user): array
    {
        $roleName = $user->role ? (string) $user->role->name : '';

        if ($roleName === 'admin') {
            return self::ALLOWED_REPORT_TYPES;
        }

        if ($roleName === 'registration') {
            return ['all', 'visits'];
        }
        if ($roleName === 'doctor') {
            return ['all', 'visits', 'morbidity', 'procedures', 'laboratory'];
        }
        if ($roleName === 'pharmacy') {
            return ['all', 'pharmacy'];
        }
        if ($roleName === 'laboratory') {
            return ['all', 'laboratory'];
        }
        if ($roleName === 'cashier') {
            return ['all', 'finance'];
        }

        // Dynamic derivation for custom roles that have `reports:read`
        $types = ['all'];
        if ($user->hasModulePermission('registrations', 'read') || $user->hasModulePermission('queues', 'read')) {
            $types[] = 'visits';
        }
        if ($user->hasModulePermission('medical-records', 'read')) {
            $types[] = 'morbidity';
            $types[] = 'procedures';
        }
        if ($user->hasModulePermission('prescriptions', 'read')) {
            $types[] = 'pharmacy';
        }
        if ($user->hasModulePermission('laboratory', 'read')) {
            $types[] = 'laboratory';
        }
        if ($user->hasModulePermission('billings', 'read') || $user->hasModulePermission('payments', 'read')) {
            $types[] = 'finance';
        }

        return $types;
    }

    /**
     * Parses, validates, and resolves query parameters into normalized date bounds & filters.
     */
    private function resolveAndValidateFilters(): array
    {
        $request = Yii::$app->request;
        $errors = [];

        $periodMode = strtolower(trim((string) $request->get('period_mode', 'month')));
        if (!in_array($periodMode, self::ALLOWED_PERIOD_MODES, true)) {
            $errors['period_mode'] = ['period_mode must be one of: day, month, range, year.'];
        }

        $reportType = strtolower(trim((string) $request->get('report_type', 'all')));
        if (!in_array($reportType, self::ALLOWED_REPORT_TYPES, true)) {
            $errors['report_type'] = ['report_type must be one of: ' . implode(', ', self::ALLOWED_REPORT_TYPES) . '.'];
        }

        $from = '';
        $to = '';
        $label = '';
        $dateParam = trim((string) $request->get('date', ''));
        $monthParam = trim((string) $request->get('month', ''));
        $yearParam = trim((string) $request->get('year', ''));
        $fromParam = trim((string) $request->get('from', ''));
        $toParam = trim((string) $request->get('to', ''));

        if ($periodMode === 'day') {
            $targetDate = $dateParam !== '' ? $dateParam : date('Y-m-d');
            if (!$this->isValidDate($targetDate)) {
                $errors['date'] = ['date must be a valid date in YYYY-MM-DD format.'];
            } else {
                $from = $targetDate;
                $to = $targetDate;
                $label = 'Harian — ' . $this->formatIndonesianDate($targetDate);
            }
        } elseif ($periodMode === 'month') {
            $targetMonth = $monthParam !== '' ? $monthParam : date('Y-m');
            if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $targetMonth, $m)) {
                $errors['month'] = ['month must be a valid month in YYYY-MM format.'];
            } else {
                $y = (int) $m[1];
                $mo = (int) $m[2];
                $lastDay = cal_days_in_month(CAL_GREGORIAN, $mo, $y);
                $from = sprintf('%04d-%02d-01', $y, $mo);
                $to = sprintf('%04d-%02d-%02d', $y, $mo, $lastDay);
                $label = 'Bulanan — ' . $this->formatIndonesianMonth($y, $mo);
            }
        } elseif ($periodMode === 'year') {
            $targetYear = $yearParam !== '' ? $yearParam : date('Y');
            if (!preg_match('/^\d{4}$/', $targetYear) || (int) $targetYear < 2000 || (int) $targetYear > 2100) {
                $errors['year'] = ['year must be a 4-digit year between 2000 and 2100.'];
            } else {
                $from = $targetYear . '-01-01';
                $to = $targetYear . '-12-31';
                $label = 'Tahunan — Tahun ' . $targetYear;
            }
        } elseif ($periodMode === 'range') {
            $targetFrom = $fromParam !== '' ? $fromParam : date('Y-m-01');
            $targetTo = $toParam !== '' ? $toParam : date('Y-m-d');
            if (!$this->isValidDate($targetFrom)) {
                $errors['from'] = ['from must be a valid date in YYYY-MM-DD format.'];
            }
            if (!$this->isValidDate($targetTo)) {
                $errors['to'] = ['to must be a valid date in YYYY-MM-DD format.'];
            }
            if (!isset($errors['from']) && !isset($errors['to'])) {
                if ($targetFrom > $targetTo) {
                    $errors['from'] = ['from date cannot be after to date.'];
                } else {
                    $from = $targetFrom;
                    $to = $targetTo;
                    $label = 'Periode — ' . $this->formatIndonesianDate($from) . ' s/d ' . $this->formatIndonesianDate($to);
                }
            }
        }

        // Optional filters
        $departmentId = $this->parsePositiveIntParam($request->get('department_id'), 'department_id', $errors);
        $doctorId = $this->parsePositiveIntParam($request->get('doctor_id'), 'doctor_id', $errors);

        $visitStatus = $this->parseEnumParam($request->get('visit_status'), self::ALLOWED_VISIT_STATUSES, 'visit_status', $errors);
        $paymentStatus = $this->parseEnumParam($request->get('payment_status'), self::ALLOWED_PAYMENT_STATUSES, 'payment_status', $errors);
        $paymentMethod = $this->parseEnumParam($request->get('payment_method'), self::ALLOWED_PAYMENT_METHODS, 'payment_method', $errors);
        $modalityType = $this->parseEnumParam($request->get('modality_type'), self::ALLOWED_MODALITIES, 'modality_type', $errors);
        $gender = $this->parseEnumParam($request->get('gender'), self::ALLOWED_GENDERS, 'gender', $errors);

        if (!empty($errors)) {
            return ['error' => $errors];
        }

        return [
            'report_type' => $reportType,
            'period' => [
                'mode' => $periodMode,
                'from' => $from,
                'to' => $to,
                'date' => $periodMode === 'day' ? $from : null,
                'month' => $periodMode === 'month' ? substr($from, 0, 7) : null,
                'year' => $periodMode === 'year' ? substr($from, 0, 4) : null,
                'label' => $label,
            ],
            'from_ts' => $from . ' 00:00:00',
            'to_ts' => $to . ' 23:59:59.999',
            'filters' => [
                'department_id' => $departmentId,
                'doctor_id' => $doctorId,
                'visit_status' => $visitStatus,
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'modality_type' => $modalityType,
                'gender' => $gender,
            ],
        ];
    }

    private function parsePositiveIntParam(mixed $raw, string $field, array &$errors): ?int
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        $str = trim((string) $raw);
        if (!ctype_digit($str) || (int) $str <= 0) {
            $errors[$field] = ["{$field} must be a positive integer."];
            return null;
        }
        return (int) $str;
    }

    private function parseEnumParam(mixed $raw, array $allowed, string $field, array &$errors): ?string
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        $val = strtolower(trim((string) $raw));
        if (!in_array($val, $allowed, true)) {
            $errors[$field] = ["{$field} must be one of: " . implode(', ', $allowed) . '.'];
            return null;
        }
        return $val;
    }

    private function isValidDate(string $dateStr): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateStr, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function formatIndonesianDate(string $dateStr): string
    {
        $months = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        ];
        [$y, $mo, $d] = array_map('intval', explode('-', $dateStr));
        return sprintf('%02d %s %04d', $d, $months[$mo] ?? (string) $mo, $y);
    }

    private function formatIndonesianMonth(int $year, int $month): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        return ($months[$month] ?? (string) $month) . ' ' . $year;
    }

    /**
     * Builds reusable parameterized WHERE clause snippets for visit-joined queries.
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function buildVisitWhere(array $resolved, bool $includeGender = false, bool $excludeCancelledByDefault = false): array
    {
        $clauses = ['v.visit_date BETWEEN :from AND :to'];
        $params = [
            ':from' => $resolved['period']['from'],
            ':to' => $resolved['period']['to'],
        ];

        $f = $resolved['filters'];
        if ($f['department_id'] !== null) {
            $clauses[] = 'v.department_id = :department_id';
            $params[':department_id'] = $f['department_id'];
        }
        if ($f['doctor_id'] !== null) {
            $clauses[] = 'v.doctor_id = :doctor_id';
            $params[':doctor_id'] = $f['doctor_id'];
        }
        if ($f['visit_status'] !== null) {
            $clauses[] = 'v.status = :visit_status';
            $params[':visit_status'] = $f['visit_status'];
        } elseif ($excludeCancelledByDefault) {
            $clauses[] = "v.status <> 'cancelled'";
        }
        if ($includeGender && $f['gender'] !== null) {
            $clauses[] = 'p.gender = :gender';
            $params[':gender'] = $f['gender'];
        }

        return [
            'sql' => implode(' AND ', $clauses),
            'params' => $params,
        ];
    }

    /**
     * Builds period-appropriate time-series trend (hourly for day, daily for month/range, monthly for year).
     */
    private function buildTrend(array $resolved, array $allowedTypes): array
    {
        $db = Yii::$app->db;
        $mode = $resolved['period']['mode'];
        $canSeeVisits = in_array('visits', $allowedTypes, true);
        $canSeeFinance = in_array('finance', $allowedTypes, true);

        $buckets = [];

        if ($mode === 'year') {
            $year = $resolved['period']['year'];
            $shortMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%s-%02d', $year, $m);
                $buckets[$key] = [
                    'bucket' => $key,
                    'label' => $shortMonths[$m - 1],
                    'visits' => 0,
                    'completed_visits' => 0,
                    'revenue_paid' => 0.0,
                ];
            }
        } elseif ($mode === 'day') {
            for ($h = 7; $h <= 21; $h++) {
                $key = sprintf('%02d:00', $h);
                $buckets[$key] = [
                    'bucket' => $key,
                    'label' => $key,
                    'visits' => 0,
                    'completed_visits' => 0,
                    'revenue_paid' => 0.0,
                ];
            }
        } else {
            // month or range: generate calendar days if <= 62 days
            $startDt = new \DateTimeImmutable($resolved['period']['from']);
            $endDt = new \DateTimeImmutable($resolved['period']['to']);
            $diffDays = (int) $startDt->diff($endDt)->days;
            if ($diffDays <= 62) {
                for ($d = $startDt; $d <= $endDt; $d = $d->modify('+1 day')) {
                    $key = $d->format('Y-m-d');
                    $buckets[$key] = [
                        'bucket' => $key,
                        'label' => $mode === 'month' ? $d->format('d') : $d->format('d/m'),
                        'visits' => 0,
                        'completed_visits' => 0,
                        'revenue_paid' => 0.0,
                    ];
                }
            }
        }

        if ($canSeeVisits) {
            $w = $this->buildVisitWhere($resolved, true);
            $bucketExpr = match ($mode) {
                'year' => "TO_CHAR(v.visit_date, 'YYYY-MM')",
                'day' => "TO_CHAR(v.created_at, 'HH24') || ':00'",
                default => "TO_CHAR(v.visit_date, 'YYYY-MM-DD')",
            };

            $rows = $db->createCommand(
                "SELECT
                    {$bucketExpr} AS bucket,
                    COUNT(v.id) AS visits,
                    COUNT(v.id) FILTER (WHERE v.status = 'completed') AS completed_visits
                 FROM {{%visits}} v
                 INNER JOIN {{%patients}} p ON p.id = v.patient_id
                 WHERE {$w['sql']}
                 GROUP BY bucket
                 ORDER BY bucket ASC",
                $w['params']
            )->queryAll();

            foreach ($rows as $row) {
                $key = (string) $row['bucket'];
                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'bucket' => $key,
                        'label' => $key,
                        'visits' => 0,
                        'completed_visits' => 0,
                        'revenue_paid' => 0.0,
                    ];
                }
                $buckets[$key]['visits'] = (int) $row['visits'];
                $buckets[$key]['completed_visits'] = (int) $row['completed_visits'];
            }
        }

        if ($canSeeFinance) {
            $w = $this->buildVisitWhere($resolved, true);
            $params = $w['params'];
            $extra = '';
            if ($resolved['filters']['payment_method'] !== null) {
                $extra .= ' AND py.payment_method = :payment_method';
                $params[':payment_method'] = $resolved['filters']['payment_method'];
            }
            if ($resolved['filters']['payment_status'] !== null) {
                $extra .= ' AND i.payment_status = :payment_status';
                $params[':payment_status'] = $resolved['filters']['payment_status'];
            }

            $bucketExpr = match ($mode) {
                'year' => "TO_CHAR(v.visit_date, 'YYYY-MM')",
                'day' => "TO_CHAR(py.paid_at, 'HH24') || ':00'",
                default => "TO_CHAR(v.visit_date, 'YYYY-MM-DD')",
            };

            $rows = $db->createCommand(
                "SELECT
                    {$bucketExpr} AS bucket,
                    COALESCE(SUM(py.amount), 0) AS revenue_paid
                 FROM {{%payments}} py
                 INNER JOIN {{%invoices}} i ON i.id = py.invoice_id
                 INNER JOIN {{%visits}} v ON v.id = i.visit_id
                 INNER JOIN {{%patients}} p ON p.id = v.patient_id
                 WHERE py.status = 'completed'
                   AND {$w['sql']} {$extra}
                 GROUP BY bucket
                 ORDER BY bucket ASC",
                $params
            )->queryAll();

            foreach ($rows as $row) {
                $key = (string) $row['bucket'];
                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'bucket' => $key,
                        'label' => $key,
                        'visits' => 0,
                        'completed_visits' => 0,
                        'revenue_paid' => 0.0,
                    ];
                }
                $buckets[$key]['revenue_paid'] = (float) $row['revenue_paid'];
            }
        }

        ksort($buckets);

        return [
            'granularity' => match ($mode) {
                'day' => 'hour',
                'year' => 'month',
                default => 'day',
            },
            'points' => array_values($buckets),
        ];
    }

    /**
     * RL 5.2 — Kunjungan Rawat Jalan (Poli, Dokter, Baru/Lama, Jenis Kelamin, Kelompok Umur).
     */
    private function buildVisitsReport(array $resolved): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);

        $summary = $db->createCommand(
            "WITH patient_first_visit AS (
                SELECT DISTINCT ON (patient_id) patient_id, id AS first_visit_id
                FROM {{%visits}}
                WHERE status <> 'cancelled'
                ORDER BY patient_id, visit_date ASC, id ASC
             )
             SELECT
                COUNT(v.id) AS total_visits,
                COUNT(DISTINCT v.patient_id) AS unique_patients,
                COUNT(v.id) FILTER (WHERE v.status = 'registered') AS registered_visits,
                COUNT(v.id) FILTER (WHERE v.status = 'in_progress') AS in_progress_visits,
                COUNT(v.id) FILTER (WHERE v.status = 'completed') AS completed_visits,
                COUNT(v.id) FILTER (WHERE v.status = 'cancelled') AS cancelled_visits,
                COUNT(v.id) FILTER (WHERE v.status <> 'cancelled' AND fv.first_visit_id = v.id) AS new_patient_visits,
                COUNT(v.id) FILTER (WHERE v.status <> 'cancelled' AND (fv.first_visit_id IS NULL OR fv.first_visit_id <> v.id)) AS returning_patient_visits,
                COUNT(v.id) FILTER (WHERE p.gender = 'male') AS male_visits,
                COUNT(v.id) FILTER (WHERE p.gender = 'female') AS female_visits
             FROM {{%visits}} v
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             LEFT JOIN patient_first_visit fv ON fv.patient_id = v.patient_id
             WHERE {$w['sql']}",
            $w['params']
        )->queryOne();

        // Age groups (standard SIMRS demographic brackets)
        $ageRow = $db->createCommand(
            "SELECT
                COUNT(v.id) FILTER (WHERE EXTRACT(YEAR FROM AGE(v.visit_date, p.birth_date)) < 6) AS age_0_5,
                COUNT(v.id) FILTER (WHERE EXTRACT(YEAR FROM AGE(v.visit_date, p.birth_date)) BETWEEN 6 AND 14) AS age_6_14,
                COUNT(v.id) FILTER (WHERE EXTRACT(YEAR FROM AGE(v.visit_date, p.birth_date)) BETWEEN 15 AND 24) AS age_15_24,
                COUNT(v.id) FILTER (WHERE EXTRACT(YEAR FROM AGE(v.visit_date, p.birth_date)) BETWEEN 25 AND 44) AS age_25_44,
                COUNT(v.id) FILTER (WHERE EXTRACT(YEAR FROM AGE(v.visit_date, p.birth_date)) BETWEEN 45 AND 64) AS age_45_64,
                COUNT(v.id) FILTER (WHERE EXTRACT(YEAR FROM AGE(v.visit_date, p.birth_date)) >= 65) AS age_65_plus
             FROM {{%visits}} v
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']}",
            $w['params']
        )->queryOne();

        // By Department (RL 5.2 breakdown per Poliklinik)
        $byDept = $db->createCommand(
            "WITH patient_first_visit AS (
                SELECT DISTINCT ON (patient_id) patient_id, id AS first_visit_id
                FROM {{%visits}}
                WHERE status <> 'cancelled'
                ORDER BY patient_id, visit_date ASC, id ASC
             ),
             filtered_visits AS (
                SELECT
                    v.id,
                    v.department_id,
                    v.status,
                    p.gender,
                    CASE WHEN v.status <> 'cancelled' AND fv.first_visit_id = v.id THEN 1 ELSE 0 END AS is_new
                FROM {{%visits}} v
                INNER JOIN {{%patients}} p ON p.id = v.patient_id
                LEFT JOIN patient_first_visit fv ON fv.patient_id = v.patient_id
                WHERE {$w['sql']}
             )
             SELECT
                d.id AS department_id,
                d.code,
                d.name,
                COUNT(fv2.id) AS total_visits,
                COUNT(fv2.id) FILTER (WHERE fv2.status = 'completed') AS completed_visits,
                COUNT(fv2.id) FILTER (WHERE fv2.status IN ('registered', 'in_progress')) AS active_visits,
                COUNT(fv2.id) FILTER (WHERE fv2.status = 'cancelled') AS cancelled_visits,
                COALESCE(SUM(fv2.is_new), 0) AS new_patients,
                COUNT(fv2.id) FILTER (WHERE fv2.status <> 'cancelled' AND fv2.is_new = 0) AS returning_patients,
                COUNT(fv2.id) FILTER (WHERE fv2.gender = 'male') AS male_count,
                COUNT(fv2.id) FILTER (WHERE fv2.gender = 'female') AS female_count
             FROM {{%departments}} d
             LEFT JOIN filtered_visits fv2 ON fv2.department_id = d.id
             WHERE d.is_active = TRUE
             GROUP BY d.id, d.code, d.name
             ORDER BY total_visits DESC, d.name ASC",
            $w['params']
        )->queryAll();

        // Filter out non-matching department rows when department_id is explicitly selected
        if ($resolved['filters']['department_id'] !== null) {
            $deptFilter = $resolved['filters']['department_id'];
            $byDept = array_values(array_filter($byDept, static fn($r) => (int) $r['department_id'] === $deptFilter));
        }

        // By Doctor workload
        $byDoctor = $db->createCommand(
            "SELECT
                doc.id AS doctor_id,
                doc.full_name AS doctor_name,
                doc.specialization,
                d.name AS department_name,
                COUNT(v.id) AS total_visits,
                COUNT(v.id) FILTER (WHERE v.status = 'completed') AS completed_visits,
                COUNT(v.id) FILTER (WHERE v.status = 'cancelled') AS cancelled_visits
             FROM {{%visits}} v
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             INNER JOIN {{%doctors}} doc ON doc.id = v.doctor_id
             INNER JOIN {{%departments}} d ON d.id = v.department_id
             WHERE {$w['sql']}
             GROUP BY doc.id, doc.full_name, doc.specialization, d.name
             ORDER BY total_visits DESC, doc.full_name ASC",
            $w['params']
        )->queryAll();

        return [
            'summary' => [
                'total_visits' => (int) ($summary['total_visits'] ?? 0),
                'unique_patients' => (int) ($summary['unique_patients'] ?? 0),
                'registered' => (int) ($summary['registered_visits'] ?? 0),
                'in_progress' => (int) ($summary['in_progress_visits'] ?? 0),
                'completed' => (int) ($summary['completed_visits'] ?? 0),
                'cancelled' => (int) ($summary['cancelled_visits'] ?? 0),
                'new_patient_visits' => (int) ($summary['new_patient_visits'] ?? 0),
                'returning_patient_visits' => (int) ($summary['returning_patient_visits'] ?? 0),
                'male_visits' => (int) ($summary['male_visits'] ?? 0),
                'female_visits' => (int) ($summary['female_visits'] ?? 0),
            ],
            'age_groups' => [
                ['key' => '0-5', 'label' => '0–5 th (Balita)', 'count' => (int) ($ageRow['age_0_5'] ?? 0)],
                ['key' => '6-14', 'label' => '6–14 th (Anak)', 'count' => (int) ($ageRow['age_6_14'] ?? 0)],
                ['key' => '15-24', 'label' => '15–24 th (Remaja)', 'count' => (int) ($ageRow['age_15_24'] ?? 0)],
                ['key' => '25-44', 'label' => '25–44 th (Dewasa Muda)', 'count' => (int) ($ageRow['age_25_44'] ?? 0)],
                ['key' => '45-64', 'label' => '45–64 th (Dewasa)', 'count' => (int) ($ageRow['age_45_64'] ?? 0)],
                ['key' => '65+', 'label' => '≥ 65 th (Lansia)', 'count' => (int) ($ageRow['age_65_plus'] ?? 0)],
            ],
            'by_department' => array_map(static fn($r) => [
                'department_id' => (int) $r['department_id'],
                'code' => $r['code'],
                'name' => $r['name'],
                'total_visits' => (int) $r['total_visits'],
                'completed_visits' => (int) $r['completed_visits'],
                'active_visits' => (int) $r['active_visits'],
                'cancelled_visits' => (int) $r['cancelled_visits'],
                'new_patients' => (int) $r['new_patients'],
                'returning_patients' => (int) $r['returning_patients'],
                'male_count' => (int) $r['male_count'],
                'female_count' => (int) $r['female_count'],
            ], $byDept),
            'by_doctor' => array_map(static fn($r) => [
                'doctor_id' => (int) $r['doctor_id'],
                'doctor_name' => $r['doctor_name'],
                'specialization' => $r['specialization'],
                'department_name' => $r['department_name'],
                'total_visits' => (int) $r['total_visits'],
                'completed_visits' => (int) $r['completed_visits'],
                'cancelled_visits' => (int) $r['cancelled_visits'],
            ], $byDoctor),
        ];
    }

    /**
     * RL 5.4 — 10 Besar Penyakit Rawat Jalan (Morbiditas).
     */
    private function buildMorbidityReport(array $resolved): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);

        $totalDiagnosed = (int) $db->createCommand(
            "SELECT COUNT(mr.id)
             FROM {{%medical_records}} mr
             INNER JOIN {{%visits}} v ON v.id = mr.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']}",
            $w['params']
        )->queryScalar();

        $topDiagnoses = $db->createCommand(
            "WITH patient_first_visit AS (
                SELECT DISTINCT ON (patient_id) patient_id, id AS first_visit_id
                FROM {{%visits}}
                WHERE status <> 'cancelled'
                ORDER BY patient_id, visit_date ASC, id ASC
             )
             SELECT
                TRIM(mr.primary_diagnosis) AS diagnosis,
                COUNT(mr.id) AS total_cases,
                COUNT(mr.id) FILTER (WHERE p.gender = 'male') AS male_cases,
                COUNT(mr.id) FILTER (WHERE p.gender = 'female') AS female_cases,
                COUNT(mr.id) FILTER (WHERE fv.first_visit_id = v.id) AS new_cases,
                COUNT(mr.id) FILTER (WHERE fv.first_visit_id IS NULL OR fv.first_visit_id <> v.id) AS returning_cases
             FROM {{%medical_records}} mr
             INNER JOIN {{%visits}} v ON v.id = mr.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             LEFT JOIN patient_first_visit fv ON fv.patient_id = v.patient_id
             WHERE {$w['sql']}
               AND TRIM(COALESCE(mr.primary_diagnosis, '')) <> ''
             GROUP BY TRIM(mr.primary_diagnosis)
             ORDER BY total_cases DESC, diagnosis ASC
             LIMIT 10",
            $w['params']
        )->queryAll();

        $uniqueDiagnoses = (int) $db->createCommand(
            "SELECT COUNT(DISTINCT TRIM(mr.primary_diagnosis))
             FROM {{%medical_records}} mr
             INNER JOIN {{%visits}} v ON v.id = mr.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']}
               AND TRIM(COALESCE(mr.primary_diagnosis, '')) <> ''",
            $w['params']
        )->queryScalar();

        return [
            'summary' => [
                'total_examined_visits' => $totalDiagnosed,
                'unique_diagnoses' => $uniqueDiagnoses,
            ],
            'top_diagnoses' => array_map(static function ($r, $idx) use ($totalDiagnosed) {
                $cases = (int) $r['total_cases'];
                return [
                    'rank' => $idx + 1,
                    'diagnosis' => $r['diagnosis'],
                    'total_cases' => $cases,
                    'male_cases' => (int) $r['male_cases'],
                    'female_cases' => (int) $r['female_cases'],
                    'new_cases' => (int) $r['new_cases'],
                    'returning_cases' => (int) $r['returning_cases'],
                    'percentage' => $totalDiagnosed > 0 ? round(($cases / $totalDiagnosed) * 100, 1) : 0.0,
                ];
            }, $topDiagnoses, array_keys($topDiagnoses)),
        ];
    }

    /**
     * Laporan Tindakan Medis Rawat Jalan.
     */
    private function buildProceduresReport(array $resolved): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);

        $summary = $db->createCommand(
            "SELECT
                COUNT(vp.id) FILTER (WHERE vp.status = 'active') AS active_count,
                COUNT(vp.id) FILTER (WHERE vp.status = 'cancelled') AS cancelled_count,
                COALESCE(SUM(vp.quantity) FILTER (WHERE vp.status = 'active'), 0) AS total_quantity,
                COALESCE(SUM(vp.applied_tariff * vp.quantity) FILTER (WHERE vp.status = 'active'), 0) AS total_revenue
             FROM {{%visit_procedures}} vp
             INNER JOIN {{%visits}} v ON v.id = vp.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']}",
            $w['params']
        )->queryOne();

        $byCategory = $db->createCommand(
            "SELECT
                pr.category,
                COUNT(vp.id) AS procedure_count,
                COALESCE(SUM(vp.quantity), 0) AS total_quantity,
                COALESCE(SUM(vp.applied_tariff * vp.quantity), 0) AS total_revenue
             FROM {{%visit_procedures}} vp
             INNER JOIN {{%procedures}} pr ON pr.id = vp.procedure_id
             INNER JOIN {{%visits}} v ON v.id = vp.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE vp.status = 'active'
               AND {$w['sql']}
             GROUP BY pr.category
             ORDER BY total_revenue DESC, pr.category ASC",
            $w['params']
        )->queryAll();

        $topProcedures = $db->createCommand(
            "SELECT
                pr.id AS procedure_id,
                pr.code,
                pr.name,
                pr.category,
                COUNT(vp.id) AS order_count,
                COALESCE(SUM(vp.quantity), 0) AS total_quantity,
                COALESCE(SUM(vp.applied_tariff * vp.quantity), 0) AS total_revenue
             FROM {{%visit_procedures}} vp
             INNER JOIN {{%procedures}} pr ON pr.id = vp.procedure_id
             INNER JOIN {{%visits}} v ON v.id = vp.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE vp.status = 'active'
               AND {$w['sql']}
             GROUP BY pr.id, pr.code, pr.name, pr.category
             ORDER BY total_quantity DESC, total_revenue DESC
             LIMIT 20",
            $w['params']
        )->queryAll();

        return [
            'summary' => [
                'active_count' => (int) ($summary['active_count'] ?? 0),
                'cancelled_count' => (int) ($summary['cancelled_count'] ?? 0),
                'total_quantity' => (int) ($summary['total_quantity'] ?? 0),
                'total_revenue' => (float) ($summary['total_revenue'] ?? 0),
            ],
            'by_category' => array_map(static fn($r) => [
                'category' => $r['category'],
                'procedure_count' => (int) $r['procedure_count'],
                'total_quantity' => (int) $r['total_quantity'],
                'total_revenue' => (float) $r['total_revenue'],
            ], $byCategory),
            'top_procedures' => array_map(static fn($r) => [
                'procedure_id' => (int) $r['procedure_id'],
                'code' => $r['code'],
                'name' => $r['name'],
                'category' => $r['category'],
                'order_count' => (int) $r['order_count'],
                'total_quantity' => (int) $r['total_quantity'],
                'total_revenue' => (float) $r['total_revenue'],
            ], $topProcedures),
        ];
    }

    /**
     * Laporan Instalasi Farmasi & Pemakaian Obat.
     */
    private function buildPharmacyReport(array $resolved): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);

        $rxSummary = $db->createCommand(
            "SELECT
                COUNT(rx.id) AS total_prescriptions,
                COUNT(rx.id) FILTER (WHERE rx.status = 'dispensed') AS dispensed_prescriptions,
                COUNT(rx.id) FILTER (WHERE rx.status = 'pending') AS pending_prescriptions,
                COUNT(rx.id) FILTER (WHERE rx.status = 'cancelled') AS cancelled_prescriptions
             FROM {{%prescriptions}} rx
             INNER JOIN {{%visits}} v ON v.id = rx.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']}",
            $w['params']
        )->queryOne();

        // Dispensed medicines filtered by visit scope (department, doctor, gender, date)
        $dispensedRows = $db->createCommand(
            "SELECT
                m.id AS medicine_id,
                m.code,
                m.name,
                m.unit,
                m.stock AS current_stock,
                m.min_stock,
                COALESCE(SUM(pi.quantity), 0) AS dispensed_qty,
                COALESCE(SUM(pi.quantity * pi.unit_price_snapshot), 0) AS dispensed_value,
                COUNT(DISTINCT rx.id) AS prescription_count
             FROM {{%prescription_items}} pi
             INNER JOIN {{%prescriptions}} rx ON rx.id = pi.prescription_id
             INNER JOIN {{%medicines}} m ON m.id = pi.medicine_id
             INNER JOIN {{%visits}} v ON v.id = rx.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE rx.status = 'dispensed'
               AND {$w['sql']}
             GROUP BY m.id, m.code, m.name, m.unit, m.stock, m.min_stock
             ORDER BY dispensed_qty DESC, m.name ASC
             LIMIT 25",
            $w['params']
        )->queryAll();

        $totalDispensedUnits = 0;
        $totalDispensedValue = 0.0;
        $medicineUsage = [];
        foreach ($dispensedRows as $r) {
            $qty = (int) $r['dispensed_qty'];
            $val = (float) $r['dispensed_value'];
            $totalDispensedUnits += $qty;
            $totalDispensedValue += $val;
            $medicineUsage[] = [
                'medicine_id' => (int) $r['medicine_id'],
                'code' => $r['code'],
                'name' => $r['name'],
                'unit' => $r['unit'],
                'prescription_count' => (int) $r['prescription_count'],
                'dispensed_qty' => $qty,
                'dispensed_value' => $val,
                'current_stock' => (int) $r['current_stock'],
                'min_stock' => (int) $r['min_stock'],
                'is_low_stock' => (int) $r['current_stock'] <= (int) $r['min_stock'],
            ];
        }

        // Stock movement summary in period (dispense vs restock vs adjustment)
        $movementSummary = $db->createCommand(
            "SELECT
                COALESCE(SUM(ABS(quantity_delta)) FILTER (WHERE movement_type = 'dispense'), 0) AS total_dispensed_mv,
                COALESCE(SUM(quantity_delta) FILTER (WHERE movement_type = 'restock'), 0) AS total_restocked_mv,
                COALESCE(SUM(quantity_delta) FILTER (WHERE movement_type = 'adjustment'), 0) AS total_adjustment_mv
             FROM {{%medicine_stock_movements}}
             WHERE created_at BETWEEN :from_ts AND :to_ts",
            [':from_ts' => $resolved['from_ts'], ':to_ts' => $resolved['to_ts']]
        )->queryOne();

        // Low stock medicines alert
        $lowStock = $db->createCommand(
            "SELECT id AS medicine_id, code, name, unit, stock, min_stock, unit_price
             FROM {{%medicines}}
             WHERE is_active = TRUE AND stock <= min_stock
             ORDER BY stock ASC, name ASC
             LIMIT 20"
        )->queryAll();

        return [
            'summary' => [
                'total_prescriptions' => (int) ($rxSummary['total_prescriptions'] ?? 0),
                'dispensed_prescriptions' => (int) ($rxSummary['dispensed_prescriptions'] ?? 0),
                'pending_prescriptions' => (int) ($rxSummary['pending_prescriptions'] ?? 0),
                'cancelled_prescriptions' => (int) ($rxSummary['cancelled_prescriptions'] ?? 0),
                'total_dispensed_units' => $totalDispensedUnits,
                'total_dispensed_value' => $totalDispensedValue,
                'restocked_units_in_period' => (int) ($movementSummary['total_restocked_mv'] ?? 0),
                'adjustment_net_in_period' => (int) ($movementSummary['total_adjustment_mv'] ?? 0),
                'low_stock_medicine_count' => count($lowStock),
            ],
            'medicine_usage' => $medicineUsage,
            'low_stock_medicines' => array_map(static fn($r) => [
                'medicine_id' => (int) $r['medicine_id'],
                'code' => $r['code'],
                'name' => $r['name'],
                'unit' => $r['unit'],
                'stock' => (int) $r['stock'],
                'min_stock' => (int) $r['min_stock'],
                'unit_price' => (float) $r['unit_price'],
            ], $lowStock),
        ];
    }

    /**
     * Laporan Laboratorium & Penunjang Diagnostik (RL 3.8 / Radiologi).
     */
    private function buildLaboratoryReport(array $resolved): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);
        $params = $w['params'];
        $modSql = '';
        if ($resolved['filters']['modality_type'] !== null) {
            $modSql = ' AND lo.modality_type = :modality_type';
            $params[':modality_type'] = $resolved['filters']['modality_type'];
        }

        $summary = $db->createCommand(
            "SELECT
                COUNT(lo.id) AS total_orders,
                COUNT(lo.id) FILTER (WHERE lo.status = 'requested') AS requested_orders,
                COUNT(lo.id) FILTER (WHERE lo.status = 'in_progress') AS in_progress_orders,
                COUNT(lo.id) FILTER (WHERE lo.status = 'completed') AS completed_orders,
                COUNT(lo.id) FILTER (WHERE lo.status = 'cancelled') AS cancelled_orders,
                COALESCE(SUM(lo.tariff_snapshot) FILTER (WHERE lo.status <> 'cancelled'), 0) AS total_tariff,
                COUNT(lo.id) FILTER (WHERE lo.integration_status = 'local_only') AS integration_local_only,
                COUNT(lo.id) FILTER (WHERE lo.integration_status = 'dispatched') AS integration_dispatched,
                COUNT(lo.id) FILTER (WHERE lo.integration_status = 'synced') AS integration_synced,
                COUNT(lo.id) FILTER (WHERE lo.integration_status = 'failed') AS integration_failed
             FROM {{%lab_orders}} lo
             INNER JOIN {{%visits}} v ON v.id = lo.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']} {$modSql}",
            $params
        )->queryOne();

        $byModality = $db->createCommand(
            "SELECT
                lo.modality_type,
                COUNT(lo.id) AS total_orders,
                COUNT(lo.id) FILTER (WHERE lo.status = 'completed') AS completed_orders,
                COUNT(lo.id) FILTER (WHERE lo.integration_status = 'synced') AS synced_orders,
                COALESCE(SUM(lo.tariff_snapshot) FILTER (WHERE lo.status <> 'cancelled'), 0) AS total_tariff
             FROM {{%lab_orders}} lo
             INNER JOIN {{%visits}} v ON v.id = lo.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']} {$modSql}
             GROUP BY lo.modality_type
             ORDER BY total_orders DESC, lo.modality_type ASC",
            $params
        )->queryAll();

        $topTests = $db->createCommand(
            "SELECT
                lo.test_name,
                lo.modality_type,
                COUNT(lo.id) AS total_orders,
                COUNT(lo.id) FILTER (WHERE lo.status = 'completed') AS completed_orders,
                COALESCE(SUM(lo.tariff_snapshot) FILTER (WHERE lo.status <> 'cancelled'), 0) AS total_tariff
             FROM {{%lab_orders}} lo
             INNER JOIN {{%visits}} v ON v.id = lo.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']} {$modSql}
             GROUP BY lo.test_name, lo.modality_type
             ORDER BY total_orders DESC, lo.test_name ASC
             LIMIT 20",
            $params
        )->queryAll();

        return [
            'summary' => [
                'total_orders' => (int) ($summary['total_orders'] ?? 0),
                'requested' => (int) ($summary['requested_orders'] ?? 0),
                'in_progress' => (int) ($summary['in_progress_orders'] ?? 0),
                'completed' => (int) ($summary['completed_orders'] ?? 0),
                'cancelled' => (int) ($summary['cancelled_orders'] ?? 0),
                'total_tariff' => (float) ($summary['total_tariff'] ?? 0),
                'integration_local_only' => (int) ($summary['integration_local_only'] ?? 0),
                'integration_dispatched' => (int) ($summary['integration_dispatched'] ?? 0),
                'integration_synced' => (int) ($summary['integration_synced'] ?? 0),
                'integration_failed' => (int) ($summary['integration_failed'] ?? 0),
            ],
            'by_modality' => array_map(static fn($r) => [
                'modality_type' => $r['modality_type'],
                'total_orders' => (int) $r['total_orders'],
                'completed_orders' => (int) $r['completed_orders'],
                'synced_orders' => (int) $r['synced_orders'],
                'total_tariff' => (float) $r['total_tariff'],
            ], $byModality),
            'top_tests' => array_map(static fn($r) => [
                'test_name' => $r['test_name'],
                'modality_type' => $r['modality_type'],
                'total_orders' => (int) $r['total_orders'],
                'completed_orders' => (int) $r['completed_orders'],
                'total_tariff' => (float) $r['total_tariff'],
            ], $topTests),
        ];
    }

    /**
     * Laporan Keuangan, Billing & Penerimaan Kasir.
     */
    private function buildFinanceReport(array $resolved): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);
        $invParams = $w['params'];
        $invExtra = '';
        if ($resolved['filters']['payment_status'] !== null) {
            $invExtra .= ' AND i.payment_status = :payment_status';
            $invParams[':payment_status'] = $resolved['filters']['payment_status'];
        }

        $invSummary = $db->createCommand(
            "SELECT
                COUNT(i.id) AS total_invoices,
                COALESCE(SUM(i.total_amount), 0) AS total_billed,
                COALESCE(SUM(i.paid_amount), 0) AS total_paid,
                COALESCE(SUM(i.balance_amount), 0) AS total_outstanding,
                COUNT(i.id) FILTER (WHERE i.payment_status = 'paid') AS paid_count,
                COUNT(i.id) FILTER (WHERE i.payment_status = 'partially_paid') AS partially_paid_count,
                COUNT(i.id) FILTER (WHERE i.payment_status = 'unpaid') AS unpaid_count
             FROM {{%invoices}} i
             INNER JOIN {{%visits}} v ON v.id = i.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE i.status = 'issued'
               AND {$w['sql']} {$invExtra}",
            $invParams
        )->queryOne();

        $totalBilled = (float) ($invSummary['total_billed'] ?? 0);
        $totalPaid = (float) ($invSummary['total_paid'] ?? 0);
        $collectionRate = $totalBilled > 0 ? round(($totalPaid / $totalBilled) * 100, 1) : 0.0;

        // Revenue by service component (invoice_items)
        $byComponent = $db->createCommand(
            "SELECT
                ii.item_type,
                COUNT(ii.id) AS item_count,
                COALESCE(SUM(ii.quantity), 0) AS total_quantity,
                COALESCE(SUM(ii.line_total), 0) AS total_amount
             FROM {{%invoice_items}} ii
             INNER JOIN {{%invoices}} i ON i.id = ii.invoice_id
             INNER JOIN {{%visits}} v ON v.id = i.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE i.status = 'issued'
               AND {$w['sql']} {$invExtra}
             GROUP BY ii.item_type
             ORDER BY total_amount DESC, ii.item_type ASC",
            $invParams
        )->queryAll();

        // Payments breakdown by method & channel
        $payParams = $invParams;
        $payExtra = $invExtra;
        if ($resolved['filters']['payment_method'] !== null) {
            $payExtra .= ' AND py.payment_method = :payment_method';
            $payParams[':payment_method'] = $resolved['filters']['payment_method'];
        }

        $byPaymentMethod = $db->createCommand(
            "SELECT
                py.payment_method,
                COALESCE(NULLIF(TRIM(py.payment_channel), ''), py.payment_method) AS payment_channel,
                COUNT(py.id) AS transaction_count,
                COALESCE(SUM(py.amount), 0) AS total_amount
             FROM {{%payments}} py
             INNER JOIN {{%invoices}} i ON i.id = py.invoice_id
             INNER JOIN {{%visits}} v ON v.id = i.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE py.status = 'completed'
               AND {$w['sql']} {$payExtra}
             GROUP BY py.payment_method, COALESCE(NULLIF(TRIM(py.payment_channel), ''), py.payment_method)
             ORDER BY total_amount DESC",
            $payParams
        )->queryAll();

        $cashTotal = 0.0;
        $nonCashTotal = 0.0;
        $paymentTxCount = 0;
        foreach ($byPaymentMethod as $pm) {
            $amt = (float) $pm['total_amount'];
            $paymentTxCount += (int) $pm['transaction_count'];
            if ($pm['payment_method'] === 'cash') {
                $cashTotal += $amt;
            } else {
                $nonCashTotal += $amt;
            }
        }

        // Revenue by Department
        $byDepartment = $db->createCommand(
            "SELECT
                d.id AS department_id,
                d.code,
                d.name,
                COUNT(i.id) AS invoice_count,
                COALESCE(SUM(i.total_amount), 0) AS total_billed,
                COALESCE(SUM(i.paid_amount), 0) AS total_paid,
                COALESCE(SUM(i.balance_amount), 0) AS total_outstanding
             FROM {{%invoices}} i
             INNER JOIN {{%visits}} v ON v.id = i.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             INNER JOIN {{%departments}} d ON d.id = v.department_id
             WHERE i.status = 'issued'
               AND {$w['sql']} {$invExtra}
             GROUP BY d.id, d.code, d.name
             ORDER BY total_billed DESC, d.name ASC",
            $invParams
        )->queryAll();

        return [
            'summary' => [
                'total_invoices' => (int) ($invSummary['total_invoices'] ?? 0),
                'total_billed' => $totalBilled,
                'total_paid' => $totalPaid,
                'total_outstanding' => (float) ($invSummary['total_outstanding'] ?? 0),
                'collection_rate' => $collectionRate,
                'paid_count' => (int) ($invSummary['paid_count'] ?? 0),
                'partially_paid_count' => (int) ($invSummary['partially_paid_count'] ?? 0),
                'unpaid_count' => (int) ($invSummary['unpaid_count'] ?? 0),
                'cash_total' => $cashTotal,
                'non_cash_total' => $nonCashTotal,
                'payment_transaction_count' => $paymentTxCount,
            ],
            'by_component' => array_map(static fn($r) => [
                'item_type' => $r['item_type'],
                'item_count' => (int) $r['item_count'],
                'total_quantity' => (int) $r['total_quantity'],
                'total_amount' => (float) $r['total_amount'],
            ], $byComponent),
            'by_payment_method' => array_map(static fn($r) => [
                'payment_method' => $r['payment_method'],
                'payment_channel' => $r['payment_channel'],
                'transaction_count' => (int) $r['transaction_count'],
                'total_amount' => (float) $r['total_amount'],
            ], $byPaymentMethod),
            'by_department' => array_map(static fn($r) => [
                'department_id' => (int) $r['department_id'],
                'code' => $r['code'],
                'name' => $r['name'],
                'invoice_count' => (int) $r['invoice_count'],
                'total_billed' => (float) $r['total_billed'],
                'total_paid' => (float) $r['total_paid'],
                'total_outstanding' => (float) $r['total_outstanding'],
            ], $byDepartment),
        ];
    }

    // =========================================================================
    // Paginated Drill-Down Detail Queries (GET /api/reports/details)
    // =========================================================================

    private function queryVisitsDetails(array $resolved, int $page, int $perPage): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);
        $offset = ($page - 1) * $perPage;

        $total = (int) $db->createCommand(
            "SELECT COUNT(v.id)
             FROM {{%visits}} v
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']}",
            $w['params']
        )->queryScalar();

        $rows = $db->createCommand(
            "WITH patient_first_visit AS (
                SELECT DISTINCT ON (patient_id) patient_id, id AS first_visit_id
                FROM {{%visits}}
                WHERE status <> 'cancelled'
                ORDER BY patient_id, visit_date ASC, id ASC
             )
             SELECT
                v.id,
                v.visit_number,
                v.visit_date,
                v.queue_number,
                v.queue_status,
                v.status,
                p.medical_record_number,
                p.full_name AS patient_name,
                p.gender,
                EXTRACT(YEAR FROM AGE(v.visit_date, p.birth_date))::int AS patient_age,
                CASE WHEN v.status <> 'cancelled' AND fv.first_visit_id = v.id THEN 'baru' ELSE 'lama' END AS visit_category,
                d.name AS department_name,
                doc.full_name AS doctor_name
             FROM {{%visits}} v
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             INNER JOIN {{%departments}} d ON d.id = v.department_id
             INNER JOIN {{%doctors}} doc ON doc.id = v.doctor_id
             LEFT JOIN patient_first_visit fv ON fv.patient_id = v.patient_id
             WHERE {$w['sql']}
             ORDER BY v.visit_date DESC, v.id DESC
             LIMIT :limit OFFSET :offset",
            array_merge($w['params'], [':limit' => $perPage, ':offset' => $offset])
        )->queryAll();

        return ['items' => $rows, 'total' => $total];
    }

    private function queryMorbidityDetails(array $resolved, int $page, int $perPage): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);
        $offset = ($page - 1) * $perPage;

        $total = (int) $db->createCommand(
            "SELECT COUNT(mr.id)
             FROM {{%medical_records}} mr
             INNER JOIN {{%visits}} v ON v.id = mr.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']}",
            $w['params']
        )->queryScalar();

        $rows = $db->createCommand(
            "SELECT
                mr.id,
                v.visit_number,
                v.visit_date,
                p.medical_record_number,
                p.full_name AS patient_name,
                p.gender,
                d.name AS department_name,
                doc.full_name AS doctor_name,
                mr.primary_diagnosis,
                mr.secondary_diagnoses,
                mr.version
             FROM {{%medical_records}} mr
             INNER JOIN {{%visits}} v ON v.id = mr.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             INNER JOIN {{%departments}} d ON d.id = v.department_id
             INNER JOIN {{%doctors}} doc ON doc.id = v.doctor_id
             WHERE {$w['sql']}
             ORDER BY v.visit_date DESC, mr.id DESC
             LIMIT :limit OFFSET :offset",
            array_merge($w['params'], [':limit' => $perPage, ':offset' => $offset])
        )->queryAll();

        return ['items' => $rows, 'total' => $total];
    }

    private function queryProceduresDetails(array $resolved, int $page, int $perPage): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);
        $offset = ($page - 1) * $perPage;

        $total = (int) $db->createCommand(
            "SELECT COUNT(vp.id)
             FROM {{%visit_procedures}} vp
             INNER JOIN {{%visits}} v ON v.id = vp.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']}",
            $w['params']
        )->queryScalar();

        $rows = $db->createCommand(
            "SELECT
                vp.id,
                v.visit_number,
                v.visit_date,
                p.medical_record_number,
                p.full_name AS patient_name,
                d.name AS department_name,
                doc.full_name AS doctor_name,
                pr.code AS procedure_code,
                vp.procedure_name_snapshot AS procedure_name,
                pr.category,
                vp.quantity,
                vp.applied_tariff,
                (vp.applied_tariff * vp.quantity) AS line_total,
                vp.status
             FROM {{%visit_procedures}} vp
             INNER JOIN {{%procedures}} pr ON pr.id = vp.procedure_id
             INNER JOIN {{%visits}} v ON v.id = vp.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             INNER JOIN {{%departments}} d ON d.id = v.department_id
             INNER JOIN {{%doctors}} doc ON doc.id = v.doctor_id
             WHERE {$w['sql']}
             ORDER BY v.visit_date DESC, vp.id DESC
             LIMIT :limit OFFSET :offset",
            array_merge($w['params'], [':limit' => $perPage, ':offset' => $offset])
        )->queryAll();

        return ['items' => $rows, 'total' => $total];
    }

    private function queryPharmacyDetails(array $resolved, int $page, int $perPage): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);
        $offset = ($page - 1) * $perPage;

        $total = (int) $db->createCommand(
            "SELECT COUNT(rx.id)
             FROM {{%prescriptions}} rx
             INNER JOIN {{%visits}} v ON v.id = rx.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']}",
            $w['params']
        )->queryScalar();

        $rows = $db->createCommand(
            "SELECT
                rx.id,
                rx.prescription_number,
                v.visit_number,
                v.visit_date,
                p.medical_record_number,
                p.full_name AS patient_name,
                d.name AS department_name,
                doc.full_name AS doctor_name,
                rx.status,
                rx.dispensed_at,
                COUNT(pi.id) AS item_count,
                COALESCE(SUM(pi.quantity), 0) AS total_units,
                COALESCE(SUM(pi.quantity * pi.unit_price_snapshot), 0) AS total_value
             FROM {{%prescriptions}} rx
             INNER JOIN {{%visits}} v ON v.id = rx.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             INNER JOIN {{%departments}} d ON d.id = v.department_id
             INNER JOIN {{%doctors}} doc ON doc.id = v.doctor_id
             LEFT JOIN {{%prescription_items}} pi ON pi.prescription_id = rx.id
             WHERE {$w['sql']}
             GROUP BY rx.id, rx.prescription_number, v.visit_number, v.visit_date,
                      p.medical_record_number, p.full_name, d.name, doc.full_name, rx.status, rx.dispensed_at
             ORDER BY v.visit_date DESC, rx.id DESC
             LIMIT :limit OFFSET :offset",
            array_merge($w['params'], [':limit' => $perPage, ':offset' => $offset])
        )->queryAll();

        return ['items' => $rows, 'total' => $total];
    }

    private function queryLaboratoryDetails(array $resolved, int $page, int $perPage): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);
        $params = $w['params'];
        $modSql = '';
        if ($resolved['filters']['modality_type'] !== null) {
            $modSql = ' AND lo.modality_type = :modality_type';
            $params[':modality_type'] = $resolved['filters']['modality_type'];
        }
        $offset = ($page - 1) * $perPage;

        $total = (int) $db->createCommand(
            "SELECT COUNT(lo.id)
             FROM {{%lab_orders}} lo
             INNER JOIN {{%visits}} v ON v.id = lo.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE {$w['sql']} {$modSql}",
            $params
        )->queryScalar();

        $rows = $db->createCommand(
            "SELECT
                lo.id,
                lo.order_number,
                v.visit_number,
                v.visit_date,
                p.medical_record_number,
                p.full_name AS patient_name,
                d.name AS department_name,
                lo.modality_type,
                lo.test_name,
                lo.body_part,
                lo.tariff_snapshot,
                lo.status,
                lo.integration_status,
                lo.external_reference_id,
                lr.result_source,
                lr.recorded_at AS result_recorded_at
             FROM {{%lab_orders}} lo
             INNER JOIN {{%visits}} v ON v.id = lo.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             INNER JOIN {{%departments}} d ON d.id = v.department_id
             LEFT JOIN {{%lab_results}} lr ON lr.lab_order_id = lo.id
             WHERE {$w['sql']} {$modSql}
             ORDER BY v.visit_date DESC, lo.id DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, [':limit' => $perPage, ':offset' => $offset])
        )->queryAll();

        return ['items' => $rows, 'total' => $total];
    }

    private function queryFinanceDetails(array $resolved, int $page, int $perPage): array
    {
        $db = Yii::$app->db;
        $w = $this->buildVisitWhere($resolved, true);
        $params = $w['params'];
        $extra = '';
        if ($resolved['filters']['payment_status'] !== null) {
            $extra .= ' AND i.payment_status = :payment_status';
            $params[':payment_status'] = $resolved['filters']['payment_status'];
        }
        if ($resolved['filters']['payment_method'] !== null) {
            $extra .= " AND EXISTS (SELECT 1 FROM {{%payments}} pyf WHERE pyf.invoice_id = i.id AND pyf.status = 'completed' AND pyf.payment_method = :payment_method)";
            $params[':payment_method'] = $resolved['filters']['payment_method'];
        }
        $offset = ($page - 1) * $perPage;

        $total = (int) $db->createCommand(
            "SELECT COUNT(i.id)
             FROM {{%invoices}} i
             INNER JOIN {{%visits}} v ON v.id = i.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             WHERE i.status = 'issued'
               AND {$w['sql']} {$extra}",
            $params
        )->queryScalar();

        $rows = $db->createCommand(
            "SELECT
                i.id,
                i.invoice_number,
                i.revision_number,
                i.issued_at,
                v.visit_number,
                v.visit_date,
                p.medical_record_number,
                p.full_name AS patient_name,
                d.name AS department_name,
                i.total_amount,
                i.paid_amount,
                i.balance_amount,
                i.payment_status
             FROM {{%invoices}} i
             INNER JOIN {{%visits}} v ON v.id = i.visit_id
             INNER JOIN {{%patients}} p ON p.id = v.patient_id
             INNER JOIN {{%departments}} d ON d.id = v.department_id
             WHERE i.status = 'issued'
               AND {$w['sql']} {$extra}
             ORDER BY v.visit_date DESC, i.id DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, [':limit' => $perPage, ':offset' => $offset])
        )->queryAll();

        return ['items' => $rows, 'total' => $total];
    }
}
