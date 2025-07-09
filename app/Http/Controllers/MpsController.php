<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use \DateTime;

class MpsController extends Controller
{
    public function mesin()
    {
        $dataMesin = DB::connection('sqlsrv')->select('EXEC sp_get_mesin');
        return response()->json([
            'status' => true,
            'message' => 'Succes',
            'dataMesin' => $dataMesin,
        ]);
    }

    // public function index()
    // {
    //     $dataMesin = DB::connection('sqlsrv')->select('EXEC sp_get_mesin');

    //     $dataDB2 = DB::connection('DB2')->select("
    //         SELECT
    //             KDMC,
    //             PRODUCTIONDEMANDCODE,
    //             STATUSMESIN,
    //             TGL_START,
    //             TGLDELIVERY,
    //             ESTIMASI_SELESAI,
    //             SUBCODE01,
    //             SUBCODE02,
    //             SUBCODE03,
    //             SUBCODE04
    //         FROM ITXTEMP_SCHEDULE_KNT
    //     ");

    //     return response()->json([
    //         'status' => true,
    //         'message' => 'Succes',
    //         'dataMesin' => $dataMesin,
    //         'dataNow' => $dataDB2,
    //         'test' => 'test'
    //     ]);
    // }

    public function index()
    {
        $dataMesin = DB::connection('sqlsrv')->select('EXEC sp_get_mesin');

        $dataDB2 = DB::connection('DB2')->select("
            SELECT
                KDMC,
                PRODUCTIONDEMANDCODE,
                STATUSMESIN,
                TGL_START,
                TGLMULAI,
                TGLDELIVERY,
                ESTIMASI_SELESAI,
                SUBCODE01,
                SUBCODE02,
                SUBCODE03,
                SUBCODE04,
                QTY_SISA,
                STANDAR_RAJUT,
                QTY_ORDER
            FROM ITXTEMP_SCHEDULE_KNT
            WHERE ESTIMASI_SELESAI IS NOT NULL
        ");

        // Group berdasarkan mesin
        $grouped = [];
        foreach ($dataDB2 as $row) {
            $kdmc = $row->kdmc;
            if (!isset($grouped[$kdmc])) {
                $grouped[$kdmc] = [];
            }

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
            
                $finalData[] = (object)[
                    'kdmc' => $row->kdmc,
                    'productiondemandcode' => $row->productiondemandcode,
                    'statusmesin' => $row->statusmesin,
                    'tgl_start' => $start->format('Y-m-d'),
                    'estimasi_selesai' => $est->format('Y-m-d'),
                    'tgl_delivery' => $row->tgldelivery,
                    'tgl_mulai' => $row->tglmulai,
                    'subcode01' => $row->subcode01,
                    'subcode02' => $row->subcode02,
                    'subcode03' => $row->subcode03,
                    'subcode04' => $row->subcode04,
                    'qty_sisa' => number_format((float)$row->qty_sisa, 2, '.', ''),
                    // 'qty_sisa' => $row->qty_sisa,
                    // 'standar_rajut' => $row->standar_rajut,
                    'standar_rajut' => number_format((float)$row->standar_rajut, 2, '.', ''),
                    'qty_order' => number_format((float)$row->qty_order, 2, '.', ''),
                ];
            }
        }

        foreach ($dataMesin as $mesin) {
            $spDetail = DB::connection('sqlsrv')->select('EXEC sp_get_mesin_detail ?', [$mesin->mesin_code]);
            foreach ($spDetail as $row) {
                $finalData[] = (object)[
                    'kdmc' => $row->kdmc,
                    'productiondemandcode' => $row->productiondemandcode,
                    'statusmesin' => $row->statusmesin,
                    'tgl_start' => $row->tgl_start ? date('Y-m-d', strtotime($row->tgl_start)) : null,
                    'estimasi_selesai' => $row->tgldelivery ? date('Y-m-d', strtotime($row->tgldelivery)) : null,
                    'tgl_delivery' => $row->tgldelivery,
                    'tgl_mulai' => $row->tgl_start,
                    'subcode01' => $row->subcode01,
                    'subcode02' => $row->subcode02,
                    'subcode03' => $row->subcode03,
                    'subcode04' => $row->subcode04,
                    'qty_sisa' => number_format((float)$row->qty_sisa, 2, '.', ''),
                    // 'qty_sisa' => $row->qty_sisa,
                    // 'standar_rajut' => $row->standar_rajut,
                    'standar_rajut' => number_format((float)$row->standar_rajut, 2, '.', ''),
                    'qty_order' => number_format((float)$row->qty_order, 2, '.', ''),
                ];
            }
        }
        return response()->json([
            'status' => true,
            'message' => 'Success',
            'dataMesin' => $dataMesin,
            'dataNow' => $finalData,
        ]);
    }


