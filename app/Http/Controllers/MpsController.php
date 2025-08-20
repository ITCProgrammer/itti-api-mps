<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use \DateTime;
use Carbon\Carbon;

class MpsController extends Controller
{
    public function index(Request $request)
    {
        $filterType = $request->get('data_filter', 'day');

        $dataMesin = DB::connection('sqlsrv')->select('EXEC sp_get_mesin');
        $dataDB2 = DB::connection('sqlsrv')->select('EXEC sp_get_schedule');
        $dataFor = DB::connection('sqlsrv')->select('EXEC sp_get_schedule_knt');

        $today = new DateTime();

        if ($filterType === 'week') {
            $startDate = (clone $today)->modify('monday this week')->setTime(0, 0, 0);
            $endDate   = (clone $startDate)->modify('+6 days')->setTime(23, 59, 59);
        } else {
            $startDate = (clone $today)->setTime(0, 0, 0);
            $endDate   = (clone $today)->setTime(23, 59, 59);
        }

        $grouped = [];
        foreach ($dataDB2 as $row) {
            $kdmc = $row->kdmc;
            $grouped[$kdmc][] = $row;
        }

        $finalData = [];
        foreach ($grouped as $kdmc => $rows) {
            usort($rows, function ($a, $b) {
                $aDate = $a->tglmulai ?? $a->tgl_start ?? $a->estimasi_selesai;
                $bDate = $b->tglmulai ?? $b->tgl_start ?? $b->estimasi_selesai;
                return strtotime($aDate) <=> strtotime($bDate);
            });
        
            $lastEstimasi = null;
        
            foreach ($rows as $row) {
                $rawStartStr = $row->tglmulai ?? $row->tgl_start;
                $rawStart = $rawStartStr ? new DateTime($rawStartStr) : null;
                $est = new DateTime($row->estimasi_selesai);
            
                if (!$rawStart || ($lastEstimasi && $rawStart <= $lastEstimasi)) {
                    $start = $lastEstimasi ? (clone $lastEstimasi)->modify('+1 day') : new DateTime();
                    $durasi = max(1, $est->diff($rawStart ?? new DateTime())->days);
                    $est = (clone $start)->modify("+$durasi days");
                } else {
                    $start = $rawStart;
                }
            
                $lastEstimasi = clone $est;
            
                // ✅ Filter sesuai range tanggal
                if ($start <= $endDate && $est >= $startDate) {
                    // Kalau mode week → geser start & end supaya pas ke minggu yang sedang difilter
                    if ($filterType === 'week') {
                        if ($start < $startDate) $start = clone $startDate;
                        if ($est > $endDate) $est = clone $endDate;
                    }
                
                    $finalData[] = (object)[
                        'kdmc' => $row->kdmc,
                        'productiondemandcode' => $row->productiondemandcode,
                        'statusmesin' => $row->statusmesin,
                        'tgl_start' => $filterType === 'week' 
                            ? 'Week ' . $start->format('W') . ' ' . $start->format('m-Y')
                            : $start->format('Y-m-d'),
                        'estimasi_selesai' => $filterType === 'week' 
                            ? 'Week ' . $est->format('W') . ' ' . $est->format('m-Y')
                            : $est->format('Y-m-d'),
                        'tgl_delivery' => $row->tgldelivery,
                        'tgl_mulai' => $row->tglmulai,
                        'subcode01' => $row->subcode01,
                        'subcode02' => $row->subcode02,
                        'subcode03' => $row->subcode03,
                        'subcode04' => $row->subcode04,
                        'qty_sisa' => number_format((float)$row->qty_sisa, 2, '.', ''),
                        'standar_rajut' => number_format((float)$row->standar_rajut, 2, '.', ''),
                        'qty_order' => number_format((float)$row->qty_order, 2, '.', ''),
                    ];
                }
            }
        }

        $finalDataFor = [];
        foreach ($dataFor as $row) {
            $tglStart = $row->tgl_start ? new DateTime($row->tgl_start) : null;
            $tglEnd   = $row->estimasi_selesai ? new DateTime($row->estimasi_selesai) : null;
        
            if ($tglStart && $tglEnd) {
                $finalDataFor[] = (object)[
                    'kdmc' => $row->kdmc,
                    'productiondemandcode' => $row->productiondemandcode,
                    'statusmesin' => $row->statusmesin,
                    'tgl_start' => $filterType === 'week'
                        ? 'Week ' . $tglStart->format('W') . ' ' . $tglStart->format('m-Y')
                        : $tglStart->format('Y-m-d'),
                    'estimasi_selesai' => $filterType === 'week'
                        ? 'Week ' . $tglEnd->format('W') . ' ' . $tglEnd->format('m-Y')
                        : $tglEnd->format('Y-m-d'),
                    'tgl_delivery' => $row->tgldelivery,
                    'tgl_mulai' => $row->tglmulai,
                    'subcode01' => $row->subcode01,
                    'subcode02' => $row->subcode02,
                    'subcode03' => $row->subcode03,
                    'subcode04' => $row->subcode04,
                    'qty_sisa' => number_format((float)$row->qty_sisa, 2, '.', ''),
                    'standar_rajut' => number_format((float)$row->standar_rajut, 2, '.', ''),
                    'qty_order' => number_format((float)$row->qty_order, 2, '.', ''),
                    'splitDemands' => json_decode($row->splitDemands, true) ?? []
                ];
            }
        }

        foreach ($dataMesin as $mesin) {
            $spDetail = DB::connection('sqlsrv')->select('EXEC sp_get_mesin_detail ?', [$mesin->mesin_code]);
            foreach ($spDetail as $row) {
                $tglStart = $row->tgl_start ? new DateTime($row->tgl_start) : null;
                $tglEnd   = $row->estimasi_selesai ? new DateTime($row->estimasi_selesai) : null;

                if ($tglStart && $tglEnd && $tglStart <= $endDate && $tglEnd >= $startDate) {
                    if ($filterType === 'week') {
                        if ($tglStart < $startDate) $tglStart = clone $startDate;
                        if ($tglEnd > $endDate) $tglEnd = clone $endDate;
                    }

                    $finalData[] = (object)[
                        'kdmc' => $row->kdmc,
                        'productiondemandcode' => $row->productiondemandcode,
                        'statusmesin' => $row->statusmesin,
                        'tgl_start' => $tglStart 
                            ? ($filterType === 'week' 
                                ? 'Week ' . $tglStart->format('W') . ' ' . $tglStart->format('m-Y') 
                                : $tglStart->format('Y-m-d'))
                            : null,
                        'estimasi_selesai' => $tglEnd
                            ? ($filterType === 'week'
                                ? 'Week ' . $tglEnd->format('W') . ' ' . $tglEnd->format('m-Y')
                                : $tglEnd->format('Y-m-d'))
                            : null,
                        'tgl_delivery' => $row->tgldelivery,
                        'tgl_mulai' => $row->tglmulai,
                        'subcode01' => $row->subcode01,
                        'subcode02' => $row->subcode02,
                        'subcode03' => $row->subcode03,
                        'subcode04' => $row->subcode04,
                        'qty_sisa' => number_format((float)$row->qty_sisa, 2, '.', ''),
                        'standar_rajut' => number_format((float)$row->standar_rajut, 2, '.', ''),
                        'qty_order' => number_format((float)$row->qty_order, 2, '.', '')
                    ];
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'dataMesin' => $dataMesin,
            'dataNow' => $finalData,
            'dataFor' => $finalDataFor,
        ]);
    }

    public function loadPoAndFor()
    {
        // $dataPo = DB::connection('DB2')->select("
        //     SELECT
        //         TRIM(p.CODE) AS CODE,
        //         a2.VALUESTRING AS NO_MESIN, 
        //         p.ORDERDATE,
        //         a5.VALUEDATE AS TGL_START,
        //     	a4.VALUEDATE AS TGLDELIVERY,
        //         a2.VALUESTRING AS STATUSRMP,
        //         TRIM(p.SUBCODE02) AS SUBCODE02,
        //         TRIM(p.SUBCODE03) AS SUBCODE03,
        //         TRIM(p.SUBCODE04) AS SUBCODE04,
        //         DECIMAL(SUM(p.USERPRIMARYQUANTITY), 18, 2) AS QTY_TOTAL,
        //         SUM(a3.VALUEDECIMAL) AS QTYSALIN
        //     FROM
        //         PRODUCTIONDEMAND p
        //     LEFT JOIN ADSTORAGE a ON a.UNIQUEID = p.ABSUNIQUEID AND a.FIELDNAME = 'MachineNoCode'
        //     LEFT JOIN ADSTORAGE a2 ON a2.UNIQUEID = p.ABSUNIQUEID AND a2.FIELDNAME = 'StatusRMP'
        //     LEFT JOIN ADSTORAGE a3 ON p.ABSUNIQUEID = a3.UNIQUEID AND a3.NAMENAME = 'QtySalin'
        //     LEFT JOIN ADSTORAGE a4 ON a4.UNIQUEID = p.ABSUNIQUEID AND a4.FIELDNAME = 'RMPGreigeReqDateTo'
        //     LEFT JOIN ADSTORAGE a5 ON a5.UNIQUEID = p.ABSUNIQUEID AND a5.FIELDNAME = 'TglRencana'
        //     WHERE
        //         p.ITEMTYPEAFICODE = 'KGF'
        //         AND p.PROGRESSSTATUS != '6'
        //         AND a.VALUESTRING IS NULL
        //         AND a2.VALUESTRING IN ('1', '4')
        //     GROUP BY
        //         p.CODE,
        //         a.VALUESTRING,
        //         a2.VALUESTRING,
        //         p.ORDERDATE,
        //         p.SUBCODE02,
        //         p.SUBCODE03,
        //         p.SUBCODE04,
        //         a5.VALUEDATE,
        //     	a4.VALUEDATE
        // ");

        $dataPo = DB::connection('DB2')->select("WITH STDR AS (
                                                        SELECT DISTINCT
                                                            PRODUCT.SUBCODE02,
                                                            PRODUCT.SUBCODE03,
                                                            PRODUCT.SUBCODE04,
                                                            (ADSTORAGE.VALUEDECIMAL * 24) AS STDRAJUT
                                                        FROM PRODUCT PRODUCT
                                                        LEFT JOIN ADSTORAGE ADSTORAGE 
                                                            ON PRODUCT.ABSUNIQUEID = ADSTORAGE.UNIQUEID
                                                        WHERE 
                                                            ADSTORAGE.NAMENAME = 'ProductionRate'
                                                            AND PRODUCT.ITEMTYPECODE = 'KGF'
                                                            AND PRODUCT.COMPANYCODE = '100'
                                                        )
                                                        SELECT
                                                            TRIM(p.CODE) AS CODE,
                                                            a2.VALUESTRING AS NO_MESIN, 
                                                            p.ORDERDATE,
                                                            a5.VALUEDATE AS TGL_START,
                                                            a4.VALUEDATE AS TGLDELIVERY,
                                                            a2.VALUESTRING AS STATUSRMP,
                                                            TRIM(p.SUBCODE02) AS SUBCODE02,
                                                            TRIM(p.SUBCODE03) AS SUBCODE03,
                                                            TRIM(p.SUBCODE04) AS SUBCODE04,
                                                            ISNULL (a6.VALUEDECIMAL, 0) AS QTYOPOUT,
                                                            DECIMAL(SUM(p.USERPRIMARYQUANTITY), 18, 2) + ISNULL (a6.VALUEDECIMAL, 0) AS QTY_TOTAL,
                                                            SUM(a3.VALUEDECIMAL) AS QTYSALIN,
                                                            s.STDRAJUT,
                                                            CAST(a9.VALUEDECIMAL AS INT) || '''''X' || CAST(a8.VALUEDECIMAL AS INT) || 'G'  AS GAUGE_DIAMETER,
                                                            CAST(a8.VALUEDECIMAL AS INT) || 'G'  AS GAUGE
                                                        FROM
                                                            PRODUCTIONDEMAND p
                                                        LEFT JOIN ADSTORAGE a ON a.UNIQUEID = p.ABSUNIQUEID AND a.FIELDNAME = 'MachineNoCode'
                                                        LEFT JOIN ADSTORAGE a2 ON a2.UNIQUEID = p.ABSUNIQUEID AND a2.FIELDNAME = 'StatusRMP'
                                                        LEFT JOIN ADSTORAGE a3 ON p.ABSUNIQUEID = a3.UNIQUEID AND a3.NAMENAME = 'QtySalin'
                                                        LEFT JOIN ADSTORAGE a4 ON a4.UNIQUEID = p.ABSUNIQUEID AND a4.FIELDNAME = 'RMPGreigeReqDateTo'
                                                        LEFT JOIN ADSTORAGE a5 ON a5.UNIQUEID = p.ABSUNIQUEID AND a5.FIELDNAME = 'TglRencana'
                                                        LEFT JOIN ADSTORAGE a6 ON a6.UNIQUEID = p.ABSUNIQUEID AND a6.FIELDNAME = 'QtyOperOut'
                                                        LEFT JOIN PRODUCT p2 ON p2.ITEMTYPECODE = p.ITEMTYPEAFICODE 
                                                                            AND p2.SUBCODE01 = p.SUBCODE01 
                                                                            AND p2.SUBCODE02 = p.SUBCODE02 
                                                                            AND p2.SUBCODE03 = p.SUBCODE03 
                                                                            AND p2.SUBCODE04 = p.SUBCODE04
                                                        LEFT JOIN ADSTORAGE a8 ON a8.UNIQUEID = p2.ABSUNIQUEID AND a8.FIELDNAME = 'Gauge'
                                                        LEFT JOIN ADSTORAGE a9 ON a9.UNIQUEID = p2.ABSUNIQUEID AND a9.FIELDNAME = 'Diameter'
                                                        LEFT JOIN STDR s ON TRIM(p.SUBCODE02) = s.SUBCODE02
                                                                        AND TRIM(p.SUBCODE03) = s.SUBCODE03
                                                                        AND TRIM(p.SUBCODE04) = s.SUBCODE04
                                                        WHERE
                                                            p.ITEMTYPEAFICODE = 'KGF'
                                                        AND p.PROGRESSSTATUS != '6'
                                                        AND a.VALUESTRING IS NULL
                                                        AND a2.VALUESTRING IN ('1', '4')
                                                        GROUP BY
                                                            p.CODE,
                                                            a.VALUESTRING,
                                                            a2.VALUESTRING,
                                                            p.ORDERDATE,
                                                            p.SUBCODE02,
                                                            p.SUBCODE03,
                                                            p.SUBCODE04,
                                                            a5.VALUEDATE,
                                                            a4.VALUEDATE,
                                                            s.STDRAJUT,
                                                            a6.VALUEDECIMAL,
                                                            a9.VALUEDECIMAL,
                                                            a8.VALUEDECIMAL");

        return response()->json([
            'status' => true,
            'message' => 'Succes',
            'dataPo' => $dataPo
        ]);
    }

    public function loadMesinByPo(Request $request)
    {
        $demand = $request->input('demand');
        $dataMesin = DB::connection('DB2')->select("
        SELECT 
        	ug.CODE AS NO_MESIN,
        	CASE 
        		WHEN LOCATE('X', ug.SHORTDESCRIPTION) > 0 
        			THEN SUBSTR(ug.SHORTDESCRIPTION, LOCATE('X', ug.SHORTDESCRIPTION) + 1)
        		WHEN LOCATE('x', ug.SHORTDESCRIPTION) > 0 
        			THEN SUBSTR(ug.SHORTDESCRIPTION, LOCATE('x', ug.SHORTDESCRIPTION) + 1)
        		ELSE ug.SHORTDESCRIPTION
        	END AS SHORTDESCRIPTION_CLEAN,
        	CASE
        	    WHEN SUBSTRING(TRIM(r.CODE), 1,2) = 'DO' THEN 'Double Knit'
        	    WHEN SUBSTRING(TRIM(r.CODE), 1,2) = 'RI' THEN 'Rib'
        	    WHEN SUBSTRING(TRIM(r.CODE), 1,2) = 'SO' THEN 'Single Knit'
        	    WHEN SUBSTRING(TRIM(r.CODE), 1,2) = 'ST' THEN 'Single Knit'
        	    WHEN SUBSTRING(TRIM(r.CODE), 1,2) = 'TF' THEN 'Fleece'
        	    WHEN SUBSTRING(TRIM(r.CODE), 1,2) = 'TT' THEN 'Fleece'
        	END AS jenis,
        	CAST(a2.VALUEDECIMAL AS INT) AS GUIGE,
        	ug.LONGDESCRIPTION
        FROM DB2ADMIN.USERGENERICGROUP ug
        LEFT JOIN RESOURCES r ON r.CODE = ug.CODE
        LEFT JOIN DB2ADMIN.PRODUCTIONDEMAND p ON p.CODE = ?
        LEFT JOIN DB2ADMIN.PRODUCT p2 
        	ON p2.ITEMTYPECODE = 'KGF'
        	AND p2.SUBCODE01 = p.SUBCODE01 
        	AND p2.SUBCODE02 = p.SUBCODE02 
        	AND p2.SUBCODE03 = p.SUBCODE03 
        	AND p2.SUBCODE04 = p.SUBCODE04
        LEFT JOIN DB2ADMIN.ADSTORAGE a2 
        	ON a2.UNIQUEID = p2.ABSUNIQUEID AND a2.FIELDNAME = 'Gauge'
        LEFT JOIN DB2ADMIN.ADSTORAGE a3 
        	ON a3.UNIQUEID = p2.ABSUNIQUEID AND a3.FIELDNAME = 'Diameter'
        WHERE 
        	ug.USERGENERICGROUPTYPECODE = 'MCK'
        	AND p.PROGRESSSTATUS != '6'
        	AND p.ITEMTYPEAFICODE = 'KGF'
        	AND a2.VALUEDECIMAL != 0
        	AND a3.VALUEDECIMAL != 0
        	AND (
        		CASE 
        			WHEN LOCATE('X', ug.SHORTDESCRIPTION) > 0 
        				THEN SUBSTR(ug.SHORTDESCRIPTION, LOCATE('X', ug.SHORTDESCRIPTION) + 1)
        			WHEN LOCATE('x', ug.SHORTDESCRIPTION) > 0 
        				THEN SUBSTR(ug.SHORTDESCRIPTION, LOCATE('xb', ug.SHORTDESCRIPTION) + 1)
        			ELSE ug.SHORTDESCRIPTION
        		END = CAST(a2.VALUEDECIMAL AS INT) || 'G'
        	)
        ", [$demand]);
        $scheduleData = DB::connection('DB2')->select("
        SELECT
            KDMC,
            PRODUCTIONDEMANDCODE,
            TGL_START,
            TGLMULAI,
            TGLDELIVERY,
            ESTIMASI_SELESAI,
            SUBCODE02,
            SUBCODE03,
            SUBCODE04,
            QTY_SISA,
            STANDAR_RAJUT,
            QTY_ORDER
        FROM ITXTEMP_SCHEDULE_KNT
        WHERE ESTIMASI_SELESAI IS NOT NULL
        ");
        $scheduleGrouped = collect($scheduleData)->groupBy(fn($row) => trim($row->kdmc));
        $finalData = [];

        foreach ($dataMesin as $mesin) {
        $noMesin = trim($mesin->no_mesin);

        // Data dari SP (tersedia demand terakhir)
        $spResults = DB::connection('sqlsrv')->select("EXEC sp_get_mesin_tersedia ?", [$noMesin]);
        $spData = $spResults[0] ?? null;

        $productionDemandCodes = [];
        $bookedDates = [];
        $itemCodeAwal = null;
        $tglStartAwal = null;
        $tglDeliveryAwal = null;

        // Ambil dari ITXTEMP_SCHEDULE_KNT
        $schedules = $scheduleGrouped->get($noMesin) ?? collect();
        foreach ($schedules as $index => $schedule) {
            $start = \Carbon\Carbon::parse($schedule->tgl_start)->format('Y-m-d');
            $end = \Carbon\Carbon::parse($schedule->estimasi_selesai)->format('Y-m-d');

            $dates = collect(range(strtotime($start), strtotime($end), 86400))
                ->map(fn($ts) => date('Y-m-d', $ts))->toArray();

            $bookedDates = array_merge($bookedDates, $dates);
            $productionDemandCodes[] = trim($schedule->productiondemandcode);

            if ($index === 0) {
                $itemCodeAwal = trim($schedule->subcode02 . '-' . $schedule->subcode03 . '-' . $schedule->subcode04);
                $tglStartAwal = $schedule->tgl_start;
                $tglDeliveryAwal = $schedule->estimasi_selesai;
            }
        }

        // Tambahkan dari SP (jika belum ada)
        if ($spData?->productiondemandcode) {
            $spPDC = trim($spData->productiondemandcode);
            if (!in_array($spPDC, $productionDemandCodes)) {
                $spStart = \Carbon\Carbon::parse($spData->start_date)->format('Y-m-d');
                $spEnd = \Carbon\Carbon::parse($spData->end_date)->format('Y-m-d');

                $datesSP = collect(range(strtotime($spStart), strtotime($spEnd), 86400))
                    ->map(fn($ts) => date('Y-m-d', $ts))->toArray();

                $bookedDates = array_merge($bookedDates, $datesSP);
                $productionDemandCodes[] = $spPDC;
            }
        }

        // Final merge and clean
        $bookedDates = array_values(array_unique($bookedDates));
        sort($bookedDates);

        $finalData[] = [
            'no_mesin' => $noMesin,
            'productiondemandcode' => $productionDemandCodes,
            'item_code' => $itemCodeAwal,
            'tgl_start' => $tglStartAwal,
            'tgldelivery' => $tglDeliveryAwal,
            'storage' => $spData->mesin_storage ?? null,
            'nama_mesin' => $spData->nama_mesin ?? null,
            'jenis' => $spData->jenis ?? null,
            'item_code_terakhir' => $spData->item_code ?? null,
            'end_date_terakhir' => $spData->end_date ?? null,
            'booked_dates' => $bookedDates
        ];
        }
        return response()->json([
            'status' => true,
            'message' => 'Success',
            'dataMesin' => $finalData
        ]);
    }

    public function loadSplittForecast(Request $request)
    {
        $itemCode     = $request->input('itemCode');
        $demandMesin    = $request->input('demandMesin');
        $demandQty      = $request->input('demandQty');
        $demandDeliv    = $request->input('demandDeliv');
        
       try {
            $data = DB::connection('sqlsrv')->select('EXEC sp_get_schedule_forcast_splitt ?, ?, ?, ?', [
                $itemCode,
                $demandMesin,
                $demandQty, 
                $demandDeliv
            ]);

            return response()->json([
                'success' => true,
                'data' => $data ?? []
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function loadStatusMesin(Request $request)
    {
        $demand = $request->input('demand');
        if (!is_array($demand) || empty($demand)) {
            return response()->json(['status' => false, 'message' => 'Demand harus berupa array dan tidak kosong']);
        }

        $placeholders = implode(',', array_fill(0, count($demand), '?'));
        $query = "WITH JML251 AS (
                    SELECT
                        DEMANDCODE,
                        COUNT(WEIGHTREALNET) AS JML,
                        SUM(WEIGHTREALNET) AS JQTY
                    FROM ELEMENTSINSPECTION
                    WHERE ELEMENTITEMTYPECODE = 'KGF'
                        AND COMPANYCODE = '100'
                    GROUP BY DEMANDCODE
                ),
                JML25PM AS (
                    SELECT
                        DEMANDCODE,
                        COUNT(WEIGHTREALNET) AS JML
                    FROM ELEMENTSINSPECTION
                    WHERE ELEMENTITEMTYPECODE = 'KGF' 
                        AND QUALITYREASONCODE = 'PM'
                        AND COMPANYCODE = '100'
                    GROUP BY DEMANDCODE
                ),
                STDR AS (
                    SELECT DISTINCT
                        PRODUCT.SUBCODE02,
                        PRODUCT.SUBCODE03,
                        PRODUCT.SUBCODE04,
                        (ADSTORAGE.VALUEDECIMAL * 24) AS STDRAJUT
                    FROM DB2ADMIN.PRODUCT PRODUCT
                    LEFT JOIN DB2ADMIN.ADSTORAGE ADSTORAGE ON PRODUCT.ABSUNIQUEID = ADSTORAGE.UNIQUEID
                    WHERE 
                        ADSTORAGE.NAMENAME = 'ProductionRate'
                        AND PRODUCT.ITEMTYPECODE = 'KGF'
                        AND PRODUCT.COMPANYCODE = '100'
                ),
                KGPAKAI AS (
                        SELECT 
                            PRODUCTIONRESERVATION.PRODUCTIONORDERCODE,
                            SUM(PRODUCTIONRESERVATION.USEDBASEPRIMARYQUANTITY) AS KGPAKAI
                        FROM DB2ADMIN.PRODUCTIONRESERVATION
                        LEFT JOIN DB2ADMIN.FULLITEMKEYDECODER 
                            ON PRODUCTIONRESERVATION.FULLITEMIDENTIFIER = FULLITEMKEYDECODER.IDENTIFIER
                        GROUP BY PRODUCTIONRESERVATION.PRODUCTIONORDERCODE
                    )
                SELECT
                    itx.CODE,
                    itx.PRODUCTIONORDERCODE,
                    itx.PROGRESSSTATUS,
                    itx.SUBCODE01,
                    itx.SUBCODE02,
                    itx.SUBCODE03,
                    itx.SUBCODE04,
                    TRIM(a2.VALUESTRING) AS StatusM,
                    COALESCE(a7.VALUEDATE, CURRENT_DATE) AS tgl_start,
                    a6.VALUEDATE AS tgl_delivery,
                    -- DATE(CURRENT_DATE) + 
                    --     INT(
                    --         (
                    --             (COALESCE(itx.BASEPRIMARYQUANTITY, 0.00) + COALESCE(a5.VALUEDECIMAL, 0.00)) -
                    --             (COALESCE(a3.VALUEDECIMAL, 0.00) + COALESCE(a4.VALUEDECIMAL, 0.00)) -
                    --             COALESCE(j251.JQTY, 0.00)
                    --         ) / NULLIF(ROUND(STDR.STDRAJUT, 0), 0)
                    --     ) DAYS AS ESTIMASI_SELESAI,
                    VARCHAR_FORMAT(
                        DATE(a7.VALUEDATE) + 
                            CEILING(
                                CAST((COALESCE(itx.BASEPRIMARYQUANTITY, 0.00) + COALESCE(a5.VALUEDECIMAL, 0.00)) AS DECIMAL) / NULLIF(ROUND(STDR.STDRAJUT, 0), 0)) DAYS - 1 DAYS, 'YYYY-MM-DD' ) AS ESTIMASI_SELESAI,
                        COALESCE(itx.BASEPRIMARYQUANTITY, 0.00) + COALESCE(a5.VALUEDECIMAL, 0.00) AS QTY_ORDER,
                        (COALESCE(itx.BASEPRIMARYQUANTITY, 0.00) + COALESCE(a5.VALUEDECIMAL, 0.00)) -
                        (COALESCE(a3.VALUEDECIMAL, 0.00) + COALESCE(a4.VALUEDECIMAL, 0.00)) -
                        COALESCE(j251.JQTY, 0.00)
                        AS QTY_SISA,
                    CASE 
                        WHEN (itx.PROGRESSSTATUS = '2' OR a.VALUESTRING = '1') AND COALESCE(pm.JML, 0) > 0 
                            THEN 'Perbaikan Mesin'
                        WHEN (itx.PROGRESSSTATUS = '2' OR a.VALUESTRING = '1') AND TRIM(a2.VALUESTRING) = '2' 
                            THEN 'Antri Mesin'
                        WHEN (itx.PROGRESSSTATUS = '2' OR a.VALUESTRING = '1') AND TRIM(a2.VALUESTRING) = '4' 
                            THEN 'Habis Benang'
                        WHEN (itx.PROGRESSSTATUS = '2' OR a.VALUESTRING = '1') AND TRIM(a2.VALUESTRING) = '5' 
                            THEN 'Tunggu Tes Quality'
                        WHEN (itx.PROGRESSSTATUS = '2' OR a.VALUESTRING = '1') AND TRIM(a2.VALUESTRING) = '3' 
                            THEN 'Hold'
                        WHEN (itx.PROGRESSSTATUS = '2' OR a.VALUESTRING = '1') AND TRIM(a2.VALUESTRING) = '1' AND COALESCE(ROUND(KGPAKAI.KGPAKAI), 0) = 0 
                            THEN 'Tunggu Pasang Benang'
                        WHEN (itx.PROGRESSSTATUS = '2' OR a.VALUESTRING = '1') AND TRIM(a2.VALUESTRING) = '1' AND COALESCE(ROUND(KGPAKAI.KGPAKAI), 0) > 0 AND COALESCE(j251.JML, 0) = 0
                            THEN 'Tunggu Setting'
                        WHEN (itx.PROGRESSSTATUS = '6' AND a.VALUESTRING = '1' AND COALESCE(j251.JML, 0) > 0)
                            THEN 'Sedang Jalan Oper PO'
                        WHEN (itx.PROGRESSSTATUS = '2' OR a.VALUESTRING = '1') AND TRIM(a2.VALUESTRING) IN ('0', '1') AND COALESCE(j251.JML, 0) > 0
                            THEN 'Sedang Jalan'
                        WHEN COALESCE(itx.PRODUCTIONORDERCODE, '') = '' AND itx.CODE <> ''
                            THEN 'Planned'
                        WHEN (itx.PROGRESSSTATUS = '2' OR a.VALUESTRING = '1') AND TRIM(a2.VALUESTRING) = '0' AND COALESCE(ROUND(KGPAKAI.KGPAKAI), 0) = 0
                            THEN 'ProdOrdCreate'
                        ELSE 'Tidak Ada PO'
                    END AS STATUSMESIN,
                    CAST(a9.VALUEDECIMAL AS INT) || '''''X' || CAST(a8.VALUEDECIMAL AS INT) || 'G'  AS GAUGE_DIAMETER,
                    CAST(a8.VALUEDECIMAL AS INT) || 'G'  AS GAUGE,
                    STDR.STDRAJUT AS STDRAJUT
                FROM 
                    ITXVIEWKNTORDER itx
                LEFT JOIN PRODUCTIONDEMAND p ON p.CODE = itx.CODE
                LEFT JOIN ADSTORAGE a ON a.UNIQUEID = p.ABSUNIQUEID AND a.FIELDNAME = 'StatusOper'
                LEFT JOIN ADSTORAGE a2 ON a2.UNIQUEID = p.ABSUNIQUEID AND a2.FIELDNAME = 'StatusMesin'
                LEFT JOIN ADSTORAGE a3 ON a3.UNIQUEID = p.ABSUNIQUEID AND a3.FIELDNAME = 'QtySalin'
                LEFT JOIN ADSTORAGE a4 ON a4.UNIQUEID = p.ABSUNIQUEID AND a4.FIELDNAME = 'QtyOperIn'
                LEFT JOIN ADSTORAGE a5 ON a5.UNIQUEID = p.ABSUNIQUEID AND a5.FIELDNAME = 'QtyOperOut'
                LEFT JOIN ADSTORAGE a6 ON a6.UNIQUEID = p.ABSUNIQUEID AND a6.FIELDNAME = 'RMPGreigeReqDateTo'
                LEFT JOIN ADSTORAGE a7 ON a7.UNIQUEID = p.ABSUNIQUEID AND a7.FIELDNAME = 'TglRencana'
                LEFT JOIN PRODUCT p2 ON p2.ITEMTYPECODE = p.ITEMTYPEAFICODE 
                                    AND p2.SUBCODE01 = p.SUBCODE01 
                                    AND p2.SUBCODE02 = p.SUBCODE02 
                                    AND p2.SUBCODE03 = p.SUBCODE03 
                                    AND p2.SUBCODE04 = p.SUBCODE04
                LEFT JOIN ADSTORAGE a8 ON a8.UNIQUEID = p2.ABSUNIQUEID AND a8.FIELDNAME = 'Gauge'
                LEFT JOIN ADSTORAGE a9 ON a9.UNIQUEID = p2.ABSUNIQUEID AND a9.FIELDNAME = 'Diameter'
                LEFT JOIN ELEMENTSINSPECTION e2 ON e2.DEMANDCODE = p.CODE AND e2.ELEMENTITEMTYPECODE = 'KGF' AND e2.COMPANYCODE = '100'
                LEFT JOIN JML251 j251 ON j251.DEMANDCODE = itx.CODE
                LEFT JOIN JML25PM pm ON pm.DEMANDCODE = itx.CODE
                LEFT JOIN KGPAKAI ON KGPAKAI.PRODUCTIONORDERCODE = itx.PRODUCTIONORDERCODE
                LEFT JOIN STDR ON STDR.SUBCODE02 = itx.SUBCODE02 AND STDR.SUBCODE03 = itx.SUBCODE03 AND STDR.SUBCODE04 = itx.SUBCODE04
                WHERE 
                    p.CODE IN ($placeholders)
                GROUP BY
                    itx.CODE,
                    itx.PROGRESSSTATUS,
                    itx.PRODUCTIONORDERCODE,
                    itx.SUBCODE01,
                    itx.SUBCODE02,
                    itx.SUBCODE03,
                    itx.SUBCODE04,
                    p.ABSUNIQUEID,
                    a.VALUESTRING,
                    a2.VALUESTRING,
                    j251.JML,
                    j251.JQTY,
                    pm.JML,
                    a3.VALUEDECIMAL,
                    a4.VALUEDECIMAL,
                    a5.VALUEDECIMAL,
                    a6.VALUEDATE,
                    a7.VALUEDATE,
                    itx.BASEPRIMARYQUANTITY,
                    KGPAKAI.KGPAKAI,
                    STDR.STDRAJUT,
                    a8.VALUEDECIMAL,
                    a9.VALUEDECIMAL";

        $dataMesin = DB::connection('DB2')->select($query, $demand);

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'dataMesin' => $dataMesin
        ]);
    }

    public function saveScheduleMesin(Request $request)
    {
        $qty        = (float) $request->input('demand.qty');
        $std_Rajut  = (float) $request->input('demand.std_Rajut');
        $demandCode = $request->input('demand.demand');
        $item_code  = $request->input('demand.item_code');
        $tglStart   = $request->input('demand.tgl_start');
        $tglDelivery= $request->input('demand.tgl_delivery');
        $mesinCode  = $request->input('demand.mesin_code');
        $status     = $request->input('demand.status');
        $rec_user     = $request->input('demand.name');
        $user_dept     = $request->input('demand.dept');

        if ($std_Rajut <= 0) {
            return response()->json(['success' => false, 'message' => 'Std rajut tidak boleh 0']);
        }

        $hariProduksi = ceil($qty / $std_Rajut);
        $tglEnd = Carbon::parse($tglStart)->addDays($hariProduksi)->subDay();
        $tglEndFormatted = $tglEnd->toDateString();

        try {
            $demandData = DB::connection('DB2')->selectOne("
                SELECT ABSUNIQUEID FROM PRODUCTIONDEMAND WHERE CODE = ?
            ", [$demandCode]);

            if (!$demandData) {
                return response()->json(['success' => false, 'message' => 'Demand tidak ditemukan']);
            }

            $absId = $demandData->absuniqueid;

            DB::connection('DB2')->beginTransaction();

            $this->insertOrUpdateADStorage($absId, 'TglRencana', 'TglRencana', 0, 3, null, $tglStart);
            $this->insertOrUpdateADStorage($absId, 'MachineNo', 'MachineNoCode', 1, 0, $mesinCode, null);
            $this->insertOrUpdateADStorage($absId, 'RMPGreigeReqDateTo', 'RMPGreigeReqDateTo', 0, 3, null, $tglDelivery);

            $result = DB::connection('sqlsrv')->statement('EXEC sp_insert_schedule ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?', [
                $rec_user,
                $mesinCode,
                $demandCode,
                $item_code,
                $std_Rajut,
                $qty,
                $tglStart,
                $tglEndFormatted,
                $tglDelivery,
                $status,
                $user_dept
            ]);

            if (!$result) {
                DB::connection('DB2')->rollBack();
                return response()->json(['success' => false, 'message' => 'Gagal menyimpan ke SQL Server']);
            }

            DB::connection('DB2')->commit();

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            DB::connection('DB2')->rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function saveSplittForecast(Request $request)
    {
        $itemCode    = $request->input('itemCode');
        $projectCode = $request->input('projectCode');
        $demandMesin = $request->input('demandMesin');
        $demandDeliv = $request->input('demandDeliv');
        $qtyForecast = $request->input('qtyForecast');
        $qtyProject  = $request->input('qtyProject');
        $recUser     = $request->input('name');
        $userDept    = $request->input('dept');

        try {
            DB::connection('sqlsrv')->statement('EXEC sp_insert_schedule_forcast_splitt ?, ?, ?, ?, ?, ?, ?, ?', [
                $recUser,
                $userDept,
                $itemCode,
                $projectCode,
                $qtyForecast, 
                $qtyProject,
                $demandMesin,
                $demandDeliv
            ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    public function deleteScheduleMesin(Request $request)
    {
        try {
            $demandCode = $request->input('demand.demand');
            $rec_user   = $request->input('demand.name');

            // EKSEKUSI SP DI SQL Server
                $result = DB::connection('sqlsrv')->statement('EXEC sp_delete_schedule ?,?', [
                    $rec_user,
                    $demandCode
                ]);

                if (!$result) {
                    DB::connection('DB2')->rollBack();
                    return response()->json(['success' => false, 'message' => 'Gagal menyimpan ke SQL Server']);
                }
            // EKSEKUSI SP DI SQL Server

            // EKSEKUSI DI DB2 
                $resultDB2 = DB::connection('DB2')->selectOne("SELECT ABSUNIQUEID
                                                        FROM PRODUCTIONDEMAND
                                                        WHERE CODE = ?", [$demandCode]);
                $absUniqueId = $resultDB2->absuniqueid;

                // STEP 2 
                DB::connection('DB2')->delete("DELETE FROM ADSTORAGE
                                                WHERE UNIQUEID = ?
                                                AND FIELDNAME = ?
                ", [$absUniqueId, 'MachineNoCode']);

                // // STEP 3
                DB::connection('DB2')->delete("DELETE FROM ADSTORAGE
                                                WHERE UNIQUEID = ?
                                                AND FIELDNAME = ?
                ", [$absUniqueId, 'TglRencana']);

                // // STEP 4
                DB::connection('DB2')->delete("DELETE FROM ADSTORAGE
                                                WHERE UNIQUEID = ?
                                                AND FIELDNAME = ?
                ", [$absUniqueId, 'RMPGreigeReqDateTo']);
            // EKSEKUSI DI DB2 

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil dihapus di SQL Server & DB2'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTrace()
            ], 500);
        }
    }

    private function insertOrUpdateADStorage($uniqueId, $nameName, $fieldName, $keySeq, $dataType, $valueString = null, $valueDate = null)
    {
        $existing = DB::connection('DB2')->selectOne("
            SELECT 1 FROM ADSTORAGE
            WHERE UNIQUEID = ?
              AND NAMENAME = ?
              AND FIELDNAME = ?
              AND KEYSEQUENCE = ?
        ", [$uniqueId, $nameName, $fieldName, $keySeq]);
    
        if ($existing) {
            // UPDATE
            DB::connection('DB2')->update("
                UPDATE ADSTORAGE
                SET VALUESTRING = ?, VALUEDATE = ?
                WHERE UNIQUEID = ?
                  AND NAMENAME = ?
                  AND FIELDNAME = ?
                  AND KEYSEQUENCE = ?
            ", [$valueString, $valueDate, $uniqueId, $nameName, $fieldName, $keySeq]);
        } else {
            // INSERT
            DB::connection('DB2')->insert("
                INSERT INTO ADSTORAGE (
                    UNIQUEID, NAMEENTITYNAME, NAMENAME, FIELDNAME,
                    KEYSEQUENCE, SHARED, DATATYPE,
                    VALUESTRING, VALUEINT, VALUEBOOLEAN,
                    VALUEDATE, VALUEDECIMAL, VALUELONG,
                    VALUETIME, VALUETIMESTAMP, ABSUNIQUEID
                ) VALUES (?, 'ProductionDemand', ?, ?, ?, 0, ?, ?, 0, 0, ?, NULL, 0, NULL, NULL, 0)
            ", [
                $uniqueId,
                $nameName,
                $fieldName,
                $keySeq,
                $dataType,
                $valueString,
                $valueDate
            ]);
        }
    }


    //Forcast
    public function mesin()
    {
        // Ambil data mesin dari SP
        $dataMesin = DB::connection('sqlsrv')->select('EXEC sp_get_mesin');
    
        // Ambil schedule dari tabel schedule_knitting_forecast
        $scheduleData = DB::connection('sqlsrv')->table('schedule_knitting_forecast')
            ->select(
                'mesin_code',
                'item_code',
                'date_startplann',
                'date_endplann'
            )
            ->whereNotNull('date_startplann')
            ->whereNotNull('date_endplann')
            ->get();
            
        // Grouping schedule berdasarkan mesin_code
        $scheduleGrouped = collect($scheduleData)->groupBy(fn($row) => trim($row->mesin_code));
            
        $finalData = [];
            
        foreach ($dataMesin as $mesin) {
            $mesinCode = trim($mesin->mesin_code);
        
            $bookedDates = [];
            $itemCodes = [];
        
            $schedules = $scheduleGrouped->get($mesinCode) ?? collect();
        
            foreach ($schedules as $schedule) {
                // Ambil rentang tanggal
                $start = \Carbon\Carbon::parse($schedule->date_startplann)->format('Y-m-d');
                $end = \Carbon\Carbon::parse($schedule->date_endplann)->format('Y-m-d');
            
                $dates = collect(range(strtotime($start), strtotime($end), 86400))
                    ->map(fn($ts) => date('Y-m-d', $ts))
                    ->toArray();
            
                $bookedDates = array_merge($bookedDates, $dates);
            
                // Kumpulkan item_code
                $itemCodes[] = trim($schedule->item_code);
            }
        
            // Bersihkan duplikat dan sort
            $bookedDates = array_values(array_unique($bookedDates));
            sort($bookedDates);
        
            $itemCodes = array_values(array_unique($itemCodes));
        
            $finalData[] = [
                'mesin_code' => $mesinCode,
                'mesin_storage' => $mesin->mesin_storage,
                'nama_mesin' => $mesin->nama_mesin,
                'jenis' => $mesin->jenis,
                'item_code' => $itemCodes,
                'booked_dates' => $bookedDates,
            ];
        }
    
        return response()->json([
            'status' => true,
            'message' => 'Success',
            'dataMesin' => $finalData
        ]);
    }

    public function mesinByItemCode(Request $request)
    {
        $itemCode = $request->input('itemCode');
        // Ambil data mesin dari SP
        $schedules = DB::connection('sqlsrv')->select('EXEC sp_get_schedule_by_item_code ?', [$itemCode]);

        return response()->json([
            'item_code' => $itemCode,
            'schedules' => $schedules,
        ]);
    }

    public function loadForcast()
    {
        // $dataForcast = DB::connection('sqlsrv')->select('EXEC sp_get_forcast');
        // collect($dataForcast)->map(function ($row) {
        //     $uploadDate = \Carbon\Carbon::parse($row->upload_date);

        //     DB::connection('sqlsrv')->statement('EXEC sp_insert_data_forecast ?, ?, ?, ?, ?', [
        //         $row->item_code,
        //         number_format((float) $row->total_qty_kg, 8, '.', ''),
        //         number_format((float) $row->stdrajut, 8, '.', ''),
        //         $row->delivery_date,
        //         $uploadDate->toDateString()
        //     ]);
        // });
        $summary = DB::connection('sqlsrv')->select('EXEC sp_get_list_forcast');

        return response()->json([
            'status' => true,
            'message' => 'Succes',
            'dataForcast' => $summary
        ]);
    }

    public function loadDetailForcast()
    {
        $dataForcast = DB::connection('sqlsrv')->select('EXEC sp_get_detail_forcast');

        return response()->json([
            'status' => true,
            'message' => 'Succes',
            'dataForcast' => $dataForcast
        ]);
    }

    public function getStockDetail(Request $request){
        $itemCode = $request->input('itemCode');
        $stock = $request->input('stock');

        list($Code1, $Code2, $Code3) = explode('-', $itemCode);

        $dataHeading = DB::connection('DB2')->select("
            SELECT
                COALESCE(p.ORIGDLVSALORDLINESALORDERCODE, '-') AS ORIGDLVSALORDLINESALORDERCODE,
                COALESCE(s.STATISTICALGROUPCODE, '-') AS STATISTICALGROUPCODE,
                COALESCE(b.LOGICALWAREHOUSECODE, '-') AS LOGICALWAREHOUSECODE,
                COALESCE(SUM(BASEPRIMARYQUANTITYUNIT), 0) AS TotalStock
            FROM
              BALANCE b
            LEFT JOIN PRODUCTIONDEMAND p ON p.CODE = b.LOTCODE
            LEFT JOIN SALESORDER s ON s.CODE = p.ORIGDLVSALORDLINESALORDERCODE
            WHERE
                b.DECOSUBCODE02 = ?
            	AND b.DECOSUBCODE03 = ?
                AND b.DECOSUBCODE04 = ?
              AND b.LOGICALWAREHOUSECODE IN ('M021', 'M502')
            GROUP BY
              p.ORIGDLVSALORDLINESALORDERCODE,
              b.LOGICALWAREHOUSECODE,
              s.STATISTICALGROUPCODE",
        [$Code1, $Code2, $Code3]);

        $dataDetail = DB::connection('DB2')->select("
            SELECT
                COALESCE(p.ORIGDLVSALORDLINESALORDERCODE, '-') AS ORIGDLVSALORDLINESALORDERCODE,
                COALESCE(b.LOTCODE, '-') AS LOTCODE,
                COALESCE(s.STATISTICALGROUPCODE, '-') AS STATISTICALGROUPCODE,
                COALESCE(p.EXTERNALREFERENCE, '-') AS EXTERNALREFERENCE,
                COALESCE(b.LOGICALWAREHOUSECODE, '-') AS LOGICALWAREHOUSECODE,
                ROUND(COALESCE(SUM(BASEPRIMARYQUANTITYUNIT), 0), 2) AS Stock
            FROM
              BALANCE b
            LEFT JOIN PRODUCTIONDEMAND p ON p.CODE = b.LOTCODE
            LEFT JOIN SALESORDER s ON s.CODE = p.ORIGDLVSALORDLINESALORDERCODE
            WHERE
                b.DECOSUBCODE02 = ?
            	AND b.DECOSUBCODE03 = ?
                AND b.DECOSUBCODE04 = ?
              AND b.LOGICALWAREHOUSECODE IN ('M021', 'M502')
            GROUP BY
              p.ORIGDLVSALORDLINESALORDERCODE,
              b.LOTCODE,
              s.STATISTICALGROUPCODE,
              p.EXTERNALREFERENCE,
              b.LOGICALWAREHOUSECODE",
        [$Code1, $Code2, $Code3]);

        $dataStock = DB::connection('DB2')->select("
            SELECT
                COALESCE(b.LOTCODE, '-') AS LOTCODE,
                COALESCE(p.ORIGDLVSALORDLINESALORDERCODE, '-') AS ORIGDLVSALORDLINESALORDERCODE,
                COALESCE(s.STATISTICALGROUPCODE, '-') AS STATISTICALGROUPCODE,
                COALESCE(p.EXTERNALREFERENCE, '-') AS EXTERNALREFERENCE,
                COALESCE(b.LOGICALWAREHOUSECODE, '-') AS LOGICALWAREHOUSECODE,
                COALESCE(SUM(BASEPRIMARYQUANTITYUNIT), 0) AS Stock
            FROM
            	BALANCE b
            LEFT JOIN PRODUCTIONDEMAND p ON p.CODE = b.LOTCODE 
            LEFT JOIN SALESORDER s ON s.CODE = p.ORIGDLVSALORDLINESALORDERCODE 
            WHERE
            	b.DECOSUBCODE02 = ?
            	AND b.DECOSUBCODE03 = ?
                AND b.DECOSUBCODE04 = ?
            	AND b.LOGICALWAREHOUSECODE IN ('M021', 'M502')
            GROUP BY
            	b.LOTCODE,
            	p.ORIGDLVSALORDLINESALORDERCODE,
            	s.STATISTICALGROUPCODE,
            	p.EXTERNALREFERENCE,
            	b.LOGICALWAREHOUSECODE",
        [$Code1, $Code2, $Code3]);

        return response()->json([
            'dataHeading' => $dataHeading,
            'dataDetail' => $dataDetail,
            'dataStock' => $dataStock
        ]);
    }

    public function getDetailData(Request $request, $itemCode)
    {
        $parts = explode('-', $itemCode);
        if (count($parts) < 3) {
            return response()->json([
                'message' => 'Format item code tidak valid'
            ], 400);
        }

        list($Code1, $Code2, $Code3) = $parts;

        $filterType = $request->get('data_filter', 'day');

        $schedules = DB::connection('sqlsrv')->select(
            'EXEC sp_get_schedule_by_item_code ?', 
            [$itemCode]
        );

        $dataDB2 = DB::connection('DB2')->select("
            SELECT
                a2.VALUEDATE AS RMP_REQ_TO,
                SUM(p.USERPRIMARYQUANTITY) AS QTY_TOTAL
            FROM
                PRODUCTIONDEMAND p
            LEFT JOIN ADSTORAGE a ON a.UNIQUEID = p.ABSUNIQUEID AND a.FIELDNAME = 'RMPReqDate'
            LEFT JOIN ADSTORAGE a2 ON a2.UNIQUEID = p.ABSUNIQUEID AND a2.FIELDNAME = 'RMPGreigeReqDateTo'
            LEFT JOIN ADSTORAGE a3 ON a3.UNIQUEID = p.ABSUNIQUEID AND a3.FIELDNAME = 'OriginalPDCode'
            WHERE
                p.SUBCODE02 = ?
                AND p.SUBCODE03 = ?
                AND p.SUBCODE04 = ?
                AND p.ITEMTYPEAFICODE = 'KGF'
                AND a2.VALUEDATE > CAST(CURRENT DATE AS DATE)
                AND a3.VALUESTRING IS NULL
            GROUP BY
                a2.VALUEDATE
        ", [$Code1, $Code2, $Code3]);

        $dataStock = DB::connection('DB2')->select("
            SELECT
                SUM(BASEPRIMARYQUANTITYUNIT) as Stock
            FROM
                BALANCE b
            WHERE
                DECOSUBCODE02 = ?
                AND b.DECOSUBCODE03 = ?
                AND b.DECOSUBCODE04 = ?
                AND b.LOGICALWAREHOUSECODE IN ('M021', 'M502')
        ", [$Code1, $Code2, $Code3]);

        $forecast = DB::connection('sqlsrv')->select(
            'EXEC sp_get_forcast_by_subcode ?', 
            [$itemCode]
        );

        $stockPlann = DB::connection('sqlsrv')->select(
            'EXEC sp_get_qtyplann_by_item_code ?', 
            [$itemCode]
        );

        return response()->json([
            'item_code'   => $itemCode,
            'filter_type' => $filterType, // 🔹 ikut dikembalikan biar jelas
            'schedules'   => $schedules,
            'db2_data'    => $dataDB2,
            'stock_data'  => $dataStock,
            'forecast'    => $forecast,
            'stockPlann'  => $stockPlann,
        ]);
    }

    public function getSearchData($itemCode){
        list($Code1, $Code2, $Code3) = explode('-', $itemCode);

        $dataDB2 = DB::connection('DB2')->select("
            SELECT
                a2.VALUEDATE AS RMP_REQ_TO,
                SUM(p.USERPRIMARYQUANTITY) AS QTY_TOTAL
            FROM
                PRODUCTIONDEMAND p
            LEFT JOIN ADSTORAGE a ON a.UNIQUEID = p.ABSUNIQUEID AND a.FIELDNAME = 'RMPReqDate'
            LEFT JOIN ADSTORAGE a2 ON a2.UNIQUEID = p.ABSUNIQUEID AND a2.FIELDNAME = 'RMPGreigeReqDateTo'
            LEFT JOIN ADSTORAGE a3 ON a3.UNIQUEID = p.ABSUNIQUEID AND a3.FIELDNAME = 'OriginalPDCode'
            WHERE
                p.SUBCODE02 = ? AND
                p.SUBCODE03 = ? AND
                p.SUBCODE04 = ? AND
                p.ITEMTYPEAFICODE = 'KGF' AND
                a2.VALUEDATE > CAST(CURRENT DATE AS DATE) AND
                a3.VALUESTRING IS NULL
            GROUP BY a2.VALUEDATE
        ", [$Code1, $Code2, $Code3]);

        $dataStock = DB::connection('DB2')->select("
            SELECT
                SUM(BASEPRIMARYQUANTITYUNIT) as Stock
            FROM BALANCE b
            WHERE
                DECOSUBCODE02 = ? AND
                b.DECOSUBCODE03 = ? AND
                b.DECOSUBCODE04 = ? AND
                b.LOGICALWAREHOUSECODE IN ('M021', 'M502')",
        [$Code1, $Code2, $Code3]);

        $forecast = DB::connection('mysql')->select("
            SELECT
                t.item_subcode2,
                t.item_subcode3,
                t.item_subcode4,
                t.buy_month,
                SUM(t.qty_kg) AS total_qty_kg
            FROM tbl_upload_order t
            WHERE 
                t.item_subcode2 = ? AND
                t.item_subcode3 = ? AND
                t.item_subcode4 = ?
            GROUP BY
                t.item_subcode2,
                t.item_subcode3,
                t.item_subcode4,
                t.buy_month
        ", [$Code1, $Code2, $Code3]);

        $stockPlann = DB::connection('sqlsrv')->select(
            'EXEC sp_get_qtyplann_by_item_code ?',
            [$itemCode]
        );

        return response()->json([
            'db2_data' => $dataDB2,
            'stock_data' => $dataStock,
            'forecast' => $forecast,
            'stockPlann' => $stockPlann,
        ]);
    }

    // public function saveScheduleForcast(Request $request)
    // {
    //     $qty        = (float) $request->input('demand.qty');
    //     $std_Rajut  = (float) $request->input('demand.std_Rajut');
    //     $demandCode = $request->input('demand.demand');
    //     $item_code  = $request->input('demand.item_code');
    //     $tglStart   = $request->input('demand.tgl_start');
    //     $tglDelivery= $request->input('demand.tgl_delivery');
    //     $mesinCode  = $request->input('demand.mesin_code');
    //     $status     = $request->input('demand.status');
    //     $rec_user     = $request->input('demand.name');
    //     $user_dept     = $request->input('demand.dept');

    //     if ($std_Rajut <= 0) {
    //         return response()->json(['success' => false, 'message' => 'Std rajut tidak boleh 0']);
    //     }

    //     $hariProduksi = ceil($qty / $std_Rajut);
    //     $tglEnd = Carbon::parse($tglStart)->addDays($hariProduksi)->subDay();
    //     $tglEndFormatted = $tglEnd->toDateString();

    //     try {
    //         $result = DB::connection('sqlsrv')->statement('EXEC sp_insert_schedule_forcast ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?', [
    //             $rec_user,
    //             $mesinCode,
    //             $demandCode,
    //             $item_code,
    //             $std_Rajut,
    //             $qty,
    //             $tglStart,
    //             $tglEndFormatted,
    //             $tglDelivery,
    //             $status,
    //             $user_dept
    //         ]);
    //         if($result){
    //             return response()->json(['success' => true]);
    //         }
    //     } catch (\Exception $e) {
    //         return response()->json(['success' => false, 'message' => $e->getMessage()]);
    //     }
    // }

    public function saveScheduleForcast(Request $request)
    {
        $qty         = (float) $request->input('demand.qty');
        $stdRajut    = (float) $request->input('demand.std_Rajut');
        $demandCode  = $request->input('demand.demand');
        $itemCode    = $request->input('demand.item_code');
        $tglStart    = $request->input('demand.tgl_start');
        $tglDelivery = $request->input('demand.tgl_delivery');
        $status      = $request->input('demand.status');
        $recUser     = $request->input('demand.name');
        $userDept    = $request->input('demand.dept');

        $mesinCodes = $request->input('demand.mesin_code');

        if (!is_array($mesinCodes) || count($mesinCodes) === 0) {
            return response()->json(['success' => false, 'message' => 'Mesin belum dipilih']);
        }

        if ($stdRajut <= 0) {
            return response()->json(['success' => false, 'message' => 'Std rajut tidak boleh 0']);
        }

        $jumlahMesin = count($mesinCodes);
        $totalPerHari = $stdRajut * $jumlahMesin;

        if ($totalPerHari <= 0) {
            return response()->json(['success' => false, 'message' => 'Produksi per hari tidak valid']);
        }

        $hariProduksi = ceil($qty / $totalPerHari);

        // Hitung tglEnd TANPA Minggu
        $tglStartObj = Carbon::parse($tglStart);
        $tglEnd = clone $tglStartObj;
        $workDays = 0;

        while ($workDays < $hariProduksi) {
            if ($tglEnd->dayOfWeek !== Carbon::SUNDAY) {
                $workDays++;
            }
            if ($workDays < $hariProduksi) {
                $tglEnd->addDay();
            }
        }

        $tglEndFormatted = $tglEnd->toDateString();

        try {
            foreach ($mesinCodes as $mesinCode) {
                DB::connection('sqlsrv')->statement('EXEC sp_insert_schedule_forcast ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?', [
                    $recUser,
                    $mesinCode,
                    $demandCode,
                    $itemCode,
                    $stdRajut,
                    $qty,
                    $tglStart,
                    $tglEndFormatted,
                    $tglDelivery,
                    $status,
                    $userDept
                ]);
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

}
