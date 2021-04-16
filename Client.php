<?php
namespace princebo\httpclient;

use princebo\httpclient\exception\RequestException;
use CURLFile;
use finfo;
use InvalidArgumentException;
use LibXMLError;
use RuntimeException;

/**
 * HTTP协议请求客户端类库
 * Class Client
 * User: yanbo
 * Date: 2021/4/15
 * Time: 16:20
 * @package princebo\httpclient
 */
class Client implements ClientInterface
{
    use ClientTrait;

    /** @var resource Easy Curl 资源 */
    private $easyHandler;
    /** @var array Curl选项[key => value] */
    private $options = [];
    /** @var string $url 请求的URL地址 */
    private $url;
    /** @var array Http请求头headers [key => value] */
    private $headers = [];
    /** @var array Client Cookies [key => value] */
    private $cookies = [];
    /** @var array POST 数据 */
    private $postData = [];

    /**
     * Client constructor.
     * @param null|string $url
     */
    public function __construct(?string $url = null)
    {
        $this->url = $url ?? null;
        $this->easyHandler = curl_init($this->url);
        if (false === $this->easyHandler) {
            throw new RuntimeException('创建Handler失败！');
        }
    }

    /**
     * get 发送HTTP GET请求
     * @return mixed|void
     * @author yanbo
     * @date 2021/4/15
     */
    public function get()
    {
        $this->options[CURLOPT_HTTPGET] = true;

        return $this->exec();
    }

    /**
     * post 发送HTTP POST请求
     * @param array $data
     * @return mixed|void
     * @author yanbo
     * @date 2021/4/15
     */
    public function post(array $data = [])
    {
        ! empty($data) && $this->postData = array_merge($this->postData, $data);
        ! empty($this->postData) && $this->options[CURLOPT_POSTFIELDS] = http_build_query($this->postData);
        $this->options[CURLOPT_POST] = true;

        return $this->exec();
    }

    /**
     * postJson 发送HTTP POST上传JSON数据
     * @param string $jsonString
     * @return mixed|void
     * @author yanbo
     * @date 2021/4/15
     */
    public function postJson(string $jsonString)
    {
        json_decode($jsonString);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException('参数错误，' . json_last_error_msg());
        }

        $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
        $this->options[CURLOPT_POSTFIELDS] = $jsonString;
        $this->addHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->addHeader('Content-Length', strlen($jsonString));

