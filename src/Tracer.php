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

    private $eventSpanIdx = [];
    private $spanIdx = [];
    private $spanStack = [];
    private $extractedContext = null;

    private $isSampled;

    private $_initialized = false;

    public $localServiceName = 'app';

    public $beforePrefixes = ['before', 'begin'];

    public $afterPrefixes = ['after', 'end', 'commit', 'rollback'];

    public $eventExcludeRules = [];

    public $enableProfiling = false;

    public $enableLogEvents = false;

    public $logLevels = [
        YiiLogger::LEVEL_ERROR,
        YiiLogger::LEVEL_WARNING,
        YiiLogger::LEVEL_INFO,
    ];

    public $webSampler = null;

    public $consoleSampler = null;

    public $zipkinEndpoint = null;
    public $zipkinReporter = null;

    public $pathExcludeRules = [];

    public $defaultKind = \Zipkin\Kind\SERVER;

    public $requestSpanName = null;

    public function init()
    {
        if ($this->zipkinEndpoint === null && $this->zipkinReporter === null) {
            throw new \yii\base\InvalidConfigException('Missing Zipkin reporter.');
        }
    }

    public function getIsSampled()
    {
        return $this->isSampled;
    }

    public function initializeTracing()
    {
        if ($this->_initialized) {
            return;
        }

        $isConsoleRequest = Yii::$app->request->getIsConsoleRequest();
        if ($this->requestSpanName === null) {
            $this->requestSpanName = ($isConsoleRequest ? 'console' : 'http').':request';
        }
        if ($isConsoleRequest) {
            $sampler = $this->consoleSampler ?? BinarySampler::createAsAlwaysSample();
        } else {
            $shouldSample = true;

            $path = Yii::$app->request->getUrl();
            foreach ($this->pathExcludeRules as $rule) {
                if (preg_match($rule, $path)) {
                    $shouldSample = false;
                    break;
                }
            }

            $sampler = $shouldSample
                ? $this->webSampler ?? PercentageSampler::create(self::DEFAULT_WEB_SAMPLE_RATE)
                : BinarySampler::createAsNeverSample();
        }

        $reporter = $this->zipkinReporter ?? new Http(['endpoint_url' => $this->zipkinEndpoint]);
        $tracing = TracingBuilder::create()
            ->havingLocalServiceName($this->localServiceName)
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
        $this->extractedContext = $extractor($carrier);

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

        $logConfig = array_merge(
            get_object_vars(Yii::getLogger()),
            ['tracer' => $this],
        );
        Yii::setLogger(new Logger($logConfig));

        $requestSpan = $this->getNextSpanInternal(true);
        $requestSpan->start();
        $requestSpan->setKind($this->defaultKind);
        $requestSpan->setName($this->requestSpanName);

        $this->_initialized = true;
    }

    private function createRequestSpan()
    {
    }

    private function ensureInitialized()
    {
        if (!$this->_initialized) {
            throw new \yii\base\InvalidConfigException('Tracer has not been initialized.');
        }
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
        if (!Yii::$app->request->getIsConsoleRequest()) {
            $span->tag(Tags\HTTP_HOST, $request->getHostInfo());
            $span->tag(Tags\HTTP_METHOD, $request->getMethod());
            $span->tag(Tags\HTTP_PATH, $request->getPathInfo());
            $span->tag('http.query_string', $request->getQueryString());
            $span->tag('http.body', $request->getRawBody());
        } else {
            $response = Yii::$app->response;
            [$command, $params] = $request->resolve();
            $span->tag('console.command', $command);
            $span->tag('console.params', json_encode($params, JSON_PRETTY_PRINT));
        }
    }

    public function getRequestSpan()
    {
        $this->ensureInitialized();
        return $this->getSpanAtIdx(0);
    }

    private function getNextSpanInternal($initial)
    {
        if ($initial) {
            $span = $this->tracer->newTrace($this->extractedContext);
            $this->isSampled = $span->getContext()->isSampled();
        } else {
            $parentSpan = $this->spanStack[array_key_last($this->spanStack)];
            $span = $this->tracer->newChild($parentSpan->getContext());
        }

        $this->setCommonTags($span);

        $spanIdxKey = $this->getIdxKey($span);
        $this->spanIdx[$spanIdxKey] = count($this->spanStack);
        $this->spanStack[] = $span;

        return $span;
    }

    public function getNextSpan()
    {
        $this->ensureInitialized();
        return $this->getNextSpanInternal(false);
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
        $this->ensureInitialized();

        Event::on(
            '*',
            '*',
            function ($event) use ($callable) {
                $name = $event->name;
                $class = $this->getClassName($event);

                foreach ($this->eventExcludeRules as $rule) {
                    if (preg_match($rule, "{$class}:{$name}")) {
                        return;
                    }
                }

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
                    $shouldCreateSpan = true;
                    $tags = [];
                    if (is_callable($callable)) {
                        $result = call_user_func($callable, $event, $key);
                        if (is_array($result)) {
                            $tags = $result;
                        } else if ($result === false) {
                            $shouldCreateSpan = false;
                        }
                    }

                    if ($shouldCreateSpan) {
                        $eventSpan = $this->getNextSpan();
                        $eventSpan->start();
                        foreach ($tags as $tag => $value) {
                            $eventSpan->tag($tag, $value);
                        }
                        $eventSpan->setKind(\Zipkin\Kind\SERVER);
                        $eventSpan->setName("event:{$class}:{$key}");
                    }
                }

                if ($eventSpan && $isBefore) {
                    $eventSpan->tag('event.before', $event->name);
                    $this->eventSpanIdx[$key] = $this->getSpanIdx($eventSpan);
                }

                if ($eventSpan && ($isAfter || !$isBefore)) {
                    if ($isAfter) {
                        $eventSpan->tag('event.after', $event->name);
                    } else {
                        $eventSpan->tag('event.name', $event->name);
                    }
                    unset($this->eventSpanIdx[$key]);
                    $this->finishSpan($eventSpan);
                }
            }
        );
    }

    public function handleRequestEnd()
    {
        // Finish any unfinished spans.
        $spanStack = array_reverse($this->spanStack);
        $requestSpan = array_pop($spanStack);

        foreach ($spanStack as $unfinishedSpan) {
            $unfinishedSpan->finish();
        }

        $request = Yii::$app->request;
        $response = Yii::$app->response;

        if (!Yii::$app->request->getIsConsoleRequest()) {
            $requestSpan->tag(Tags\HTTP_STATUS_CODE, $response->getStatusCode());

            if ($response->getIsClientError()) {
                $requestSpan->tag('error', 'Client error');
            }

            if ($response->getIsServerError()) {
                $requestSpan->tag('error', 'Server error');
            }
        } else {
            $requestSpan->tag('console.status_code', $response->exitStatus);
        }

        $requestSpan->finish();
        $this->tracer->flush();
    }
}
