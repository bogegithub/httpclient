<?php
namespace princebo\httpclient;

use princebo\httpclient\exception\RequestException;
use CURLFile;
use finfo;
use InvalidArgumentException;
use LibXMLError;
use RuntimeException;

/**
 *  Class AsyncClient  HTTP 协议请求异步客户端
 * User: yanbo
 * Date: 2021/4/15
 * Time: 18:40
 * @package princebo\httpclient
 */
class AsyncClient
{
    use ClientTrait;

    /** @var array Multi Curl中的一些默认规则配置项 */
    private const DEFAULT_MULTI_OPTIONS = [
        CURLMOPT_PIPELINING => 1,
    ];
    /** @var resource Multi Curl 资源 */
    private $multiHeader = null;
    /** @var array resource[] Easy Curl资源组 */
    private $easyHandlers = [];
    /** @var array resource[] 已用到的 Easy Curl资源组 */
    private $usedEasyHandlers = [];
    /** @var array 二维数组,Easy Curl选项[key => value],每一维代表一个Easy curl的配置选项 */
    private $easyOptions = [];
    /** @var array 二维数组,Easy Http请求头headers [key => value],每一维代表一个Easy HTTP请求的header */
    private $easyHeaders = [];
    /** @var array 二维数组,Easy Client Cookies [key => value],每一维代表一个Easy HTTP请求的Cookies */
    private $easyCookies = [];
    /** @var array 二维数组,Easy POST数据,每一维代表一个Easy HTTP请求的POST数据 */
    private $easyPostData = [];
    /** @var array 二维数组,所有 HTTP 请求的结果内容,每一维代表一个请求的结果内容 */
    private $resultsContent = [];
    /** @var array 二维数组,每一个 Easy Handler 生成结果失败后的错误信息 */
    private $resultsErrorInfo = [];
    /** @var bool select 执行是否完成 */
    private $selectCompleted = false;
    /** @var bool generateResultsContent 生成结果内容是否完成 */
    private $generateResultsContentCompleted = false;

    /**
     * AsyncClient constructor.
     */
    public function __construct()
    {
        $this->multiHeader = curl_multi_init();
        if (false === $this->multiHeader) {
            throw new RuntimeException('创建Multi Handler失败！');
        }

        foreach (self::DEFAULT_MULTI_OPTIONS as $option => $value) {
            $result = curl_multi_setopt($this->multiHeader, $option, $value);
            if (false === $result) {
                throw self::generateExceptionOnMulti(curl_multi_errno($this->multiHeader));
            }
        }
    }

