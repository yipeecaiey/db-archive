<?php

namespace RingleSoft\DbArchive\Services;

use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RingleSoft\DbArchive\Jobs\ArchiveTableJob;
use RingleSoft\DbArchive\Utility\Logger;
use Throwable;

class TableArchiver
{
    public string $table;

    public string $archiveTable;
    public ?string $activeConnection;
    public ?string $archiveConnection;
    public ArchiveSettings $settings;
    public Carbon $cutoffDate;

    public function __construct(string $table, array|ArchiveSettings|null $settings = [])
    {
        $this->withSettings($settings ?? []);
        $this->table = $table;
        $this->activeConnection = Config::get("database.default");
        $this->archiveConnection = Config::get('db_archive.connection');
        $this->archiveTable = $this->settings->tablePrefix ? ($this->settings->tablePrefix . '_' . $this->table) : $this->table;

    }

    public static function of(string $table): self
    {
        return new self($table);
    }

    public function withSettings(array|ArchiveSettings $settings): self
    {
        $this->settings = ($settings instanceof ArchiveSettings) ? $settings : ArchiveSettings::fromArray($settings);
        return $this;
    }


    /**
     * @return bool
     * @throws Throwable
     */
    public function archive(): bool
    {
        $this->cutoffDate = Carbon::now()->subDays($this->settings->archiveOlderThanDays);
        $this->log("Archiving table: " . $this->table);
        $sourceConnection = DB::connection($this->activeConnection);
        $archiveConnection = DB::connection($this->archiveConnection);
        $sourceTableName = $this->table;
        $archiveTableName = $this->archiveTable;
        $batchSize = $this->settings->batchSize;
        $jobSize = $this->settings->jobSize;
        $dateColumn = $this->settings->dateColumn;
        $conditions = $this->settings->conditions;
        $primaryId = $this->settings->primaryId ?? 'id';

        try {
            $query = $sourceConnection->table($sourceTableName)
                ->where($dateColumn, '<', $this->cutoffDate)
                ->when(count($conditions), function ($query) use ($conditions) {
                    foreach ($conditions as $key => $value) {
                        if (is_numeric($key) && is_array($value) && (count($value) && count($value) <= 3)) {
                            $query->where(...$value);
                        } else {
                            $query->where($key, $value);
                        }
                    }
                });
            $archivableRecordsCount = $query->clone()->count();
            $numBatches = ceil(min($jobSize, $archivableRecordsCount) / $batchSize);
            for($i=0;$i<$numBatches;$i++) {
                DB::beginTransaction(); // Start a transaction to ensure atomicity
                $sourceRecords = $query->clone()
                    ->orderBy($dateColumn)
                    ->limit($batchSize)
                    ->get();
                $dataToArchive = [];
                $idsToDelete = [];

                foreach ($sourceRecords as $record) {
                    $dataToArchive[] = (array)$record;
                    $idsToDelete[] = $record->{$primaryId};
                }

                if (!empty($dataToArchive)) {
                    $archiveConnection->table($archiveTableName)->insert($dataToArchive);
                }

                if (!empty($idsToDelete)) {
                    $sourceConnection->table($sourceTableName)
                        ->whereIn($primaryId, $idsToDelete)
                        ->delete();
                }

                DB::commit();
            }

            Logger::info('Archived ' . $batchSize * $numBatches . ' records for table ' . $this->table);

            //if we have reached the maximum number of records for a job, spawn another
            if($archivableRecordsCount >= $batchSize * $numBatches) {
                ArchiveTableJob::dispatch($this->table, $this->settings);
            } else {
                Logger::info('Finished archiving ' . $this->table);
            }

            return true;
        } catch (QueryException $e) {
            DB::rollBack(); // Rollback the transaction in case of any error
            Logger::error($e->getMessage());
            return false; // Or throw an exception if you want to handle it differently up the call stack
        } catch (Exception $e) {
            DB::rollBack(); // Rollback transaction for other exceptions as well
            Logger::error($e->getMessage());
            return false; // Or throw exception
        } catch (Throwable $e) {
            DB::rollBack(); // Rollback transaction for other exceptions as well
            Logger::error($e->getMessage());
            return false; // Or throw exception
        }
    }

    /**
     * @param $data
     * @param String|null $type
     * @return void
     */
    private function log($data, ?string $type = "info"): void
    {
        if (Config::get('db_archive.enable_logging')) {
            // TODO: modify the method
            Log::debug($data);
        }
    }

}

