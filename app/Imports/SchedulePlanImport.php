<?php
namespace App\Imports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Row;

class SchedulePlanImport implements OnEachRow
{
    public function onRow(Row $row)
    {
        // Lewati baris pertama (judul kolom)
        if ($row->getIndex() === 1) {
            return;
        }

        $row = $row->toArray();

        // Lewati jika kolom-kolom utama kosong
        if (empty($row[0]) || empty($row[1]) || empty($row[2]) || empty($row[3])) {
            return;
        }

        DB::connection('DB2')->table('SCHEDULE_PLAN_MQM')->insert([
            'mesin'                       => $row[0],
            'production_demand'           => $row[1],
            'production_order'            => $row[2],
            'planned_process'             => $row[3],
            'planned_process_description' => $row[4],
            'sales_order'                 => $row[5],
            'sales_order_req_due_date'    => $row[6],
            'product_family'              => $row[7],
            'color_description'           => $row[8],
            'quantity_to_schedule'        => (int) $row[9],
            'schedule_start'              => $this->safeExcelDateTime($row[10]),
            'schedule_end'                => $this->safeExcelDateTime($row[11]),
            'created_at'                  => now(),
            'deleted_at'                  => null,
        ]);
    }

    private function safeExcelDate($value)
    {
        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }
        return null;
    }

    private function safeExcelDateTime($value)
    {
        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
        }
        return null;
    }

}
