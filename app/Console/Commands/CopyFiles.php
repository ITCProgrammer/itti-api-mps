<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class CopyFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:copy-multimedia';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting multimedia files copy process...');

        try {
            // Verifikasi koneksi database
            try {
                DB::connection('DB2')->getPdo();
            } catch (\Exception $e) {
                throw new \Exception("DB Connection failed: " . $e->getMessage());
            }

            // Verifikasi direktori
            if (!is_dir('Z:/deploy/now.ear/nowui.war')) {
                throw new \Exception("Source directory not found");
            }

            if (!is_dir('C:/migration') && !mkdir('C:/migration', 0777, true)) {
                throw new \Exception("Failed to create destination directory");
            }

            // Ambil data
            $records = DB::connection('DB2')
                ->table('REPLENISHMENTREQUISITION as r')
                ->leftJoin('ABSMULTIMEDIADEPENDENT as a', 'a.FATHERID', '=', 'r.ABSUNIQUEID')
                ->select([
                    'r.HEADERCODE',
                    'a.LINK',
                    'r.CREATIONDATETIME'
                ])
                ->where('r.APPROVALLEVEL', '0')
                ->whereDate('r.CREATIONDATETIME', '>=', '2025-06-01')
                ->get();

            $baseSourcePath = 'Z:/deploy/now.ear/nowui.war';
            $baseDestinationPath = 'C:/migration';
            $successCount = 0;
            $failedCount = 0;

            foreach ($records as $record) {
                if (empty($record->link)) {
                    $this->warn("Skipped: link is empty");
                    continue;
                }

                $cleanPath = ltrim($record->link, '/');
                $sourcePath = $baseSourcePath . '/' . $cleanPath;
                $destinationPath = $baseDestinationPath . '/' . $cleanPath;
                $formattedDate = substr($record->creationdatetime, 0, 23);
                $logData = [
                    'header_code' => $record->headercode,
                    'file_path' => $cleanPath,
                    'source_path' => $sourcePath,
                    'destination_path' => $destinationPath,
                    'original_creation_date' => $formattedDate,
                    'created_at' => now()
                ];

                try {
                    // Buat direktori tujuan
                    $destinationDir = dirname($destinationPath);
                    if (!is_dir($destinationDir)) {
                        mkdir($destinationDir, 0777, true);
                    }

                    if (!file_exists($sourcePath)) {
                        throw new \Exception("Source file not found");
                    }

                    if (copy($sourcePath, $destinationPath)) {
                        $logData['is_success'] = true;
                        $successCount++;
                        $this->info("SUCCESS: {$cleanPath}");
                    } else {
                        throw new \Exception("Copy operation failed");
                    }
                } catch (\Exception $e) {
                    $logData['is_success'] = false;
                    $logData['error_message'] = $e->getMessage();
                    $failedCount++;
                    $this->error("ERROR: {$e->getMessage()}");
                }

                // Simpan log ke database
                DB::table('dbo.file_copy_logs')->insert($logData);
            }

            $summary = "Process completed. Success: {$successCount}, Failed: {$failedCount}";
            $this->info($summary);

            return $successCount > 0 ? Command::SUCCESS : Command::FAILURE;

        } catch (\Exception $e) {
            $this->error("FATAL ERROR: " . $e->getMessage());

            // Log error utama ke database
            DB::table('file_copy_logs')->insert([
                'header_code' => null,
                'file_path' => '',
                'source_path' => '',
                'destination_path' => '',
                'is_success' => false,
                'error_message' => $e->getMessage(),
                'original_creation_date' => null,
                'created_at' => now()
            ]);

            return Command::FAILURE;
        }
    }
}