    /**
     * get 发送HTTP GET请求
     * @param int $handlerId
     * @param null|string $url
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function get(int $handlerId, ?string $url = null): self
    {
        if ($this->isValidHandlerId($handlerId)) {
            $this->easyOptions[$handlerId][CURLOPT_HTTPGET] = true;
            $this->usedEasyHandlers[] = $this->easyHandlers[$handlerId];
            ! empty($url) && $this->setUrl($handlerId, $url);
        }

        return $this;
    }

    /**
     * post 发送HTTP POST请求
     * @param int $handlerId
     * @param array $data
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function post(int $handlerId, array $data = []): self
    {
        if ($this->isValidHandlerId($handlerId)) {
            if (! empty($data)) {
                $this->addPostData($handlerId, $data);
            }
            if (! empty($this->easyPostData[$handlerId])) {
                $this->easyOptions[$handlerId][CURLOPT_POSTFIELDS] = http_build_query($this->easyPostData[$handlerId]);
            }
            $this->easyOptions[$handlerId][CURLOPT_POST] = true;
            $this->usedEasyHandlers[] = $this->easyHandlers[$handlerId];
        }

        return $this;
    }

    /**
     * postJson 发送HTTP POST上传JSON数据
     * @param int $handlerId
     * @param string $jsonString
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function postJson(int $handlerId, string $jsonString): self
    {
        if ($this->isValidHandlerId($handlerId)) {
            json_decode($jsonString);
            if (JSON_ERROR_NONE !== json_last_error()) {
                $this->clear();
                throw new InvalidArgumentException('参数错误，' . json_last_error_msg());
            }

            $this->easyOptions[$handlerId][CURLOPT_CUSTOMREQUEST] = 'POST';
            $this->easyOptions[$handlerId][CURLOPT_POSTFIELDS] = $jsonString;
            $this->addHeader($handlerId, 'Content-Type', 'application/json; charset=UTF-8');
            $this->addHeader($handlerId, 'Content-Length', strlen($jsonString));
            $this->usedEasyHandlers[] = $this->easyHandlers[$handlerId];
        }

        return $this;
    }

    /**
     * postXml 发送HTTP POST上传XML数据
     * @param int $handlerId
     * @param string $xmlString
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function postXml(int $handlerId, string $xmlString): self
    {
        if ($this->isValidHandlerId($handlerId)) {
            libxml_use_internal_errors(true);
            simplexml_load_string($xmlString);
            if (false === $xmlString) {
                $libXMLError = libxml_get_last_error();
                $libXMLErrorString = $libXMLError instanceof LibXMLError ? $libXMLError->message : '未知XML错误';
                $this->clear();
                throw new InvalidArgumentException('参数错误，无效的XML字符串，' . $libXMLErrorString);
            }
            $this->usedEasyHandlers[] = $this->easyHandlers[$handlerId];
            $this->easyOptions[$handlerId][CURLOPT_CUSTOMREQUEST] = 'POST';
            $this->easyOptions[$handlerId][CURLOPT_POSTFIELDS] = $xmlString;
            $this->addHeader($handlerId, 'Content-Type', 'application/xml; charset=UTF-8');
            $this->addHeader($handlerId, 'Content-Length', strlen($xmlString));
        }

        return $this;
    }

    /**
     * upload 发送HTTP POST上传文件
     * @param int $handlerId
     * @param null|string $filePath
     * @param null|string $postName
     * @param null|string $fileName
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function upload(
        int $handlerId,
        ?string $filePath = null,
        ?string $postName = null,
        ?string $fileName = null
    ): self {
        if ($this->isValidHandlerId($handlerId)) {
            if (! empty($filePath) && ! empty($postName)) {
                $this->addUploadFile($handlerId, $filePath, $postName, $fileName);
            }
            if (! empty($this->easyPostData[$handlerId])) {
                $this->easyOptions[$handlerId][CURLOPT_POSTFIELDS] = $this->easyPostData[$handlerId];
            }
            $this->easyOptions[$handlerId][CURLOPT_CUSTOMREQUEST] = 'POST';
            $this->addHeader($handlerId, 'Content-Type', 'multipart/form-data');
            $this->usedEasyHandlers[] = $this->easyHandlers[$handlerId];
        }

        return $this;
    }

    /**
     * createHandler 创建一个HTTP处理器
     * @param null|string $url
     * @return int
     * @author yanbo
     * @date 2021/4/15
     */
    public function createHandler(?string $url = null): int
    {
        $easyHeader = curl_init();
        if (false === $easyHeader) {
            $this->clear();
            throw new RuntimeException('创建Handler失败！');
        }

        $handlerId = self::getEasyHandlerId($easyHeader);
        $this->easyHandlers[$handlerId] = $easyHeader;
        ! empty($url) && $this->setUrl($handlerId, $url);

        return $handlerId;
    }

    /**
     * exec 执行所有已经添加进的HTTP异步请求
     * @return bool
     * @author yanbo
     * @date 2021/4/15
     */
    public function exec(): bool
    {
        $this->addEasyToMulti();

        do {
            $execStatus = curl_multi_exec($this->multiHeader, $stillRunning);
        } while (CURLM_CALL_MULTI_PERFORM === $execStatus);

        if (CURLM_OK === $execStatus) {
            return true;
        }

        $this->clear();
        throw self::generateExceptionOnMulti(curl_multi_errno($this->multiHeader));
    }

    /**
     * getResult 获取运行结果
     * @param int $handlerId
     * @return null|string
     * @author yanbo
     * @date 2021/4/15
     */
    public function getResult(int $handlerId): ?string
    {
        $this->generateResultsContent();

        if (! array_key_exists($handlerId, $this->resultsContent)) {
            throw new InvalidArgumentException('不存在的 Handler ID ' . $handlerId . '。');
        }

        if (! isset($this->resultsErrorInfo[$handlerId]['err_no']) || 0 != $this->resultsErrorInfo[$handlerId]['err_no']) {
            throw new RequestException('获取 ' . $handlerId . ' 结果失败：' . $this->resultsErrorInfo[$handlerId]['err_msg'] ?? '未知错误。');
        }

        return $this->resultsContent[$handlerId];
    }

