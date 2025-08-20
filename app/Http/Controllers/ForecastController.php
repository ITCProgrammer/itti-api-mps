<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    public function getForecastData(Request $request)
    {
        $filterType = $request->get('data_filter', 'day');
        $filterType = in_array($filterType, ['day', 'week', 'month']) ? $filterType : 'day';
        $today = now()->format('Y-m-d');
        $date_cutoff = now()->subDay()->format('Y-m-d');
        $date_cutoff_formated = now()->subDay()->format('d-M-Y');

        // === 1. Generate 360 hari (daily basis) ===
        $dynamic_days = $this->generateDynamicDays(360);

        // === 2. Generate ranges: day, week, or month (untuk semua) ===
        $dateRanges = [
            'day'   => $this->generateDateRanges('day', $dynamic_days),
            'week'  => $this->generateDateRanges('week', $dynamic_days),
            'month' => $this->generateDateRanges('month', $dynamic_days),
        ];

        // === 3. Get holidays ===
        $holidays = $this->getHolidaysFromDB2();

        // === 4. Get forecast from SQL Server (daily) ===
        $forecastData = $this->getForecastFromSQLServer($today, $dynamic_days);
        $result = $this->calculateYarnRequirements($forecastData, $dynamic_days);
        $groupedData = $this->groupDataByDescription($result, $dynamic_days);

        // dd($groupedData);

        // === 5. Enrich with PO and Balance only (NO AGGREGATION) ===
        $this->enrichGroupedData($groupedData, $dynamic_days, $holidays, $date_cutoff);

        // Kirim filterType yang diminta dan semua dateRanges
        return compact('groupedData', 'dateRanges', 'holidays', 'filterType', 'date_cutoff', 'date_cutoff_formated');
    }

    private function generateDynamicDays($total_days = 360)
    {
        $days = [];
        $current = now();

        for ($i = 0; $i < $total_days; $i++) {
            $days[] = [
                'date'          => $current->copy(),
                'date_formated' => $current->format('Y-m-d'),
                'label_date' => $current->format('d-M-Y'),
                'column_name'   => 'DAY_' . $current->format('Ymd'),
            ];
            $current->addDay();
        }

        return $days;
    }

    private function generateDateRanges($type, $dynamic_days)
    {
        $ranges = [];

        if ($type === 'day') {
            foreach ($dynamic_days as $idx => $day) {
                $ranges[] = [
                    'date'          => $day['date'],
                    'date_formated' => $day['date_formated'],
                    'date_label'    => $day['date']->format('d-M-Y'),
                    'index'         => $idx + 1,
                ];
            }
        } elseif ($type === 'week') {
            $weeks = [];
            foreach ($dynamic_days as $idx => $day) {
                $weekKey = $day['date']->year . '-W' . $day['date']->weekOfYear;
                if (!isset($weeks[$weekKey])) {
                    $weeks[$weekKey] = [
                        'date'          => $day['date']->copy(),
                        'start'         => $day['date']->copy()->startOfWeek(),
                        'end'           => $day['date']->copy()->endOfWeek(),
                        'date_label'    => [
                            'week'  => 'W' . $day['date']->weekOfYear,
                            'start' => $day['date']->copy()->startOfWeek()->format('d-M-Y'),
                            'end'   => $day['date']->copy()->endOfWeek()->format('d-M-Y'),
                        ],
                        'indices'       => [],
                    ];
                }
                $weeks[$weekKey]['indices'][] = $idx + 1;
            }
            $ranges = array_values($weeks);
        } elseif ($type === 'month') {
            $months = [];
            foreach ($dynamic_days as $idx => $day) {
                $monthKey = $day['date']->format('Y-m');
                if (!isset($months[$monthKey])) {
                    $months[$monthKey] = [
                        'date'          => $day['date']->copy(),
                        'date_label'    => $day['date']->format('M Y'),
                        'indices'       => [],
                    ];
                }
                $months[$monthKey]['indices'][] = $idx + 1;
            }
            $ranges = array_values($months);
        }

        return $ranges;
    }

    private function getHolidaysFromDB2()
    {
        return Cache::remember('forecast_holidays_db2', now()->addHours(24), function () {
            $query = "
                SELECT DISTINCT
                    TRIM(c.YEAR) || '-' || RIGHT('00' || TRIM(c.HOLIDAYMONTH), 2) || '-' || RIGHT('00' || TRIM(c.HOLIDAY), 2) AS HOLIDAYDATE,
                    HOLIDAYDESCRIPTION AS LONGDESCRIPTION
                FROM WORKINGCALENDAR w
                LEFT JOIN CALENDARHOLIDAY c ON w.CODE = c.WORKINGCALENDARCODE
                WHERE w.CODE = '001'
            ";

            $result = DB::connection('DB2')->select($query);
            $holidays = [];

            foreach ($result as $row) {
                $holidays[$row->holidaydate] = $row->longdescription;
            }

            return $holidays;
        });
    }

    private function getForecastFromSQLServer($today, $dynamic_days)
    {
        Log::info('Jumlah dynamic_days di SQL: ' . count($dynamic_days));
        $columns_sql = '';
        foreach ($dynamic_days as $dy) {
            $date = $dy['date_formated'];
            $col = $dy['column_name'];
            $columns_sql .= "CEILING(SUM(CASE WHEN '$date' BETWEEN CAST(s.date_startplann AS DATE) AND CAST(s.date_endplann AS DATE) THEN TRY_CAST(s.qty_day AS FLOAT) ELSE 0 END)) AS [$col],";
        }
        $columns_sql = rtrim($columns_sql, ',');

        $query = "
            SELECT s.item_code, $columns_sql
            FROM dbo.schedule_knitting_forecast s
            WHERE s.date_startplann >= ?
            GROUP BY s.item_code
        ";

        return DB::connection('sqlsrv_knt')->select($query, [$today]);
    }

    private function calculateYarnRequirements($forecastData, $dynamic_days)
    {
        $result = [];

        foreach ($forecastData as $row) {
            $item_code = $row->item_code;
            $parts = explode('-', $item_code);
            if (count($parts) < 3) continue;

            [$subcode02, $subcode03, $subcode04] = $parts;

            $bomQuery = "
                SELECT
                    b.SUBCODE01, b.SUBCODE02, b.SUBCODE03, b.SUBCODE04,
                    b.SUBCODE05, b.SUBCODE06, b.SUBCODE07, b.SUBCODE08, b.SUBCODE09,
                    TRIM(b.SUBCODE01)||'-'||TRIM(b.SUBCODE02)||'-'||TRIM(b.SUBCODE03)||'-'||
                    TRIM(b.SUBCODE04)||'-'||TRIM(b.SUBCODE05)||'-'||TRIM(b.SUBCODE06)||'-'||
                    TRIM(b.SUBCODE07)||'-'||TRIM(b.SUBCODE08) AS GROUPCODE,
                    p.SHORTDESCRIPTION,
                    b.QUANTITYPER
                FROM BOMCOMPONENT b
                LEFT JOIN FULLITEMKEYDECODER p ON
                    p.ITEMTYPECODE = b.ITEMTYPEAFICODE AND
                    p.SUBCODE01 = b.SUBCODE01 AND
                    p.SUBCODE02 = b.SUBCODE02 AND
                    p.SUBCODE03 = b.SUBCODE03 AND
                    p.SUBCODE04 = b.SUBCODE04 AND
                    p.SUBCODE05 = b.SUBCODE05 AND
                    p.SUBCODE06 = b.SUBCODE06 AND
                    p.SUBCODE07 = b.SUBCODE07 AND
                    p.SUBCODE08 = b.SUBCODE08 AND
                    p.SUBCODE09 = b.SUBCODE09
                WHERE
                    b.ITEMTYPEAFICODE IN ('GYR','DYR') AND
                    b.BILLOFMATERIALITEMTYPECODE = 'KGF' AND
                    b.BILLOFMATERIALSUBCODE02 = ? AND
                    b.BILLOFMATERIALSUBCODE03 = ? AND
                    b.BILLOFMATERIALSUBCODE04 = ?
            ";

            $bomResults = DB::connection('DB2')->select($bomQuery, [$subcode02, $subcode03, $subcode04]);

            foreach ($bomResults as $bom) {
                $rowData = [
                    'SHORTDESCRIPTION' => $bom->shortdescription,
                    'GROUPCODE'        => $bom->groupcode,
                    'SUBCODE01'        => trim($bom->subcode01),
                    'SUBCODE02'        => trim($bom->subcode02),
                    'SUBCODE03'        => trim($bom->subcode03),
                    'SUBCODE04'        => trim($bom->subcode04),
                    'SUBCODE05'        => trim($bom->subcode05),
                    'SUBCODE06'        => trim($bom->subcode06),
                    'SUBCODE07'        => trim($bom->subcode07),
                    'SUBCODE08'        => trim($bom->subcode08),
                    'total_qty'        => 0,
                ] + array_fill_keys(range(1, 360), 0);

                foreach ($dynamic_days as $idx => $dw) {
                    $colName = $dw['column_name'];
                    $value = property_exists($row, $colName) ? (float)$row->$colName : 0;
                    $calculated = $value * $bom->quantityper;
                    $rowData[$idx + 1] = $calculated;
                    $rowData['total_qty'] += $calculated;
                }

                $result[] = $rowData;
            }
        }

        return $result;
    }

    private function groupDataByDescription($result, $dynamic_days)
    {
        $grouped = [];
        $totalDays = 360;
    
        foreach ($result as $item) {
            $key = $item['SHORTDESCRIPTION'];
        
            if (!isset($grouped[$key])) {
                // ✅ Inisialisasi manual: pastikan semua key 1..360 ada
                $grouped[$key] = [
                    'SHORTDESCRIPTION' => $key,
                    'total_qty' => 0,
                    'GROUPCODE' => '',
                ];
            
                // Isi key 1 sampai 360 dengan 0
                for ($i = 1; $i <= 360; $i++) {
                    $grouped[$key][$i] = 0;
                }
            }
        
            foreach ($dynamic_days as $idx => $dw) {
                $index = $idx + 1;
            
                // Pastikan $item[$index] ada
                $value = ROUND($item[$index]) ?? 0;
            
                // 🔴 Cek apakah $grouped[$key][$index] ada
                if (!isset($grouped[$key][$index])) {
                    Log::error("BUG: grouped[$key] TIDAK PUNYA KEY $index", [
                        'available_keys' => array_keys($grouped[$key]),
                    ]);
                }
            
                $grouped[$key][$index] += $value;
            }
        
            $grouped[$key]['total_qty'] += ROUND($item['total_qty'],0);
            $grouped[$key]['GROUPCODE'] = $item['GROUPCODE'];
        
            foreach (['SUBCODE01','SUBCODE02','SUBCODE03','SUBCODE04','SUBCODE05','SUBCODE06','SUBCODE07','SUBCODE08'] as $field) {
                $grouped[$key][$field] = $item[$field];
            }
        }
    
        return $grouped;
    }

    // ✅ Hanya tambahkan PO dan Balance — TIDAK ADA AGGREGASI
    private function enrichGroupedData(&$groupedData, $dynamic_days, $holidays, $date_cutoff)
    {
        $startDate = $dynamic_days[0]['date_formated'];
        $endDate = end($dynamic_days)['date_formated'];

        $cacheKey = "forecast_po_all_" . md5($startDate . $endDate);
        $allPOs = $this->fetchAllPOs($startDate, $endDate);
        // dd($allPOs);

        foreach ($groupedData as $key => $rowData) {
            // dd($groupedData);
            $kode_benang = $rowData['GROUPCODE'];

            // === Assign PO harian ===
            $poData = [];
            foreach ($dynamic_days as $idx => $day) {
                $date = $day['label_date'];
                $date_PO = $day['date_formated'];
                $poKey = "{$kode_benang}-{$date_PO}";
                $poData[$date_PO] = $allPOs[$poKey] ?? 0;
            }
            $rowData['po_data'] = $poData;

            // === Balance dari MySQL ===
            $balanceQuery = "
                SELECT SUM(weight) AS BASEPRIMARYQUANTITYUNIT
                FROM tblopname_detail_11
                WHERE kd_benang = ? AND tgl_tutup = ?
                GROUP BY kd_benang, tgl_tutup
            ";
            $balanceResult = DB::connection('mysql_gdb')->select($balanceQuery, [$kode_benang, $date_cutoff]);
            $balance = !empty($balanceResult) ? (float)$balanceResult[0]->BASEPRIMARYQUANTITYUNIT : 0;
            $rowData['BALANCE_BENANG'] = $balance;

            $groupedData[$key] = $rowData;
        }

        // dd($groupedData);

    }

    private function fetchAllPOs($startDate, $endDate)
    {
        $query = "
            SELECT
                p2.REQUIREDDUEDATE AS DUE_DATE,
                p.SUBCODE01, p.SUBCODE02, p.SUBCODE03, p.SUBCODE04,
                p.SUBCODE05, p.SUBCODE06, p.SUBCODE07, p.SUBCODE08,
                SUM(p.BASEPRIMARYQUANTITY - COALESCE(L.TOTAL_DITERIMA, 0)) AS QTY
            FROM PURCHASEORDERLINE p
            LEFT JOIN PURCHASEORDER p2 ON p2.CODE = p.PURCHASEORDERCODE
            LEFT JOIN (
                SELECT
                    SUM(BASEPRIMARYQTY) AS TOTAL_DITERIMA,
                    SUBCODE01, SUBCODE02, SUBCODE03, SUBCODE04,
                    SUBCODE05, SUBCODE06, SUBCODE07, SUBCODE08,
                    PURCHASEORDERCODE
                FROM MRNDETAIL
                WHERE ITEMTYPEAFICODE IN ('GYR','DYR')
                GROUP BY PURCHASEORDERCODE, SUBCODE01, SUBCODE02, SUBCODE03, SUBCODE04, SUBCODE05, SUBCODE06, SUBCODE07, SUBCODE08
            ) L ON
                L.SUBCODE01 = p.SUBCODE01 AND
                L.SUBCODE02 = p.SUBCODE02 AND
                L.SUBCODE03 = p.SUBCODE03 AND
                L.SUBCODE04 = p.SUBCODE04 AND
                L.SUBCODE05 = p.SUBCODE05 AND
                L.SUBCODE06 = p.SUBCODE06 AND
                L.SUBCODE07 = p.SUBCODE07 AND
                L.SUBCODE08 = p.SUBCODE08 AND
                L.PURCHASEORDERCODE = p.PURCHASEORDERCODE
            WHERE
                p.ITEMTYPEAFICODE IN ('GYR','DYR') AND
                (p.BASEPRIMARYQUANTITY - COALESCE(L.TOTAL_DITERIMA, 0)) > 0 AND
                p2.REQUIREDDUEDATE BETWEEN ? AND ?
            GROUP BY p2.REQUIREDDUEDATE, p.SUBCODE01, p.SUBCODE02, p.SUBCODE03, p.SUBCODE04, p.SUBCODE05, p.SUBCODE06, p.SUBCODE07, p.SUBCODE08
        ";

        $results = DB::connection('DB2')->select($query, [$startDate, $endDate]);
        $poData = [];

        foreach ($results as $row) {
            $groupCode = implode('-', array_map('trim', [
                $row->subcode01, $row->subcode02, $row->subcode03, $row->subcode04,
                $row->subcode05, $row->subcode06, $row->subcode07, $row->subcode08
            ]));
            $poData["{$groupCode}-{$row->due_date}"] = $row->qty;
        }
        // dd($poData);
        return $poData;
    }
}
