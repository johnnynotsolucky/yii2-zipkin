<?php
namespace johnnynotsolucky\Yii2\Zipkin;

use Yii;
use yii\base\Response;
use yii\base\Event;
use yii\log\Logger as YiiLogger;

use Zipkin\Annotation;
use Zipkin\Samplers\BinarySampler;
use Zipkin\Samplers\PercentageSampler;
use Zipkin\TracingBuilder;
use Zipkin\Reporters\Http;
use Zipkin\Propagation\Map;
use Zipkin\Tags;

class Tracer extends \yii\base\Component
{
    const DEFAULT_WEB_SAMPLE_RATE = 0.3;

    private $tracer;
    private $requestSpan;

    private $eventSpanIdx = [];
    private $spanIdx = [];
    private $spanStack = [];


    public $localServiceName = null;

    public $beforePrefixes = ['before', 'begin'];

    public $afterPrefixes = ['after', 'end'];

    public $enableProfiling = true;

    public $enableLogEvents = true;

    public $logLevels = [
        YiiLogger::LEVEL_ERROR,
        YiiLogger::LEVEL_WARNING,
        YiiLogger::LEVEL_INFO,
    ];

    public $webSampler = null;

    public $consoleSampler = null;

    public $zipkinEndpoint = null;
    public $zipkinReporter = null;

    public $isSampled;

    public function init()
    {
        $isConsoleRequest = Yii::$app->request->getIsConsoleRequest();

        if ($this->zipkinEndpoint === null && $this->zipkinReporter === null) {
            throw new \yii\base\InvalidConfigException('Missing Zipkin reporter.');
        }

        $reporter = $this->zipkinReporter ?? new Http(['endpoint_url' => $this->zipkinEndpoint]);

        if ($isConsoleRequest) {
            $sampler = $this->consoleSampler ?? BinarySampler::createAsAlwaysSample();
        } else {
            $sampler = $this->webSampler ?? PercentageSampler::create(self::DEFAULT_WEB_SAMPLE_RATE);
        }

        $serviceName = $this->localServiceName ? "{$this->localServiceName}-" : '';
        $tracing = TracingBuilder::create()
            ->havingLocalServiceName(
                $isConsoleRequest
                    ? "{$serviceName}-console"
                    : "{$serviceName}-web"
            )
            ->havingSampler($sampler)
            ->havingReporter($reporter)
            ->build();

        $this->tracer = $tracing->getTracer();

        // TODO
        // For span propagation in distributed systems
        $headers = !Yii::$app->request->getIsConsoleRequest()
            ? Yii::$app->request->getHeaders()->toArray()
            : [];
        $carrier = array_map(function ($header) {
            return $header[0];
        }, $headers);

        // Extracts the context from the HTTP headers
        $extractor = $tracing->getPropagation()->getExtractor(new Map());
        $extractedContext = $extractor($carrier);

        $span = $this->tracer->newTrace($extractedContext);

        $this->isSampled = $span->getContext()->isSampled();

        $span->start();
        $span->setKind(\Zipkin\Kind\SERVER);
        $span->setName(($isConsoleRequest ? 'console' : 'http').':request');

        $this->requestSpan = $span;
        $this->setCommonTags($span);

        if ($isConsoleRequest) {
            Event::on(
                \yii\base\Application::class,
                \yii\base\Application::EVENT_AFTER_REQUEST,
                [$this, 'handleRequestEnd']
            );
        } else {
            Event::on(
                \yii\web\Response::class,
                \yii\web\Response::EVENT_AFTER_SEND,
                [$this, 'handleRequestEnd']
            );
        }

        Yii::getLogger()->attachBehavior('tracing', new LoggerBehavior(['tracer' => $this]));
    }

    private function getIdxKey($span)
    {
        $context = $span->getContext();
        $key = md5(serialize($context));
        return $key;
    }

    public function getSpanAtIdx($idx)
    {
        return $this->spanStack[$idx] ?? false;
    }

    public function getSpanIdx($span)
    {
        $idxKey = $this->getIdxKey($span);
        return $this->spanIdx[$idxKey] ?? false;
    }