    /**
     * setUrl 设置指定的 HTTP 请求的 URL
     * @param int $handlerId
     * @param string $url
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function setUrl(int $handlerId, string $url): self
    {
        if (empty($url)) {
            throw new InvalidArgumentException('URL 地址不能为空！');
        }

        if ($this->isValidHandlerId($handlerId)) {
            $this->easyOptions[$handlerId][CURLOPT_URL] = $url;
        }

        return $this;
    }

    /**
     * setAcceptEncoding 设置HTTP请求头中"Accept-Encoding: "的值
     * 这使得能够解码响应的内容,支持的编码有"identity","deflate"和"gzip"
     * 如果为空字符串"",会发送所有支持的编码类型
     * @param int $handlerId
     * @param string $acceptEncoding
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function setAcceptEncoding(int $handlerId, string $acceptEncoding): self
    {
        if (empty($acceptEncoding)) {
            throw new InvalidArgumentException('Accept Encoding 不能为空！');
        }
        if ($this->isValidHandlerId($handlerId)) {
            $this->easyOptions[$handlerId][CURLOPT_ENCODING] = $acceptEncoding;
        }

        return $this;
    }

    /**
     * etReferer 设置HTTP请求头中"Referer: "的内容
     * @param int $handlerId
     * @param string $referer
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function setReferer(int $handlerId, string $referer): self
    {
        if (empty($referer)) {
            throw new InvalidArgumentException('Referer 不能为空！');
        }
        if ($this->isValidHandlerId($handlerId)) {
            $this->easyOptions[$handlerId][CURLOPT_REFERER] = $referer;
        }

        return $this;
    }

    /**
     * setUserAgent 设置HTTP请求中的用户代理"User-Agent: "字符串
     * @param int $handlerId
     * @param string $userAgent
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function setUserAgent(int $handlerId, string $userAgent): self
    {
        if (empty($userAgent)) {
            throw new InvalidArgumentException('UserAgent 不能为空！');
        }
        if ($this->isValidHandlerId($handlerId)) {
            $this->easyOptions[$handlerId][CURLOPT_USERAGENT] = $userAgent;
        }

        return $this;
    }

    /**
     * addHeader 添加 Http 请求 Header
     * @param int $handlerId
     * @param string $name
     * @param string $value
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function addHeader(int $handlerId, string $name, string $value): self
    {
        if (empty($name) || empty($value)) {
            throw new InvalidArgumentException('Name 和 Value 不能为空！');
        }
        if ($this->isValidHandlerId($handlerId)) {
            $this->easyHeaders[$handlerId][$name] = $value;
        }

        return $this;
    }

    /**
     * addCookie 添加Cookie
     * @param int $handlerId
     * @param string $name
     * @param string $value
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/15
     */
    public function addCookie(int $handlerId, string $name, string $value): self
    {
        if (empty($name) || empty($value)) {
            throw new InvalidArgumentException('Name 和 Value 不能为空！');
        }
        if ($this->isValidHandlerId($handlerId)) {
            $this->easyCookies[$handlerId][$name] = $value;
        }

        return $this;
    }

    /**
     * addPostData 添加HTTP POST数据
     * @param int $handlerId
     * @param array $postData
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/16
     */
    public function addPostData(int $handlerId, array $postData): self
    {
        if (empty($postData)) {
            throw new InvalidArgumentException('Post Data 不能为空！');
        }
        if ($this->isValidHandlerId($handlerId)) {
            ! empty($this->easyPostData[$handlerId]) && $postData = array_merge(
                $this->easyPostData[$handlerId],
                $postData
            );
            $this->easyPostData[$handlerId] = $postData;
        }

        return $this;
    }

    /**
     * addUploadFile 添加一个上传文件
     * @param int $handlerId
     * @param string $filePath
     * @param string $postName
     * @param null|string $fileName
     * @return AsyncClient
     * @author yanbo
     * @date 2021/4/16
     */
    public function addUploadFile(
        int $handlerId,
        string $filePath,
        string $postName,
        ?string $fileName = null
    ): self {
        if (empty($filePath) || empty($postName)) {
            throw new InvalidArgumentException('文件名或表单名不能为空');
        }
        if ($this->isValidHandlerId($handlerId)) {
            if (false == ($filePath = realpath($filePath))) {
                throw  new RuntimeException('文件路径不是有效的文件');
            }

            $fileMimeType = (new finfo())->file($filePath, FILEINFO_MIME_TYPE) ?? '';

            empty($fileName) && $fileName = basename($filePath);
            $cFile = new CURLFile($filePath, $fileMimeType, $fileName);
            $this->easyPostData[$handlerId][$postName] = $cFile;
        }

        return $this;
    }

