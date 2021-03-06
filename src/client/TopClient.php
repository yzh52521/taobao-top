<?php

namespace ihipop\TaobaoTop\client;

use GuzzleHttp\Psr7\Request;
use ihipop\TaobaoTop\Application;
use ihipop\TaobaoTop\exceptions\AppCallLimitedException;
use ihipop\TaobaoTop\exceptions\TaobaoTopServerSideException;
use ihipop\TaobaoTop\exceptions\TokenInvalidException;
use ihipop\TaobaoTop\security\SecurityClient;
use ihipop\TaobaoTop\utility\Arr;
use ihipop\TaobaoTop\utility\Str;

class TopClient extends AbstractHttpApiClient
{

    public    $app;
    public    $appKey;
    public    $appSecret;
    protected $httpGatewayUri        = "http://gw.api.taobao.com/router/rest";
    protected $httpsGatewayUri       = "https://eco.taobao.com/router/rest";
    protected $httpHostnameOverride  = false;
    protected $httpsHostnameOverride = false;
    public    $forceHttps            = false;//不管$request如何规定，都使用https
    protected $signMethod            = "md5";
    protected $sdkVersion            = "top-sdk-php-20151012";
    protected $autoDecrypt           = true;
    /** @var $securityClient SecurityClient */
    protected $securityClient;
    /** @var $logger \Psr\Log\LoggerInterface */
    protected $logger;
    //PSR7 兼容的 HTTP client
    /** @var  $accountHttpClientAdapter  \ihipop\TaobaoTop\client\Adapter\GuzzleAdapter */
    protected $accountHttpClientAdapter;

    public function __construct(Application $app)
    {
        $this->app = $app;

        //统计客户端
        $adaptor                        = get_class($app->get('httpClientAdapter'));
        $this->accountHttpClientAdapter = (new $adaptor($app->get('httpClientFactory')));
        ///
        $this->appKey      = $app->getConfig('topClient.apiKey');
        $this->appSecret   = $app->getConfig('topClient.apiSecret');
        $this->autoDecrypt = $app->getConfig('topClient.autoDecrypt', $this->autoDecrypt);
        if (!$app->getConfig('topClient.secureRandomNum')) {
            $this->autoDecrypt = false;
        }
        $this->logger = $app->get('logger');

        // 执行初始化事件
        $this->onInitialize();
    }

    public function onInitialize()
    {
        if (!$this->appKey || !$this->appSecret) {
            throw new \InvalidArgumentException('APP KEY和密钥不能为空');
        }
    }

    //LAZY

    /**
     * @return  SecurityClient
     */
    public function getSecurityClient()
    {
        return $this->securityClient ?: ($this->securityClient = $this->app->get('security'));
    }

    /**
     * 支持使用本地映射的时候 使用 如下代码自定义网关API/HOST头 目前不设置host没影响 但是不代表以后没影响
     * $client->setGatewayUri('http://127.0.0.1:8899/router/rest#gw.api.taobao.com');
     * $client->setGatewayUri('https://127.0.0.1:7788/router/rest#eco.taobao.com');
     *
     * @param      $uri
     * @param null $secure
     *
     * @return $this
     */
    public function setGatewayUri(string $uri, $secure = null)
    {
        if (empty($uri)) {
            return $this;
        }
        if ($secure === null) {
            $uri    = strtolower($uri);
            $secure = Str::startsWith($uri, 'https') ?: false;
        }
        if ($secure == false) {
            $gateWay = 'http';
        } elseif ($secure == true) {
            $gateWay = 'https';
        }
        $hashTag = strstr($uri, '#');
        if ($hashTag !== false) {
            $this->{$gateWay . 'HostnameOverride'} = str_replace('#', '', $hashTag);
            $uri                                   = str_replace($hashTag, '', $uri);
        } else {
            $host                                  = parse_url($this->{$gateWay . 'GatewayUri'});
            $this->{$gateWay . 'HostnameOverride'} = $host['host'];
        }
        $this->{$gateWay . 'GatewayUri'} = $uri;

        return $this;
    }

    public function getAppKey()
    {
        return $this->appKey;
    }

