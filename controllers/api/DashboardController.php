<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use app\components\PermissionFilter;

/**
 * DashboardController provides authoritative PostgreSQL aggregations for Dashboard & Reports (FR-08).
 * Consumed by Node.js supporting service (/node-api/dashboard/summary) and directly as fallback.
 */
class DashboardController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'dashboard',
            'actions' => [
                'metrics' => 'read',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/dashboard/metrics
     * Query: from (YYYY-MM-DD), to (YYYY-MM-DD), department_id
     */
    public function actionMetrics(): array
    {
        $request = Yii::$app->request;
        $from = trim((string) $request->get('from', date('Y-m-01')));
        $to = trim((string) $request->get('to', date('Y-m-d')));
        $deptId = $request->get('department_id');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }

        $db = Yii::$app->db;
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';

        $visitParams = [':from' => $from, ':to' => $to];
        $deptSql = '';
        if ($deptId !== null && $deptId !== '') {
            $deptSql = ' AND v.department_id = :dept_id';
            $visitParams[':dept_id'] = (int) $deptId;
        }

        // 1. Total visits & by status
        $visitTotals = $db->createCommand(
            "SELECT
                COUNT(*) AS total_visits,
                COUNT(*) FILTER (WHERE v.status = 'completed') AS completed_visits,
                COUNT(*) FILTER (WHERE v.status IN ('registered', 'in_progress')) AS active_visits,
                COUNT(*) FILTER (WHERE v.status = 'cancelled') AS cancelled_visits
             FROM {{%visits}} v
             WHERE v.visit_date BETWEEN :from AND :to {$deptSql}",
            $visitParams
        )->queryOne();

        // 2. Visits by department (poli)
        $visitsByDept = $db->createCommand(
            "SELECT d.id AS department_id, d.code, d.name, COUNT(v.id) AS visit_count
             FROM {{%departments}} d
             LEFT JOIN {{%visits}} v
                ON v.department_id = d.id
                AND v.visit_date BETWEEN :from AND :to
                AND v.status <> 'cancelled'
             WHERE d.is_active = TRUE
             GROUP BY d.id, d.code, d.name
             ORDER BY visit_count DESC, d.name ASC",
            [':from' => $from, ':to' => $to]
        )->queryAll();

        // 3. Medical procedures count
        $procedureStats = $db->createCommand(
            "SELECT
                COUNT(vp.id) AS procedure_count,
                COALESCE(SUM(vp.quantity), 0) AS procedure_total_qty,
                COALESCE(SUM(vp.applied_tariff * vp.quantity), 0) AS procedure_revenue
             FROM {{%visit_procedures}} vp
             INNER JOIN {{%visits}} v ON v.id = vp.visit_id
             WHERE vp.status = 'active'
               AND v.visit_date BETWEEN :from AND :to {$deptSql}",
            $visitParams
        )->queryOne();

        // 4. Billing & revenue summary + payment statuses
        $billingSummary = $db->createCommand(
            "SELECT
                COUNT(i.id) AS total_invoices,
                COALESCE(SUM(i.total_amount), 0) AS total_billed,
                COALESCE(SUM(i.paid_amount), 0) AS total_paid,
                COALESCE(SUM(i.balance_amount), 0) AS total_outstanding,
                COUNT(i.id) FILTER (WHERE i.payment_status = 'unpaid') AS unpaid_count,
                COUNT(i.id) FILTER (WHERE i.payment_status = 'partially_paid') AS partially_paid_count,
                COUNT(i.id) FILTER (WHERE i.payment_status = 'paid') AS paid_count
             FROM {{%invoices}} i
             INNER JOIN {{%visits}} v ON v.id = i.visit_id
             WHERE i.status = 'issued'
               AND v.visit_date BETWEEN :from AND :to {$deptSql}",
            $visitParams
        )->queryOne();

        // 5. Medicine usage summary (dispensed stock movements)
        $medicineUsage = $db->createCommand(
            "SELECT
                m.id AS medicine_id,
                m.code,
                m.name,
                m.unit,
                m.stock AS current_stock,
                COALESCE(SUM(ABS(mv.quantity_delta)), 0) AS dispensed_qty
             FROM {{%medicine_stock_movements}} mv
             INNER JOIN {{%medicines}} m ON m.id = mv.medicine_id
             WHERE mv.movement_type = 'dispense'
               AND mv.created_at BETWEEN :from_ts AND :to_ts
             GROUP BY m.id, m.code, m.name, m.unit, m.stock
             ORDER BY dispensed_qty DESC, m.name ASC
             LIMIT 10",
            [':from_ts' => $fromTs, ':to_ts' => $toTs]
        )->queryAll();

        $totalDispensedUnits = 0;
        foreach ($medicineUsage as &$row) {
            $row['dispensed_qty'] = (int) $row['dispensed_qty'];
            $row['current_stock'] = (int) $row['current_stock'];
            $totalDispensedUnits += $row['dispensed_qty'];
        }
        unset($row);

        // 6. Lab orders summary
        $labSummary = $db->createCommand(
            "SELECT
                COUNT(lo.id) AS total_lab_orders,
                COUNT(lo.id) FILTER (WHERE lo.status = 'completed') AS completed_lab_orders
             FROM {{%lab_orders}} lo
             INNER JOIN {{%visits}} v ON v.id = lo.visit_id
             WHERE v.visit_date BETWEEN :from AND :to {$deptSql}",
            $visitParams
        )->queryOne();

        return [
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $from,
                    'to' => $to,
                    'department_id' => $deptId !== null && $deptId !== '' ? (int) $deptId : null,
                ],
                'visits' => [
                    'total' => (int) ($visitTotals['total_visits'] ?? 0),
                    'active' => (int) ($visitTotals['active_visits'] ?? 0),
                    'completed' => (int) ($visitTotals['completed_visits'] ?? 0),
                    'cancelled' => (int) ($visitTotals['cancelled_visits'] ?? 0),
                ],
                'visits_by_department' => array_map(fn($r) => [
                    'department_id' => (int) $r['department_id'],
                    'code' => $r['code'],
                    'name' => $r['name'],
                    'visit_count' => (int) $r['visit_count'],
                ], $visitsByDept),
                'procedures' => [
                    'count' => (int) ($procedureStats['procedure_count'] ?? 0),
                    'total_quantity' => (int) ($procedureStats['procedure_total_qty'] ?? 0),
                    'revenue' => (float) ($procedureStats['procedure_revenue'] ?? 0),
                ],
                'revenue' => [
                    'total_invoices' => (int) ($billingSummary['total_invoices'] ?? 0),
                    'total_billed' => (float) ($billingSummary['total_billed'] ?? 0),
                    'total_paid' => (float) ($billingSummary['total_paid'] ?? 0),
                    'total_outstanding' => (float) ($billingSummary['total_outstanding'] ?? 0),
                ],
                'payment_status' => [
                    'unpaid' => (int) ($billingSummary['unpaid_count'] ?? 0),
                    'partially_paid' => (int) ($billingSummary['partially_paid_count'] ?? 0),
                    'paid' => (int) ($billingSummary['paid_count'] ?? 0),
                ],
                'medicine_usage' => [
                    'total_dispensed_units' => $totalDispensedUnits,
                    'top_medicines' => $medicineUsage,
                ],
                'laboratory' => [
                    'total_orders' => (int) ($labSummary['total_lab_orders'] ?? 0),
                    'completed_orders' => (int) ($labSummary['completed_lab_orders'] ?? 0),
                ],
            ],
        ];
    }
}