        return $this->exec();
    }

    /**
     * postXml 发送HTTP POST上传XML数据
     * @param string $xmlString
     * @return mixed|void
     * @author yanbo
     * @date 2021/4/15
     */
    public function postXml(string $xmlString)
    {
        libxml_use_internal_errors(true);
        simplexml_load_string($xmlString);
        if (false === $xmlString) {
            $libXMLError = libxml_get_last_error();
            $libXMLErrorString = $libXMLError instanceof LibXMLError ? $libXMLError->message : '未知XML错误';
            throw new InvalidArgumentException('参数错误，无效的XML字符串，' . $libXMLErrorString);
        }
        $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
        $this->options[CURLOPT_POSTFIELDS] = $xmlString;
        $this->addHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->addHeader('Content-Length', strlen($xmlString));

        return $this->exec();
    }

    /**
     * upload 发送HTTP POST上传文件
     * @param null|string $filePath
     * @param null|string $postName
     * @param null|string $fileName
     * @return mixed|void
     * @author yanbo
     * @date 2021/4/15
     */
    public function upload(?string $filePath = null, ?string $postName = null, ?string $fileName = null)
    {
        if (! empty($filePath) && ! empty($postName)) {
            $this->addUploadFile($filePath, $postName, $fileName);
        }
        ! empty($this->postData) && $this->options[CURLOPT_POSTFIELDS] = $this->postData;
        $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
        $this->addHeader('Content-Type', 'multipart/form-data');

        return $this->exec();
    }

    /**
     * setUrl 设置HTTP请求的URL
     * @param string $url
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function setUrl(string $url): self
    {
        if (empty($url)) {
            throw new InvalidArgumentException('URL地址不能为空！');
        }

        $this->options[CURLOPT_URL] = $url;

        return $this;
    }

    /**
     * setAcceptEncoding 设置HTTP请求头中"Accept-Encoding: "的值
     * 这使得能够解码响应的内容,支持的编码有"identity","deflate"和"gzip"
     * 如果为空字符串"",会发送所有支持的编码类型
     * @param string $acceptEncoding
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function setAcceptEncoding(string $acceptEncoding): self
    {
        if (empty($acceptEncoding)) {
            throw new InvalidArgumentException('Accept Encoding不能为空！');
        }

        $this->options[CURLOPT_ENCODING] = $acceptEncoding;

        return $this;
    }

    /**
     * setReferer 设置HTTP请求头中"Referer: "的内容
     * @param string $referer
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function setReferer(string $referer): self
    {
        if (empty($referer)) {
            throw new InvalidArgumentException('Referer不能为空！');
        }

        $this->options[CURLOPT_REFERER] = $referer;

        return $this;
    }

    /**
     * setUserAgent 设置HTTP请求中的用户代理"User-Agent: "字符串
     * @param string $userAgent
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function setUserAgent(string $userAgent): self
    {
        if (empty($userAgent)) {
            throw new InvalidArgumentException('UserAgent不能为空！');
        }

        $this->options[CURLOPT_USERAGENT] = $userAgent;

        return $this;
    }

    /**
     * addHeader 添加Http请求Header
     * @param string $name
     * @param string $value
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function addHeader(string $name, string $value): self
    {
        if (empty($name) || empty($value)) {
            throw new InvalidArgumentException('Name和Value不能为空！');
        }

        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * addCookie 添加Cookie
     * @param string $name
     * @param string $value
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function addCookie(string $name, string $value): self
    {
        if (empty($name) || empty($value)) {
            throw new InvalidArgumentException('Name和Value不能为空！');
        }

        $this->cookies[$name] = $value;

        return $this;
    }

    /**
     * addPostData 添加HTTP POST数据
     * @param array $postData
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function addPostData(array $postData): self
    {
        if (empty($postData)) {
            throw new InvalidArgumentException('Post Data不能为空！');
        }

        $this->postData = array_merge($this->postData, $postData);

        return $this;
    }

    /**
     * addUploadFile 添加一个上传文件
     * @param string $filePath
     * @param string $postName
     * @param null|string $fileName
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function addUploadFile(string $filePath, string $postName, ?string $fileName = null): self
    {
        if (empty($filePath) || empty($postName)) {
            throw new InvalidArgumentException('文件名或表单名不能为空');
        }
        if (false == ($filePath = realpath($filePath))) {
            throw  new RuntimeException('文件路径不是有效的文件');
        }

        $fileMimeType = (new finfo())->file($filePath, FILEINFO_MIME_TYPE) ?? '';

        empty($fileName) && $fileName = basename($filePath);
        $cFile = new CURLFile($filePath, $fileMimeType, $fileName);
        $this->postData[$postName] = $cFile;

        return $this;
    }

    /**
     * exec 执行CURL并返回执行的结果
     * @return mixed|void
     * @author yanbo
     * @date 2021/4/15
     */
    private function exec()
    {
        if (! self::isEasyHandler($this->easyHandler)) {
            throw new RuntimeException('HTTP请求未初始化！');
        }

        $this->processingOptions();
        $result = $this->processingFuncResult(curl_exec($this->easyHandler));
        $this->clear();

        return $result;
    }

    /**
     * processingOptions 处理所有选项
     * @return bool
     * @author yanbo
     * @date 2021/4/15
     */
    private function processingOptions(): bool
    {
        if (! self::isEasyHandler($this->easyHandler)) {
            throw new RuntimeException('HTTP请求未初始化！');
        }

        $this->generateHeaderOption();
        $this->generateCookiesOption();
        $defaultOption = self::generateDefaultOptions();
        $options = $this->options + $defaultOption;

        return empty($options)
            ? true
            : $this->processingFuncResult(curl_setopt_array($this->easyHandler, $options));
    }

    /**
     * generateHeaderOption 生成Header选项
     * @author yanbo
     * @date 2021/4/15
     */
    private function generateHeaderOption(): void
    {
        if (! empty($this->headers)) {
            foreach ($this->headers as $key => $value) {
                $headers[] = "{$key}: {$value}";
            }
            ! empty($headers) && $this->options[CURLOPT_HTTPHEADER] = $headers;
        }
    }

    /**
     * generateCookiesOption 生成Cookie选项
     * @author yanbo
     * @date 2021/4/15
     */
    private function generateCookiesOption(): void
    {
        if (! empty($this->cookies)) {
            foreach ($this->cookies as $key => $value) {
                $cookies[] = "{$key}={$value}";
            }
            ! empty($cookies) && $this->options[CURLOPT_COOKIE] = implode('; ', $cookies);
        }
    }

    /**
     * clear 运行完的清理工作
     * @author yanbo
     * @date 2021/4/15
     */
    private function clear(): void
    {
        $this->headers = [];
        $this->cookies = [];
        $this->options = [];
        $this->url = null;
        $this->postData = [];
        self::isEasyHandler($this->easyHandler)
        && $this->processingFuncResult(curl_reset($this->easyHandler));
    }

    /**
     * close 关闭当前Http连接
     * @author yanbo
     * @date 2021/4/15
     */
    private function close()
    {
        self::isEasyHandler($this->easyHandler)
        && $this->processingFuncResult(curl_close($this->easyHandler));
    }

    /**
     * processingFuncResult 处理函数运行的结果
     * @param $funcRunRes
     * @author yanbo
     * @date 2021/4/15
     */
    private function processingFuncResult($funcRunRes)
    {
        if (false !== $funcRunRes) {
            return $funcRunRes;
        }

        return $this->throwException();
    }

    /**
     * throwException 用于出问题后抛出异常
     * @author yanbo
     * @date 2021/4/15
     */
    private function throwException()
    {
        $errorCode = $this->getErrorCode();
        throw new RequestException($this->getErrorMessage($errorCode), $errorCode);
    }

    /**
     * getErrorCode 获取运行中的错误Code
     * @return int
     * @author yanbo
     * @date 2021/4/15
     */
    private function getErrorCode(): int
    {
        return self::isEasyHandler($this->easyHandler)
            ? $this->processingFuncResult(curl_errno($this->easyHandler))
            : -1;
    }

    /**
     * getErrorMessage 根据错误吗获取错误信息
     * @param int $errorNumber
     * @return string
     * @author yanbo
     * @date 2021/4/15
     */
    private function getErrorMessage(int $errorNumber): string
    {
        return $this->processingFuncResult(curl_strerror($errorNumber));
    }

    /**
     * 设置 cURL 传输选项
     * @param $curlOptName
     * @param $val
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function setCurlOpt($curlOptName, $val): self
    {
        $this->options[$curlOptName] = $val;

        return $this;
    }

    /**
     * 禁止 https认证 不验证
     * @return Client
     * @author yanbo
     * @date 2021/4/15
     */
    public function closeSslVerify(): self
    {
        $this->options[CURLOPT_SSL_VERIFYPEER] = false;
        $this->options[CURLOPT_SSL_VERIFYHOST] = false;

        return $this;
    }

    /**
     * Client __destruct
     */
    public function __destruct()
    {
        $this->close();
    }
}