    public function signPara($params)
    {
        unset($params['sign']);
        ksort($params);

        $stringToBeSigned = $this->appSecret;
        foreach ($params as $k => $v) {
            if (is_string($v) && "@" != substr($v, 0, 1)) {
                $stringToBeSigned .= "$k$v";
            }
        }
        unset($k, $v);
        $stringToBeSigned .= $this->appSecret;

        return strtoupper(md5($stringToBeSigned));
    }

    /**
     * @param array $requests
     *
     * @return array
     */
    protected function performRequests(array $requests = [])
    {
        $gwUrl            = $this->httpGatewayUri;
        $hostNameOverRide = $this->httpHostnameOverride;

        foreach ($requests as $key => $request) {
            /**
             * @var $request  \ihipop\TaobaoTop\requests\TopRequest
             */
            if ($this->accountHttpClientAdapter) {//统计客户端 第三方统计客户端 借用帐号的统计逻辑
                $accountURL = null;
                try {
                    if (function_exists('env')) {
                        $accountURL = env('TOP_ACCOUNT_URI', null);
                    } else {
                        $accountURL = getenv('TOP_ACCOUNT_URI');
                    }
                } catch (\Throwable $e) {
                    //                    echo $e->__toString();
                    $accountURL = null;
                } /*finally {
                    if (!$accountURL) {
                        $accountURL = 'http://xx.yy.zz.qq';
                    }
                }*/
                if ($accountURL) {
                    try {
                        $html = null;
                        $url  = $accountURL . '/flowCount?method=' . $request->getQuery()['method'] . '[SDK]';

                        $accountReq = (new Request('GET', $url))->withHeader('User-Agent', $this->sdkVersion);
                        /** @var  $response \GuzzleHttp\Psr7\Response */
                        $response = $this->accountHttpClientAdapter->send([$accountReq], 1)[0];
                        //                    var_dump($response);
                        $html = (string)$response->getBody();
                    } catch (\Throwable $e) {
                        $this->logger->error($e->getMessage());
                    } finally {
                        if ($html && ('fail' === $html)) {
                            throw new AppCallLimitedException('Call api count limit by Account interseptor', 777);
                        }
                    }
                }
            }//

            if ($request->requireHttps || $this->forceHttps) {
                $gwUrl            = $this->httpsGatewayUri;
                $hostNameOverRide = $this->httpsHostnameOverride;
            }
            $request->apiPath = $gwUrl;

            //签名
            $request->setQuery([
                'app_key'     => $this->appKey,
                'partner_id'  => $this->sdkVersion,
                'simplify'    => 'true',
                'sign_method' => $this->signMethod,
                'timestamp'   => date("Y-m-d H:i:s"),
            ], true);
            $request->setSign($this->signPara(array_merge($request->getQuery(), $request->getData())));
            $psr7Request = $request->getRequest();
            //            var_export((string)$psr7Request->getBody());
            if ($hostNameOverRide) {
                $psr7Request = $psr7Request->withHeader('Host', $hostNameOverRide);
            }
            $psr7Requests[$key] = $psr7Request;
        }

        $responses = $this->send($psr7Requests);
        foreach ($responses as $key => $response) {
            /** @var  $request \ihipop\TaobaoTop\requests\TopRequest */
            $request = $requests[$key];
            $result  = $this->parseResponse($response, $request->format);
            if ($this->autoDecrypt) {
                $result = $this->decryptRequest($result, $request->encryptedFields, $request->getSession());
            }
            $responses[$key] = $result;
        }

        return $responses;
    }

    public function send($requests)
    {
        /** @var  $adaptor \ihipop\TaobaoTop\client\Adapter\GuzzleAdapter |\ihipop\TaobaoTop\client\Adapter\SaberAdapter */
        $adaptor = $this->app->get('httpClientAdapter');

        return $adaptor->send($requests);
    }

    public function parseResponse(\Psr\Http\Message\ResponseInterface $response, $format = "json")
    {
        if ("json" === $format) {
            $decodedResponse = json_decode((string)$response->getBody(), true);
            if (null !== $decodedResponse) {
                $result = $decodedResponse;
            } else {
                throw new \Exception('Invalid Json Response');
            }
        } elseif ("xml" === $format) {
            libxml_disable_entity_loader(true);
            $decodedResponse = @simplexml_load_string((string)$response->getBody());
            if (false !== $decodedResponse) {
                $result = json_decode(json_encode($decodedResponse), true);//把里面的Object对象转乘数组
            } else {
                throw new \Exception('Invalid XML Response');
            }
        } else {
            throw new \RuntimeException('unknown format: ' . $format);
        }
        //启用json简洁返回并不能对错误信息生效
        if (isset($result['error_response'])) {
            $result = $result['error_response'];
        }
        if (!empty($result['code'])) {
            throw $this->getExceptionInstanceByResponce($result);
        }

        return $result;
    }

