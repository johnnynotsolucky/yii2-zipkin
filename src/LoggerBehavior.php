<?php
namespace johnnynotsolucky\Yii2\Zipkin;

use yii\log\Logger as YiiLogger;
use yii\base\Behavior;
use Zipkin\Tags;

class LoggerBehavior extends Behavior
{
    const LEVEL_MAP = [
        YiiLogger::LEVEL_ERROR => 'error',
        YiiLogger::LEVEL_WARNING => 'warning',
        YiiLogger::LEVEL_INFO => 'info',
        YiiLogger::LEVEL_TRACE => 'trace',
    ];

    private $profileSpanIdx = [];

    public $tracer;

    /**
     * {@inheritdoc}
     */
    public function log($message, $level, $category = 'application')
    {
        if (in_array($level, [static::LEVEL_PROFILE_BEGIN, static::LEVEL_PROFILE_END])) {
            if ($this->tracer->enableProfiling) {
                $this->handleProfileLog($message, $level, $category);
            }
        } else {
            if ($this->tracer->enableLogEvents && in_array($level, $this->tracer->logLevels)) {
                $this->handleMetricLog($message, $level, $category);
            }
        }

        parent::log($message, $level, $category);
    }

    private function handleMetricLog($message, $level, $category)
    {
        $name = str_replace('\\', '_', $category);
        $name = str_replace('::', ':', $name);

        $span = $this->tracer->getNextSpan();
        $span->setKind(\Zipkin\Kind\SERVER);
        $span->setName("log:{$name}");

        $message = is_string($message) ? $message : json_encode($message);
        if ($level === YiiLogger::LEVEL_ERROR) {
            $span->tag('error', $message);
        } else {
            $span->tag('log.data', $message);
        }

        $span->tag('log.level', self::LEVEL_MAP[$level]);
        $span->start();
        $this->tracer->finishSpan($span);
    }

    private function handleProfileLog($message, $level, $category)
    {
        $isDbProfile = in_array($category, $this->dbEventNames);

        $name = str_replace('\\', '_', $category);
        $name = str_replace('::', ':', $name);
        $profilePrefix = $isDbProfile ? 'query:' : '';

        $key = md5(json_encode([$name, $message]));

        $tracer = $this->tracer;
        if ($level === static::LEVEL_PROFILE_BEGIN) {
            $span = $tracer->getNextSpan();
            $span->start();
            $span->setKind(\Zipkin\Kind\SERVER);
            $span->setName("profile:${profilePrefix}{$name}");

            $message = is_string($message) ? $message : json_encode($message);
            if ($isDbProfile) {
                $span->tag(Tags\SQL_QUERY, $message);
            } else {
                $span->tag('profile.data', $message);
            }

            $this->profileSpanIdx[$key] = $tracer->getSpanIdx($span);
        } else if ($level === static::LEVEL_PROFILE_END) {
            $spanIdx = $this->profileSpanIdx[$key] ?? -1;
            $span = $tracer->getSpanAtIdx($spanIdx);

            if ($span !== false) {
                $tracer->finishSpan($span);
                unset($this->profileSpanIdx[$key]);
            }
        }
    }
}


