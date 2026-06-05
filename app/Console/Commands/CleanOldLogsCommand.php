<?php

namespace App\Console\Commands;

use App\Models\ApiRequestLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanOldLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:clean {--days= : Number of days to retain logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean old API request logs from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $retentionDays = $this->getRetentionDays();
            $cutoffDate = Carbon::now()->subDays($retentionDays);

            $deletedCount = ApiRequestLog::olderThan($cutoffDate)->delete();

            $this->info("Deleted {$deletedCount} log entries older than {$retentionDays} days.");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to clean logs: {$e->getMessage()}");
            Log::error('Log cleanup failed', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }
    }

    /**
     * Get the retention period in days.
     */
    protected function getRetentionDays(): int
    {
        $days = $this->option('days');

        if ($days !== null) {
            return (int) $days;
        }

        return config('apicache.log_retention_days', 30);
    }
}
