<?php
namespace princebo\httpclient;

use princebo\httpclient\exception\RequestException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Trait ClientTrait Client 公用的一些 Trait 方法
 * User: yanbo
 * Date: 2021/4/15
 * Time: 16:30
 * @package princebo\httpclient
 */
trait ClientTrait
{
    /** @var array Curl 中的一些默认规则配置 */
    private static $defaultEasyOptions = [
        // 自动设置 header 中的 Referer: 信息
        'CURLOPT_AUTOREFERER' => true,
        // 获取的信息以字符串返回,而不是直接输出
        'CURLOPT_RETURNTRANSFER' => true,
        // 在尝试连接时等待的秒数,设置为0,则无限等待
        'CURLOPT_CONNECTTIMEOUT' => 1,
        // 在尝试连接时等待的毫秒数,设置为0,则无限等待
        'CURLOPT_CONNECTTIMEOUT_MS' => 1000,
        // 允许 CURL 函数执行的最长秒数
        'CURLOPT_TIMEOUT' => 60,
        // 允许 CURL 执行的最长毫秒数
        'CURLOPT_TIMEOUT_MS' => 60000,
        // Easy URL 不带协议的时候,使用的默认协议
        'CURLOPT_DEFAULT_PROTOCOL' => 'http',
        // User-Agent
        'CURLOPT_USERAGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.100 Safari/537.36',
        'CURLINFO_HEADER_OUT' => true,
    ];

    /**
     * generateDefaultOptions 生成默认的选项配置
     * @return array
     * @author yanbo
     * @date 2021/4/15
     */
    private static function generateDefaultOptions(): array
    {
        foreach (self::$defaultEasyOptions as $key => $value) {
            defined($key) && $options[constant($key)] = $value;
        }

        return $options ?? [];
    }

    /**
     * isEasyHandler 判断资源句柄是否为 CURL 类型
     * @param $handler
     * @return bool
     * @author yanbo
     * @date 2021/4/15
     */
    private static function isEasyHandler($handler): bool
    {
        if (is_resource($handler) && 'curl' == get_resource_type($handler)) {
            return true;
        }

        return false;
    }

    /**
     * generateException 根据 curl_* 系列函数执行的错误码生成异常
     * @param int $easyErrNum
     * @return RequestException
     * @author yanbo
     * @date 2021/4/15
     */
    private static function generateExceptionOnEasy(int $easyErrNum)
    {
        $errMsg = curl_strerror($easyErrNum);
        empty($errMsg) && $errMsg = '未知CURL错误！';

        return new RequestException($errMsg, $easyErrNum);
    }

    /**
     * getEasyHandlerId 获取 Handler ID
     * @param $handler
     * @return int
     * @author yanbo
     * @date 2021/4/15
     */
    final private static function getEasyHandlerId($handler): int
    {
        if (! is_resource($handler)) {
            throw new InvalidArgumentException('参数不是合法的资源类型！');
        }

        $matchRes = preg_match("/\d+/", (string)$handler, $matches);
        if (false === $matchRes) {
            throw new RuntimeException('匹配失败，Error Code Is ' . preg_last_error());
        }
        if (1 !== $matchRes || ! isset($matches[0])) {
            throw new RuntimeException('未匹配到资源ID，可能是因为系统升级导致。');
        }

        return $matches[0];
    }
}