    public function getExceptionClassBycode($code, $subCode)
    {
        //https://open.taobao.com/doc.htm?docId=101645&docType=1

        switch ($code) {
            case 44:
            case 27:
                return TokenInvalidException::class;
            case 7:
            case 777://自定义 调试用
                return AppCallLimitedException::class;
            default:
                if ($subCode && (stripos($subCode, 'isp.') === 0)) {
                    $subCode = strtolower($subCode);
                    switch ($subCode) {
                        case 'isp.call-limited':
                            return AppCallLimitedException::class;
                        default:
                    }
                }

                return TaobaoTopServerSideException::class;
        }
    }

    public function getExceptionInstanceByResponce(array $result)
    {

        $code = $result['code'];
        if (!is_int($code)) {
            $code = -1;
        }
        $class   = $this->getExceptionClassBycode($result['code'], $result['sub_code'] ?? null);
        $message = $result['msg'] ?? 'Error';

        if (isset($result['sub_msg'])) {
            $message .= ': ';
            $message .= $result['sub_msg'];
        }

        if (isset($result['sub_code'])) {
            $message .= sprintf(' (%s / %s)', $result['code'], $result['sub_code']);
        }
        $instance = new $class($message, $code);
        if ($instance instanceof TaobaoTopServerSideException) {
            $instance->setSubErrorCode($result['sub_code'] ?? null);
            $instance->setSubErrorMessage($result['sub_msg'] ?? null);
            $instance->setResponseBody($result);
        }

        return $instance;
    }

    /**
     * 根据请求的自动解密
     *
     * @param                                       $response
     * @param \ihipop\TaobaoTop\requests\TopRequest $request
     *
     * @return mixed
     */
    protected function decryptRequest($response, $fieldsConfig, $session)
    {
        if (empty($fieldsConfig)) {
            return $response;
        }
        if (!$this->getSecurityClient()) {
            throw new \Exception('解密必须指定安全客户端');
        }

        $decrypt = function ($fieldsConfig, $filteredResponse) use (&$decrypt, $session) {
            foreach ($fieldsConfig as $key => $configs) {
                if ($key === '@') {//表示原数据的对应子节点是个数组 如：trades.trade[]
                    foreach ($filteredResponse as $subResponseKey => $singleSubResponse) {
                        Arr::set($filteredResponse, $subResponseKey, $decrypt($configs, $singleSubResponse));
                    }
                } elseif (is_array($configs)) {//表示原数据的对应子节点是直接就是字段
                    $subResponse = Arr::get($filteredResponse, $key);
                    if ($subResponse) {
                        Arr::set($filteredResponse, $key, $decrypt($configs, $subResponse));
                    }
                } elseif (isset($filteredResponse[$key])) {
                    $filedName = $key;
                    $type      = $configs;
                    //                    $this->logger->debug('解密前订单内容：', ['response' => $filteredResponse, 'session' => $session]);
                    $decrypted = $this->getSecurityClient()->decrypt($filteredResponse[$filedName], $type, $session);
                    Arr::set($filteredResponse, $filedName, $decrypted);
                }
            }

            return $filteredResponse;
        };

        return $decrypt($fieldsConfig, $response);
    }

    public function execute($requests, $accessToken = null)
    {
        $returnFirst = false;
        if (!is_array($requests)) {
            $returnFirst = true;
            $requests    = [$requests];
        }
        if (null != $accessToken) {
            foreach ($requests as $k => $req) {
                /**
                 * @var $req \ihipop\TaobaoTop\requests\TopRequest
                 */
                $req->setAccessToken($accessToken);
                $requests[$k] = $req;
            }
        }

        $responses = $this->performRequests($requests);

        if ($returnFirst) {
            return current($responses);
        }

        return $responses;
    }
}
