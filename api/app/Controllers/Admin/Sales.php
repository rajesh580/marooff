<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Admin sales analytics — aggregates the `orders` table into the numbers and
 * lists the storefront-owner needs to see what customers have been buying.
 *
 *   GET /api/admin/sales/summary    → headline KPIs (revenue, AOV, splits, top products/customers)
 *   GET /api/admin/sales/series     → daily revenue + order count for the last N days
 *   GET /api/admin/sales/customers  → ranked customer list with their lifetime spend
 *
 * All revenue numbers are in fils (AED minor units) so the frontend can format consistently.
 */
class Sales extends BaseController
{
    /** GET /api/admin/sales/summary */
    public function summary()
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d');
        $d7  = date('Y-m-d', strtotime('-6 days'));   // inclusive 7-day window
        $d30 = date('Y-m-d', strtotime('-29 days'));  // inclusive 30-day window

        // -- Counts by status (incl. total) ----------------------------------
        $statusRows = $db->table('orders')
            ->select('status, COUNT(*) AS c, COALESCE(SUM(grand_total_minor),0) AS rev')
            ->groupBy('status')->get()->getResultArray();

        $counts  = ['placed' => 0, 'confirmed' => 0, 'shipped' => 0, 'delivered' => 0, 'cancelled' => 0, 'refunded' => 0];
        $revByStatus = ['placed' => 0, 'confirmed' => 0, 'shipped' => 0, 'delivered' => 0, 'cancelled' => 0, 'refunded' => 0];
        foreach ($statusRows as $r) {
            if (isset($counts[$r['status']])) {
                $counts[$r['status']]      = (int) $r['c'];
                $revByStatus[$r['status']] = (int) $r['rev'];
            }
        }
        $totalOrders        = array_sum($counts);
        $nonCancelledOrders = $totalOrders - $counts['cancelled'] - $counts['refunded'];

        // -- Revenue (excludes cancelled + refunded) -------------------------
        $revTotal = (int) ($db->table('orders')
            ->selectSum('grand_total_minor', 'rev')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->get()->getRow('rev') ?? 0);

        $revToday = (int) ($db->table('orders')
            ->selectSum('grand_total_minor', 'rev')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where('DATE(placed_at)', $now)
            ->get()->getRow('rev') ?? 0);

        $rev7 = (int) ($db->table('orders')
            ->selectSum('grand_total_minor', 'rev')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where('DATE(placed_at) >=', $d7)
            ->get()->getRow('rev') ?? 0);

        $rev30 = (int) ($db->table('orders')
            ->selectSum('grand_total_minor', 'rev')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where('DATE(placed_at) >=', $d30)
            ->get()->getRow('rev') ?? 0);

        $aov = $nonCancelledOrders ? (int) round($revTotal / $nonCancelledOrders) : 0;

        // -- Payment method split (revenue + count) -------------------------
        $payRows = $db->table('orders')
            ->select('payment_method, COUNT(*) AS c, COALESCE(SUM(grand_total_minor),0) AS rev')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->groupBy('payment_method')->get()->getResultArray();

        $payment = [
            'cod'    => ['orders' => 0, 'revenue' => 0],
            'stripe' => ['orders' => 0, 'revenue' => 0],
            'tamara' => ['orders' => 0, 'revenue' => 0],
        ];
        foreach ($payRows as $r) {
            $method = strtolower((string) ($r['payment_method'] ?? 'cod'));
            if (!isset($payment[$method])) {
                $payment[$method] = ['orders' => 0, 'revenue' => 0];
            }
            $payment[$method]['orders']  += (int) $r['c'];
            $payment[$method]['revenue'] += (int) $r['rev'];
        }

        // -- Unique customers ------------------------------------------------
        $customerCount = (int) ($db->table('orders')
            ->select('COUNT(DISTINCT user_id) AS c')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->get()->getRow('c') ?? 0);

        // -- Top 5 products by units sold + revenue (snapshot fields) -------
        $topProducts = $db->table('order_items oi')
            ->select('oi.product_id, oi.name_snapshot AS name, SUM(oi.qty) AS units, SUM(oi.line_total_minor) AS revenue')
            ->join('orders o', 'o.id = oi.order_id')
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->groupBy('oi.product_id, oi.name_snapshot')
            ->orderBy('revenue', 'DESC')
            ->limit(5)
            ->get()->getResultArray();

