<?php
namespace App\Http\Controllers;

use App\Imports\SchedulePlanImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class SchedulePlanController extends Controller
{
    public function index(Request $request)
    {
        $start = $request->start_date ?? now()->toDateString();
        $end   = $request->end_date ?? now()->toDateString();
        $dept  = $request->dept;

        $operationGroups = [
            'BRS' => ['SHR3', 'SHR4', 'RSE5', 'RSE1', 'RSE2', 'RSE3', 'RSE4', 'COM1', 'COM2', 'SHR1', 'SHR2', 'SUE1', 'SUE2', 'SUE3', 'SUE4', 'POL1', 'TDR1', 'AIR1', 'NCP8', 'BBS5', 'WAIT38', 'SHR5', 'WET1', 'WET2', 'WET3', 'WET4', 'INS9'],
            'CQA' => ['CCK2', 'CCK1', 'CCK3', 'CCK4', 'CCK5', 'CCK6', 'CCK7', 'QCF3', 'WAIT45', 'CCK8', 'CCK9', 'CCK10', 'NCP24', 'WAIT67'],
            'CSR' => ['QCF5', 'WAIT68'],
            'DYE' => ['DYE2', 'DYE3', 'DYE4', 'DYE5', 'NCP1', 'TBS1', 'DYE6', 'SCO1', 'DYE1', 'SOA1', 'FIX1', 'HOT1', 'RDC1', 'RTW1', 'FEW1', 'HEW1', 'NEU1', 'LVL1', 'STR1', 'MWS1', 'RLX1', 'CBL1', 'BKR', 'T.BASAH', 'TEST1', 'MATD', 'SOF1', 'BRD1', 'BKR1', 'WAIT33', 'BBS3', 'WAIT28', 'WAIT29'],
            'FIN' => ['OVN2', 'OPW1', 'OVN1', 'FIN1', 'FIN2', 'FNJ1', 'PRE1', 'CPT1', 'PAD1', 'LIP1', 'STM1', 'OVD1', 'FNU1', 'BLD1', 'BLP1', 'NCP5', 'WAIT17', 'INS5', 'INS6', 'BBS4', 'FNU2', 'CPD1', 'WAIT37', 'FNJ2', 'FNJ3', 'FNJ4', 'WAIT49', 'OVN3', 'CUR2', 'PAD2', 'PAD3', 'PAD4', 'PAD5', 'CPF1', 'FNU3', 'FNU4', 'OVD2', 'OVD3', 'TMF1', 'TRF1', 'GKF1', 'BLD2', 'BLD3', 'BLD4', 'OVD4', 'OPW2', 'OPW3', 'OPW4', 'OVB1', 'OVB2', 'OVN4', 'OVN5', 'OVN6', 'CPF2', 'CPF3', 'CPF4', 'LIP2', 'LIP3', 'FNJ5', 'FNJ6', 'OVG1', 'CPD2', 'CPD3', 'LPS1'],
            'GKG' => ['BAT1', 'BKN1', 'JHP1', 'BEL1', 'BAT2', 'WAIT18', 'BBS1', 'WAIT36', 'BAT3', 'BA24'],
            'KNT' => ['BBS8', 'WAIT55'],
            'LAB' => ['MAT1', 'MAT2', 'LAB-T1', 'LAB-T2', 'LAB-T3', 'MAT2R2', 'MAT2-R1', 'MAT2-R2', 'MAT2-R3', 'MAT2-R4', 'MAT2-R5', 'MAT1-TC1', 'MAT1-TC2', 'MAT1-TC3', 'WAIT8', 'MAT2-R6', 'MAT1-TC4', 'MAT1-TC5', 'NCP9', 'BBS2', 'WAIT25', 'WAIT40', 'LAB2', 'WAIT52', 'TWR1'],
            'PPC' => ['WAIT3', 'PPC1', 'WAIT2', 'NCP10', 'WAIT23', 'BBS7', 'WAIT26', 'WAIT27', 'WAIT31', 'WAIT35'],
            'PRT' => ['PST2', 'ROT2', 'FLT2', 'FLT1', 'ROT1', 'CUR1', 'WSH1', 'INS2', 'ROT3', 'NCP12', 'PST3', 'WAIT41', 'STM2', 'INS8'],
            'QC'  => ['NCP2', 'KKT', 'PQC', 'INS4', 'INS3', 'CNP1', 'PPC2', 'TTQ', 'QCF1', 'QCF2', 'QCF4', 'QCF6', 'QCF7', 'QCF8', 'WAIT39', 'INS7', 'CNP2'],
            'RMP' => ['NCP7', 'BBS6', 'WAIT32', 'WAIT34'],
            'TAS' => ['TAS', 'NCP11', 'TAS2', 'WAIT53', 'BS12', 'WSH2', 'TAS3', 'TAS4'],
        ];

        $bindings = [$start, $end];

        $sql = "
            SELECT
                spm.ID,
                spm.MESIN,
                spm.PRODUCTION_DEMAND,
                spm.PRODUCTION_ORDER,
                spm.PLANNED_PROCESS,
                CAST(spm.PLANNED_PROCESS_DESCRIPTION AS VARCHAR(255)) AS PLANNED_PROCESS_DESCRIPTION,
                spm.SALES_ORDER,
                spm.SALES_ORDER_REQ_DUE_DATE,
                REPLACE(CAST(spm.PRODUCT_FAMILY AS VARCHAR(255)), ' ', '') AS PRODUCT_FAMILY,
                spm.COLOR_DESCRIPTION,
                spm.QUANTITY_TO_SCHEDULE,
                VARCHAR_FORMAT(spm.SCHEDULE_START, 'YYYY-MM-DD HH24:MI:SS') AS SCHEDULE_START,
                VARCHAR_FORMAT(spm.SCHEDULE_END, 'YYYY-MM-DD HH24:MI:SS') AS SCHEDULE_END,
                spm.CREATED_AT,
                spm.DELETED_AT,
                p.PROGRESSSTATUS,
                CASE
                    WHEN TRIM(p.PROGRESSSTATUS) = '0' THEN 'Entered'
                    WHEN TRIM(p.PROGRESSSTATUS) = '1' THEN 'Planned'
                    WHEN TRIM(p.PROGRESSSTATUS) = '2' THEN 'Progress'
                    WHEN TRIM(p.PROGRESSSTATUS) = '3' THEN 'Closed'
                END AS ALERT
            FROM
                SCHEDULE_PLAN_MQM spm
            LEFT JOIN PRODUCTIONDEMANDSTEP p
                ON p.PRODUCTIONDEMANDCODE = spm.PRODUCTION_DEMAND
               AND p.PRODUCTIONORDERCODE = spm.PRODUCTION_ORDER
               AND COALESCE(p.PRODRESERVATIONLINKGROUPCODE, p.OPERATIONCODE) = spm.PLANNED_PROCESS
            WHERE DATE(spm.SCHEDULE_START) BETWEEN ? AND ?
        ";

        // Tambahkan filter dept jika ada dan bukan 'all'
        if ($dept && isset($operationGroups[$dept]) && $dept !== 'all') {
            $inClause = implode(',', array_fill(0, count($operationGroups[$dept]), '?'));
            $sql .= " AND RTRIM(spm.PLANNED_PROCESS) IN ($inClause)";
            $bindings = array_merge($bindings, $operationGroups[$dept]);
        }

        $plans = DB::connection('DB2')->select($sql, $bindings);

        $machines = collect($plans)->groupBy(function ($item) {
            return trim($item->mesin);
        });

        $dates   = collect();
        $current = \Carbon\Carbon::parse($start);
        $last    = \Carbon\Carbon::parse($end);
        while ($current <= $last) {
            $dates->push($current->copy());
            $current->addDay();
        }

        return response()->json([
            'status'     => true,
            'message'    => 'Success',
            'data'       => $machines,
            'dates'      => $dates,
            'start'      => $start,
            'end'        => $end,
            'jamMulai'   => 0,
            'jamSelesai' => 24,
        ], 200);
    }

    public function import(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xls,xlsx',
        ]);

        try {
            Excel::import(new SchedulePlanImport, $request->file('excel_file'));

            return response()->json([
                'status'  => 'success',
                'message' => 'Data berhasil diimport.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal mengimport data.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function dataDetail(Request $request){
        $production_demand=$request->production_demand;
        $production_order=$request->production_order;
        $operation_code=$request->operation_code;

        $bindings = [$production_demand,$production_order,$operation_code];


        $sql = "SELECT
            ipkk.MULAI,
            ipkk.SELESAI,
            ipkk.STATUS_OPERATION,
            CASE
                WHEN TRIM(ipkk.STATUS_OPERATION) = 'Entered' THEN 'Red' 
                WHEN TRIM(ipkk.STATUS_OPERATION) = 'Planned' THEN 'Red'
                WHEN TRIM(ipkk.STATUS_OPERATION) = 'Progress' THEN 'Red'
                WHEN TRIM(ipkk.STATUS_OPERATION) = 'Closed' THEN 'Green'
            END AS ALERT
        FROM
            ITXVIEW_POSISI_KARTU_KERJA ipkk 
        WHERE 
            ipkk.PRODUCTIONDEMANDCODE = ?
            AND ipkk.PRODUCTIONORDERCODE = ?
            AND ipkk.OPERATIONCODE = ? ";

        $data = DB::connection('DB2')->select($sql, $bindings);

        return response()->json([
            'status'  => true,
            'message' => 'Success',
            'data'    => [
                'mulai'            => is_null($data[0]->mulai) ? '' : $data[0]->mulai,
                'selesai'          => is_null($data[0]->selesai) ? '' : $data[0]->selesai,
                'status_operation' => $data[0]->status_operation,
                'alert'            => $data[0]->alert
            ]
        ], 200);
        
    }
}