    /**
     * getResultsErrorInfoByHandler 获取出错结果的错误信息
     * @param int $handlerId
     * @return array
     * @author yanbo
     * @date 2021/4/16
     */
    public function getResultsErrorInfoByHandler(int $handlerId): array
    {
        if (! array_key_exists($handlerId, $this->resultsErrorInfo)) {
            throw new InvalidArgumentException('不存在的 Handler ID 。');
        }

        return $this->resultsErrorInfo[$handlerId];
    }

    /**
     * addEasyToMulti 添加Easy Curl到Multi Handler
     * @author yanbo
     * @date 2021/4/16
     */
    private function addEasyToMulti(): void
    {
        $this->processingEasyOptions();
        foreach ($this->usedEasyHandlers as $easyHandler) {
            if (0 !== curl_multi_add_handle($this->multiHeader, $easyHandler)) {
                $this->clear();
                throw self::generateExceptionOnMulti(curl_multi_errno($this->multiHeader));
            }
        }
    }

    /**
     * processingEasyOptions 处理 Easy Curl 的选项
     * @author yanbo
     * @date 2021/4/16
     */
    private function processingEasyOptions(): void
    {
        $this->generateEasyHeaderOption();
        $this->generateEasyCookiesOption();
        $defaultOption = self::generateDefaultOptions();

        foreach ($this->easyHandlers as $handlerId => $easyHandler) {
            if ($this->isValidHandlerId($handlerId)) {
                $options = $defaultOption;
                if (! empty($this->easyOptions[$handlerId])) {
                    $options = $this->easyOptions[$handlerId] + $options;
                }
                $this->setEasyOptions($easyHandler, $options);
            }
        }
    }

    /**
     * setEasyOptions 设置指定的 easy handler options
     * @param $easyHandler
     * @param array $options
     * @author yanbo
     * @date 2021/4/16
     */
    private function setEasyOptions($easyHandler, array $options): void
    {
        if (! empty($options)) {
            $res = curl_setopt_array($easyHandler, $options);
            if (false === $res) {
                $this->clear();
                throw self::generateExceptionOnEasy(curl_errno($easyHandler));
            }
        }
    }

    /**
     * generateEasyHeaderOption 生成Easy Curl Header
     * @author yanbo
     * @date 2021/4/16
     */
    private function generateEasyHeaderOption(): void
    {
        foreach ($this->easyHandlers as $handlerId => $easyHandler) {
            if (! empty($this->easyHeaders[$handlerId])) {
                foreach ($this->easyHeaders[$handlerId] as $key => $value) {
                    $headers[] = "{$key}: {$value}";
                }
                if (! empty($headers)) {
                    $this->easyOptions[$handlerId][CURLOPT_HTTPHEADER] = $headers;
                    unset($headers);
                }
            }
        }
    }

    /**
     * generateEasyCookiesOption 生成Easy Curl Cookie选项
     * @author yanbo
     * @date 2021/4/16
     */
    private function generateEasyCookiesOption(): void
    {
        foreach ($this->easyHandlers as $handlerId => $easyCurlHandler) {
            if (! empty($this->easyCookies[$handlerId])) {
                foreach ($this->easyCookies[$handlerId] as $key => $value) {
                    $cookies[] = "{$key}: {$value}";
                }
                if (! empty($cookies)) {
                    $this->easyOptions[$handlerId][CURLOPT_COOKIE] = implode('; ', $cookies);
                }
            }
        }
    }

    /**
     * generateResultsContent 生成所有HTTP并发请求的结果内容
     * @author yanbo
     * @date 2021/4/16
     */
    private function generateResultsContent(): void
    {
        if (! $this->generateResultsContentCompleted) {
            $this->select();
            $this->generateResultsContentWithMultiInfoRead();
            foreach ($this->easyHandlers as $handlerId => $easyHandler) {
                if (! array_key_exists($handlerId, $this->resultsContent)) {
                    $this->resultsContent[$handlerId] = null;
                    empty($this->resultsErrorInfo[$handlerId]) && $this->resultsErrorInfo[$handlerId] = [
                        'err_no' => -1,
                        'err_msg' => '当前 Easy Handler 的请求并未操作发送请求.',
                    ];
                }
                curl_multi_remove_handle($this->multiHeader, $easyHandler);
            }
            $this->generateResultsContentCompleted = true;
        }
    }

    /**
     *  select socket select
     * @author yanbo
     * @date 2021/4/16
     */
    private function select(): void
    {
        if (! $this->selectCompleted) {
            do {
                $execStatus = curl_multi_exec($this->multiHeader, $stillRunning);
                if ($stillRunning >= 0) {
                    // 出让CPU,等待select信号
                    curl_multi_select($this->multiHeader);
                }
            } while (CURLM_OK === $execStatus && $stillRunning > 0);

            $this->selectCompleted = true;
        }
    }