        foreach ($topProducts as &$row) {
            $row['units']   = (int) $row['units'];
            $row['revenue'] = (int) $row['revenue'];
        }
        unset($row);

        // -- Top 5 customers by lifetime spend ------------------------------
        $topCustomers = $db->table('orders')
            ->select('user_id, customer_name, customer_email, customer_phone,
                      COUNT(*) AS orders_count,
                      COALESCE(SUM(grand_total_minor),0) AS spend')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->groupBy('user_id, customer_name, customer_email, customer_phone')
            ->orderBy('spend', 'DESC')
            ->limit(5)
            ->get()->getResultArray();

        foreach ($topCustomers as &$row) {
            $row['orders_count'] = (int) $row['orders_count'];
            $row['spend']        = (int) $row['spend'];
        }
        unset($row);

        // -- Recent 10 orders for the snapshot table ------------------------
        $recent = $db->table('orders')
            ->select('id, order_number, customer_name, customer_email, customer_phone,
                      status, payment_method, payment_status, grand_total_minor, currency, placed_at')
            ->orderBy('id', 'DESC')->limit(10)->get()->getResultArray();

        return $this->ok([
            'currency'             => 'AED',
            'counts'               => array_merge($counts, ['total' => $totalOrders]),
            'revenue' => [
                'total_minor'      => $revTotal,
                'today_minor'      => $revToday,
                'last_7d_minor'    => $rev7,
                'last_30d_minor'   => $rev30,
                'aov_minor'        => $aov,
            ],
            'revenue_by_status'    => $revByStatus,
            'payment'              => $payment,
            'customers' => [
                'unique_buyers'    => $customerCount,
            ],
            'top_products'         => $topProducts,
            'top_customers'        => $topCustomers,
            'recent_orders'        => $recent,
        ]);
    }

    /**
     * GET /api/admin/sales/series?days=30
     * Daily revenue + order count for the chart. Returns one row per day in the window,
     * including days with zero sales (so the chart x-axis is continuous).
     */
    public function series()
    {
        $days = max(7, min(90, (int) ($this->request->getGet('days') ?? 30)));
        $db   = \Config\Database::connect();
        $from = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));

        $rows = $db->table('orders')
            ->select("DATE(placed_at) AS d,
                      COUNT(*) AS c,
                      COALESCE(SUM(grand_total_minor),0) AS rev")
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where('DATE(placed_at) >=', $from)
            ->groupBy('DATE(placed_at)')
            ->orderBy('d', 'ASC')
            ->get()->getResultArray();

        $byDate = [];
        foreach ($rows as $r) $byDate[$r['d']] = ['orders' => (int) $r['c'], 'revenue' => (int) $r['rev']];

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $out[] = [
                'date'    => $d,
                'orders'  => $byDate[$d]['orders']  ?? 0,
                'revenue' => $byDate[$d]['revenue'] ?? 0,
            ];
        }
        return $this->ok(['days' => $days, 'series' => $out]);
    }

    /**
     * GET /api/admin/sales/customers?limit=50
     * Paginated lifetime-customer list for the bottom of the Sales page.
     */
    public function customers()
    {
        [$page, $limit, $offset] = $this->pageParams(50, 200);
        $db = \Config\Database::connect();

        $base = $db->table('orders')
            ->select('user_id, customer_name, customer_email, customer_phone,
                      COUNT(*) AS orders_count,
                      COALESCE(SUM(grand_total_minor),0) AS spend,
                      MAX(placed_at) AS last_order_at')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->groupBy('user_id, customer_name, customer_email, customer_phone');

        $total = (int) ($db->query('SELECT COUNT(*) AS c FROM (' .
            $base->getCompiledSelect(false) . ') t')->getRow('c') ?? 0);

        $rows = $base->orderBy('spend', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['orders_count'] = (int) $r['orders_count'];
            $r['spend']        = (int) $r['spend'];
        }
        unset($r);

        return $this->ok($rows, [
            'page' => $page, 'limit' => $limit, 'total' => $total,
            'last_page' => $total ? (int) ceil($total / $limit) : 1,
        ]);
    }
}