    public function loadPoAndFor()
    {
        $dataPo = DB::connection('DB2')->select("
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
                DECIMAL(SUM(p.USERPRIMARYQUANTITY), 18, 2) AS QTY_TOTAL,
                SUM(a3.VALUEDECIMAL) AS QTYSALIN
            FROM
                PRODUCTIONDEMAND p
            LEFT JOIN ADSTORAGE a ON a.UNIQUEID = p.ABSUNIQUEID AND a.FIELDNAME = 'MachineNoCode'
            LEFT JOIN ADSTORAGE a2 ON a2.UNIQUEID = p.ABSUNIQUEID AND a2.FIELDNAME = 'StatusRMP'
            LEFT JOIN ADSTORAGE a3 ON p.ABSUNIQUEID = a3.UNIQUEID AND a3.NAMENAME = 'QtySalin'
            LEFT JOIN ADSTORAGE a4 ON a4.UNIQUEID = p.ABSUNIQUEID AND a4.FIELDNAME = 'RMPGreigeReqDateTo'
            LEFT JOIN ADSTORAGE a5 ON a5.UNIQUEID = p.ABSUNIQUEID AND a5.FIELDNAME = 'TglRencana'
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
            	a4.VALUEDATE
        ");

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
            SELECT DISTINCT
                USERGENERICGROUP.CODE AS NO_MESIN
            FROM
                DB2ADMIN.USERGENERICGROUP
            WHERE
                USERGENERICGROUP.USERGENERICGROUPTYPECODE = 'MCK'
                AND USERGENERICGROUP.USERGENGROUPTYPECOMPANYCODE = '100'
                AND USERGENERICGROUP.OWNINGCOMPANYCODE = '100'
                AND USERGENERICGROUP.SHORTDESCRIPTION = (
                    SELECT
                        COALESCE(CAST(a3.VALUEDECIMAL AS INT), 0) || '''''X' || COALESCE(CAST(a2.VALUEDECIMAL AS INT), 0) || 'G'
                    FROM
                        DB2ADMIN.PRODUCTIONDEMAND p
                    LEFT JOIN DB2ADMIN.PRODUCT p2 
                        ON p2.ITEMTYPECODE = 'KGF'
                        AND p2.SUBCODE01 = p.SUBCODE01 
                        AND p2.SUBCODE02 = p.SUBCODE02 
                        AND p2.SUBCODE03 = p.SUBCODE03 
                        AND p2.SUBCODE04 = p.SUBCODE04
                    LEFT JOIN DB2ADMIN.ADSTORAGE a2 
                        ON a2.UNIQUEID = p2.ABSUNIQUEID 
                        AND a2.FIELDNAME = 'Gauge'
                    LEFT JOIN DB2ADMIN.ADSTORAGE a3 
                        ON a3.UNIQUEID = p2.ABSUNIQUEID 
                        AND a3.FIELDNAME = 'Diameter'
                    WHERE 
                        p.CODE = ?
                        AND NOT p.PROGRESSSTATUS = '6'
                        AND p.ITEMTYPEAFICODE = 'KGF'
                        AND a2.VALUEDECIMAL != 0
                        AND a3.VALUEDECIMAL != 0
                    FETCH FIRST 1 ROW ONLY
                )
            ORDER BY USERGENERICGROUP.CODE ASC;
        ", [$demand]);

        $finalData = [];

        foreach ($dataMesin as $mesin) {
            $noMesin = trim($mesin->no_mesin);

            $spResult = DB::connection('sqlsrv')->select("EXEC sp_get_avail_mechine ?", [$noMesin]);

            $spData = $spResult[0] ?? null;

            $finalData[] = [
                'no_mesin' => $noMesin,
                'productiondemandcode' => trim($spData->productiondemandcode),
                'item_code' => $spData->item_code,
                'tgl_start' => $spData->start_date,
                'tgldelivery' => $spData->end_date,
                'storage' => $spData->mesin_storage ?? null,
                'nama_mesin' => $spData->nama_mesin ?? null,
                'jenis' => $spData->jenis ?? null,
                'item_code_terakhir' => $spData->item_code ?? null,
                'end_date_terakhir' => $spData->end_date ?? null,
            ];
        }

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'dataMesin' => $finalData
        ]);
    }

    public function saveScheduleMesin(Request $request)
    {
        $demandCode     = $request->demand;
        $item_code      = $request->item_code;
        $qty            = $request->qty;
        $tglStart       = $request->tgl_start;
        $tglDelivery    = $request->tgl_delivery;
        $mesinCode      = $request->mesin_code;
        $status         = $request->status;

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

            $result = DB::connection('sqlsrv')->statement('EXEC sp_insert_schedule ?, ?, ?, ?, ?, ?, ?', [
                $demandCode,
                $item_code,
                $qty,
                $mesinCode,
                $tglStart,
                $tglDelivery,
                $status,
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

}