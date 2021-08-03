<?php
namespace johnnynotsolucky\Yii2\Zipkin;

use yii\log\Logger as YiiLogger;
use Zipkin\Tags;

class Logger extends YiiLogger
{
    private $profileSpanIdx = [];

    public $tracer;

    /**
     * {@inheritdoc}
     */
    public function log($message, $level, $category = 'application')
    {
        if (in_array($level, [static::LEVEL_PROFILE_BEGIN, static::LEVEL_PROFILE_END])) {
            $this->handleProfileLog($message, $level, $category);
        } else {
            $this->handleMetricLog($message, $level, $category);
        }

        parent::log($message, $level, $category);
    }

    private function handleMetricLog($message, $level, $category)
    {
        $name = str_replace('\\', '_', $category);
        $name = str_replace('::', ':', $name);

        $span = $this->tracer->getNextSpan();
        $span->start();
        $span->setKind(\Zipkin\Kind\SERVER);
        $span->setName("metric:{$name}");
        $span->tag('log.data', json_encode($message));
        $span->tag('log.level', $level);
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

            if ($isDbProfile) {
                $span->tag(Tags\SQL_QUERY, json_encode($message));
            } else {
                $span->tag('profile.data', json_encode($message));
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


