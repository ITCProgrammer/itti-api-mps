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
                SUBCODE04
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
        // Ambil tanggal mulai: prioritas ke tglmulai
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
        $demand = $request['demand'];
        $dataMesin = DB::connection('DB2')->select("
            SELECT
            	USERGENERICGROUP.CODE AS NO_MESIN,
            	DMN.PRODUCTIONDEMANDCODE,
                TRIM(DMN.SUBCODE02) || '-' || TRIM(DMN.SUBCODE03) || '-' || TRIM(DMN.SUBCODE04) AS ITEM_CODE,
            	DMN.TGL_START,
            	DMN.TGLDELIVERY
            FROM
            	DB2ADMIN.USERGENERICGROUP
            LEFT OUTER JOIN (
            	SELECT
            		ADSTORAGE.VALUESTRING,
            		AD1.VALUEDATE AS TGL_START,
            		AD7.VALUEDATE AS TGLDELIVERY,
            		ITXVIEWKNTORDER.*
            	FROM
            		ITXVIEWKNTORDER
            	LEFT OUTER JOIN DB2ADMIN.PRODUCTIONDEMAND 
            		ON PRODUCTIONDEMAND.CODE = ITXVIEWKNTORDER.PRODUCTIONDEMANDCODE
            	LEFT OUTER JOIN DB2ADMIN.ADSTORAGE 
            		ON ADSTORAGE.UNIQUEID = PRODUCTIONDEMAND.ABSUNIQUEID 
            		AND ADSTORAGE.NAMENAME = 'MachineNo'
            	LEFT OUTER JOIN DB2ADMIN.ADSTORAGE AD1 
            		ON AD1.UNIQUEID = PRODUCTIONDEMAND.ABSUNIQUEID 
            		AND AD1.FIELDNAME = 'TglRencana'
            	LEFT OUTER JOIN DB2ADMIN.ADSTORAGE AD2 
            		ON AD2.UNIQUEID = PRODUCTIONDEMAND.ABSUNIQUEID 
            		AND AD2.FIELDNAME = 'RMPReqDate'
            	LEFT OUTER JOIN DB2ADMIN.ADSTORAGE AD3 
            		ON AD3.UNIQUEID = PRODUCTIONDEMAND.ABSUNIQUEID 
            		AND AD3.FIELDNAME = 'QtySalin'
            	LEFT OUTER JOIN DB2ADMIN.ADSTORAGE AD4 
            		ON AD4.UNIQUEID = PRODUCTIONDEMAND.ABSUNIQUEID 
            		AND AD4.FIELDNAME = 'QtyOperIn'
            	LEFT OUTER JOIN DB2ADMIN.ADSTORAGE AD5 
            		ON AD5.UNIQUEID = PRODUCTIONDEMAND.ABSUNIQUEID 
            		AND AD5.FIELDNAME = 'QtyOperOut'
            	LEFT OUTER JOIN DB2ADMIN.ADSTORAGE AD6 
            		ON AD6.UNIQUEID = PRODUCTIONDEMAND.ABSUNIQUEID 
            		AND AD6.FIELDNAME = 'StatusOper'
            	LEFT OUTER JOIN DB2ADMIN.ADSTORAGE AD7 
            		ON AD7.UNIQUEID = PRODUCTIONDEMAND.ABSUNIQUEID 
            		AND AD7.FIELDNAME = 'RMPGreigeReqDateTo'
            	LEFT OUTER JOIN DB2ADMIN.ADSTORAGE AD8 
            		ON AD8.UNIQUEID = PRODUCTIONDEMAND.ABSUNIQUEID 
            		AND AD8.FIELDNAME = 'StatusMesin'
            	WHERE
            		PRODUCTIONDEMAND.ITEMTYPEAFICODE = 'KGF'
            		AND (PRODUCTIONDEMAND.PROGRESSSTATUS IN ('0','1','2') OR AD6.VALUESTRING = '1')
            ) DMN 
            	ON DMN.VALUESTRING = USERGENERICGROUP.CODE 	
            WHERE
            	USERGENERICGROUP.USERGENERICGROUPTYPECODE = 'MCK'
            	AND USERGENERICGROUP.USERGENGROUPTYPECOMPANYCODE = '100'
            	AND USERGENERICGROUP.OWNINGCOMPANYCODE = '100'
            	AND USERGENERICGROUP.SHORTDESCRIPTION = (
            		SELECT
            			COALESCE(CAST(a3.VALUEDECIMAL AS INT), 0) || '''''X' || COALESCE(CAST(a2.VALUEDECIMAL AS INT), 0) || 'G'
            		FROM
            			PRODUCTIONDEMAND p
            		LEFT JOIN PRODUCT p2 
            			ON p2.ITEMTYPECODE = 'KGF'
            			AND p2.SUBCODE01 = p.SUBCODE01 
            			AND p2.SUBCODE02 = p.SUBCODE02 
            			AND p2.SUBCODE03 = p.SUBCODE03 
            			AND p2.SUBCODE04 = p.SUBCODE04
            		LEFT JOIN ADSTORAGE a2 
            			ON a2.UNIQUEID = p2.ABSUNIQUEID 
            			AND a2.FIELDNAME = 'Gauge'
            		LEFT JOIN ADSTORAGE a3 
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
        ", [$demand]);

        return response()->json([
            'status' => true,
            'message' => 'Succes',
            'dataMesin' => $dataMesin
        ]);
    }

    public function saveScheduleMesin(Request $request)
    {
        $demandCode   = $request->demand;
        $tglStart     = $request->tgl_start;
        $tglDelivery  = $request->tgl_delivery;
        $mesinCode    = $request->mesin_code;
        $status       = $request->status;

        try {
            $demandData = DB::connection('DB2')->selectOne("
                SELECT ABSUNIQUEID FROM PRODUCTIONDEMAND WHERE CODE = ?
            ", [$demandCode]);

            if (!$demandData) {
                return response()->json(['success' => false, 'message' => 'Demand tidak ditemukan']);
            }

            $absId = $demandData->absuniqueid;

            DB::connection('DB2')->beginTransaction();

            // Helper function untuk insert/update
            $this->insertOrUpdateADStorage($absId, 'TglRencana', 'TglRencana', 0, 3, null, $tglStart);
            $this->insertOrUpdateADStorage($absId, 'MachineNo', 'MachineNoCode', 1, 0, $mesinCode, null);
            $this->insertOrUpdateADStorage($absId, 'RMPGreigeReqDateTo', 'RMPGreigeReqDateTo', 0, 3, null, $tglDelivery);

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
