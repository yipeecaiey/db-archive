<?php

namespace RingleSoft\DbArchive\Services;

use Illuminate\Support\Facades\Config;

class ArchiveSettings
{
    public ?String $tablePrefix;
    public int $batchSize = 1000;
    public int $jobSize = 100000;
    public ?int $archiveOlderThanDays;
    public String $dateColumn = 'created_at';
    public ?string $dispatchModelDeletingEvent = null;
    public bool $softDelete = false;
    public array $conditions = [];
    public ?string $primaryId = "id";

    public function __construct(?int $archiveOlderThanDays, ?int $batchSize, ?int $jobSize, ?String $dateColumn, ?string $dispatchModelDeletingEvent, ?bool $softDelete, ?String $tablePrefix, ?array $conditions, ?string $primaryId)
    {
        $this->tablePrefix = $tablePrefix ?? null;
        $this->archiveOlderThanDays = $archiveOlderThanDays ?? 365;
        $this->batchSize = $batchSize ?? $this->batchSize;
        $this->jobSize = $jobSize ?? $this->jobSize;
        $this->dateColumn = $dateColumn ?? $this->dateColumn;
        $this->dispatchModelDeletingEvent = $dispatchModelDeletingEvent ?? $this->dispatchModelDeletingEvent;
        $this->softDelete = $softDelete ?? $this->softDelete;
        $this->conditions = $conditions ?? $this->conditions;
        $this->primaryId = $primaryId ?? $this->primaryId;
    }

    public static function fromArray(array $settings): self
    {
        $defaultSettings = [
            'table_prefix' => Config::get('db_archive.settings.table_prefix'),
            'batch_size' => Config::get('db_archive.settings.batch_size', 1000),
            'job_size' => Config::get('db_archive.settings.job_size', 100000),
            'archive_older_than_days' => Config::get('db_archive.settings.archive_older_than_days', 30),
            'date_column' => Config::get('db_archive.settings.date_column', 'created_at'),
            'dispatch_model_deleting_event' => Config::get('db_archive.settings.dispatch_model_deleting_event', null),
            'soft_delete' => Config::get('db_archive.settings.soft_delete', false),
        ];
        $settings = array_merge($defaultSettings, $settings);
        return new self(
            archiveOlderThanDays: $settings['archive_older_than_days'] ?? null,
            batchSize: $settings['batch_size'] ?? null,
            jobSize: $settings['job_size'] ?? null,
            dateColumn: $settings['date_column'] ?? null,
            dispatchModelDeletingEvent: $settings['dispatch_model_deleting_event'] ?? null,
            softDelete: $settings['soft_delete'] ?? null,
            tablePrefix: $settings['table_prefix'] ?? null,
            conditions: $settings['conditions'] ?? null,
            primaryId: $settings['primary_id'] ?? null
        );
    }

}