    /**
     *  generateResultsContentWithMultiInfoRead 根据 MultiInfoRead 信息获取请求的结果内容
     * @author yanbo
     * @date 2021/4/16
     */
    private function generateResultsContentWithMultiInfoRead(): void
    {
        do {
            $easyInfoRead = curl_multi_info_read($this->multiHeader, $msgInQueue);
            if (false !== $easyInfoRead) {
                $easyHandlerId = self::getEasyHandlerIdByMultiInfoRead($easyInfoRead);
                if (
                    isset($easyInfoRead['msg'])
                    && CURLMSG_DONE == $easyInfoRead['msg']
                    && isset($easyInfoRead['result'])
                    && CURLE_OK == $easyInfoRead['result']
                ) {
                    $this->resultsContent[$easyHandlerId] = curl_multi_getcontent($this->easyHandlers[$easyHandlerId]);

                    $errNo = 0;
                    $errMsg = 'No error occurred.';
                } else {
                    $this->resultsContent[$easyHandlerId] = null;

                    $errNo = $easyInfoRead['result'] ?? $easyInfoRead['msg'] ?? -2;
                    $errMsg = -2 == $errNo ? 'Unknown Error.' : curl_strerror($errNo);
                }
                $this->resultsErrorInfo[$easyHandlerId] = [
                    'err_no' => $errNo,
                    'err_msg' => $errMsg,
                ];
            }
        } while ($msgInQueue > 0);
    }

    /**
     * isValidHandlerId 判断 Handler ID 是否有效
     * @param int $handlerId
     * @return bool
     * @author yanbo
     * @date 2021/4/16
     */
    private function isValidHandlerId(int $handlerId): bool
    {
        if (empty($this->easyHandlers[$handlerId])) {
            throw new InvalidArgumentException('Handler ID Non-existent');
        }
        if (! self::isEasyHandler($this->easyHandlers[$handlerId])) {
            throw new RuntimeException("第 {$handlerId} HTTP 请求未初始化！");
        }

        return true;
    }

    /**
     * generateExceptionOnMulti 根据curl_multi_*系列函数执行的错误码生成异常
     * @param int $curlMultiErrorNumber
     * @return RequestException
     * @author yanbo
     * @date 2021/4/16
     */
    private static function generateExceptionOnMulti(int $curlMultiErrorNumber): RequestException
    {
        if (false === $curlMultiErrorNumber) {
            $curlMultiErrorNumber = 0;
            $errorMessage = '未知CURL MULTI 错误！';
        } else {
            $errorMessage = curl_multi_strerror($curlMultiErrorNumber);
        }

        return new RequestException($errorMessage, $curlMultiErrorNumber);
    }

    /**
     * getEasyHandlerIdByMultiInfoRead 根据 MultiInfoRead 信息获取对应的 Easy Handler ID
     * @param array $easyInfoRead
     * @return int
     * @author yanbo
     * @date 2021/4/16
     */
    final private static function getEasyHandlerIdByMultiInfoRead(array $easyInfoRead): int
    {
        if (! isset($easyInfoRead['handle'])) {
            throw new InvalidArgumentException('无效的 Easy Info Read 参数。');
        }

        $handlerString = $easyInfoRead['handle'];

        $matchRes = preg_match("/\d+/", (string)$handlerString, $matches);
        if (false === $matchRes) {
            throw new RuntimeException('匹配失败，Error Code Is ' . preg_last_error());
        }
        if (1 !== $matchRes || ! isset($matches[0])) {
            throw new RuntimeException('未匹配到资源ID，可能是因为系统升级导致。');
        }

        return $matches[0];
    }

    /**
     * clear
     * @author yanbo
     * @date 2021/4/16
     */
    private function clear(): void
    {
        $this->easyHandlers = null;
        $this->easyOptions = [];
        $this->easyHeaders = [];
        $this->easyCookies = [];
        $this->easyPostData = [];
        $this->resultsContent = [];
        $this->resultsErrorInfo = [];
        $this->usedEasyHandlers = [];
        $this->selectCompleted = false;
        $this->generateResultsContentCompleted = false;
    }

    /**
     * close
     * @author yanbo
     * @date 2021/4/16
     */
    private function close(): void
    {
        foreach ($this->easyHandlers as $easyHandler) {
            curl_close($easyHandler);
        }
        curl_multi_close($this->multiHeader);
    }

    /**
     * Client __destruct.
     */
    public function __destruct()
    {
        $this->close();
    }

}
