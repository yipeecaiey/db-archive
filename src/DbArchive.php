<?php

namespace RingleSoft\DbArchive;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use RingleSoft\DbArchive\Jobs\ArchiveTableJob;
use RingleSoft\DbArchive\Services\ArchiveSettings;
use RingleSoft\DbArchive\Utility\Logger;
use Throwable;

class DbArchive
{

    public function __construct()
    {
    }

    /**
     * @return bool|Batch
     * @throws Throwable
     */
    public function archive(): bool|Batch
    {
        Logger::info('Starting DbArchive');

        $jobData = static::getJobData();

        if(Config::get('db_archive.queueing.enable_queuing')){
            if(Config::get('db_archive.queueing.enable_batching')) {
                $batch = Bus::batch([]);
                try {
                    foreach($jobData as $data){
                        $batch->add(new ArchiveTableJob($data['table'], $data['settings']));
                    }
                    $batch->then(function (Batch $batch) {
                        Logger::info('All jobs in the batch completed successfully.');
                    })->catch(function (Batch $batch, Throwable $e) {
                        Logger::error('Batch failed: ' . $e->getMessage());
                    })->finally(function (Batch $batch) {
                        Logger::info('Batch processing finished.');
                    })->then(function (Batch $batch) {
                        return $batch;
                    })->dispatch();
                } catch (Throwable $e) {
                    Logger::error('Batch failed: ' . $e->getMessage());
                    throw $e;
                }
            } else {
                //if we are processing tables async only run the first table
                if(Config::get('db_archive.queueing.process_tables_async')) {
                    $jobData = [$jobData[0]];
                }
                foreach($jobData as $data){
                    ArchiveTableJob::dispatch($data['table'], $data['settings']);
                }
            }
        } else {
            foreach ($jobData as $data) {
                ArchiveTableJob::dispatchSync($data['table'], $data['settings']);
            }
        }
        return true;
    }

    public static function finishedArchivingTable(string $table, ArchiveSettings $settings): void
    {
        Logger::info('Finished archiving table ' . $table);

        //if we are processing tables async get started on the next table
        if(Config::get('db_archive.queueing.process_tables_async', true)) {
            if($nextTable = static::getNextTable($table)) {
                $data = static::getJobData($nextTable);
                ArchiveTableJob::dispatch($data['table'], $data['settings']);
            } else {
                Logger::info('Finished DbArchive');
            }
        }
    }

    public static function getNextTable(string $table=null): ?string
    {
        $jobData = static::getJobData();
        if(is_null($table)) {
            return $jobData[0]['table'];
        }

        $takeNext=false;
        foreach($jobData as $data) {
            if($data['table'] == $table) {
                $takeNext=true;
                continue;
            }
            if($takeNext) {
                return $data['table'];
            }
        }
        return null;
    }

    public static function getJobData(string $tableName=null): ?array
    {
        $availableTables = Config::get('db_archive.tables');
        $jobData = [];
        foreach ($availableTables as $key => $value) {
            if(is_numeric($key)){
                $table = $value;
                $settings = [];
            } else {
                $table = $key;
                $settings = $value;
            }
            $tableJob = [
                'table' => $table,
                'settings' => $settings
            ];
            if($tableName && $table==$tableName) {
                return $tableJob;
            } else {
                $jobData[] = $tableJob;
            }
        }
        return ($tableName) ? null : $jobData ;
    }

}
