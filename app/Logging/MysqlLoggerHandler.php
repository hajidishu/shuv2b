<?php
namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        try{
            if(isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable){
                $exception = $record['context']['exception'];
                $record['context']['exception'] = [
                    'class' => get_class($exception), 'message' => $exception->getMessage(),
                    'file' => $exception->getFile(), 'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ];
            }
            $record['request_data'] = request()->all() ??[];
            $log = [
                'title' => $record['message'],
                'level' => $record['level_name'],
                'host' => $record['request_host'] ?? request()->getSchemeAndHttpHost(),
                'uri' => $record['request_uri'] ?? request()->getRequestUri(),
                'method' => $record['request_method'] ?? request()->getMethod(),
                'ip' => request()->getClientIp(),
                'data' => json_encode($record['request_data']) ,
                'context' => isset($record['context']) ? json_encode($record['context'], JSON_INVALID_UTF8_SUBSTITUTE) : '',
                'created_at' => strtotime($record['datetime']),
                'updated_at' => strtotime($record['datetime']),
            ];

            LogModel::insert(
                $log
            );
        }catch (\Exception $e){
            Log::channel('daily')->error($e->getMessage().$e->getFile().$e->getTraceAsString());
        }
    }
}