    private function setCommonTags($span)
    {
        $request = Yii::$app->request;
        if (!$request->getIsConsoleRequest()) {
            $span->tag(Tags\HTTP_HOST, $request->getHostInfo());
            $span->tag(Tags\HTTP_METHOD, $request->getMethod());
            $span->tag(Tags\HTTP_PATH, $request->getUrl());
            $span->tag('http.query_string', $request->getQueryString());
        } else {
            $response = Yii::$app->response;
            [$command, $params] = $request->resolve();
            $span->tag('console.command', $command);
            $span->tag('console.params', json_encode($params, JSON_PRETTY_PRINT));
        }
    }

    public function getNextSpan()
    {
        if (count($this->spanStack) > 0) {
            $parentSpan = $this->spanStack[array_key_last($this->spanStack)];
        } else {
            $parentSpan = $this->requestSpan;
        }
        $span = $this->tracer->newChild($parentSpan->getContext());

        $this->setCommonTags($span);

        $spanIdxKey = $this->getIdxKey($span);
        $this->spanIdx[$spanIdxKey] = count($this->spanStack);
        $this->spanStack[] = $span;

        return $span;
    }

    public function finishSpan($span)
    {
        $span->finish();

        $idx = $this->getSpanIdx($span);

        if ($idx !== false) {
            array_splice($this->spanStack, $idx, 1);
        }

        $idxKey = $this->getIdxKey($span);
        unset ($this->spanIdx[$idxKey]);
    }

    private function startsWith($eventName, $prefixes)
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return $prefix;
            }
        }

        return false;
    }

    private function getKeyFromName($eventName, $prefix): string
    {
        return substr($eventName, strlen($prefix), strlen($eventName));
    }

    private function getClassName($event)
    {
        if ($event->sender === null) {
            return str_replace('\\', '_', get_class($event));
        } else {
            return str_replace('\\', '_', get_class($event->sender));
        }
    }

    public function registerEvents(callable $callable = null)
    {
        Event::on(
            '*',
            '*',
            function ($event) use ($callable) {
                $name = $event->name;
                $class = $this->getClassName($event);

                $key = $name;
                $isBefore = $this->startsWith($name, $this->beforePrefixes);
                $isAfter = $this->startsWith($name, $this->afterPrefixes);

                if ($isBefore !== false) {
                    $key = $this->getKeyFromName($name, $isBefore);
                }

                $eventSpan = null;
                if ($isAfter !== false) {
                    $key = $this->getKeyFromName($name, $isAfter);
                    $eventSpan = $this->spanStack[$this->eventSpanIdx[$key] ?? -1] ?? null;
                }

                if ($eventSpan === null) {
                    $eventSpan = $this->getNextSpan();
                    $eventSpan->start();
                    $eventSpan->setKind(\Zipkin\Kind\SERVER);
                    $eventSpan->setName("event:{$class}:{$key}");

                    if ($callable !== null) {
                        call_user_func($callable, $event, $eventSpan);
                    }

                }

                if ($isBefore) {
                    $this->eventSpanIdx[$key] = $this->getSpanIdx($eventSpan);
                }

                if ($isAfter || !$isBefore) {
                    unset($this->eventSpanIdx[$key]);
                    $this->finishSpan($eventSpan);
                }
            }
        );
    }

    public function handleRequestEnd()
    {
        // Finish any unfinished spans.
        foreach ($this->spanStack as $unfinishedSpan) {
            $unfinishedSpan->finish();
        }

        $request = Yii::$app->request;
        $response = Yii::$app->response;

        if (!Yii::$app->request->getIsConsoleRequest()) {
            $this->requestSpan->tag(Tags\HTTP_STATUS_CODE, $response->getStatusCode());

            if ($response->getIsClientError()) {
                $this->requestSpan->tag('error', 'Client error');
            }

            if ($response->getIsServerError()) {
                $this->requestSpan->tag('error', 'Server error');
            }
        } else {
            $this->requestSpan->tag('console.status_code', $response->exitStatus);
        }

        $this->requestSpan->finish();
        $this->tracer->flush();
    }
}
