<?php
/**
 * Created by diduweiwu
 * User: diduweiwu
 * Date: 2018/2/11
 * Time: 下午4:39
 */

namespace Diduweiwu\Papollo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Mockery\Exception;

class ApolloClient
{
    /**
     * 执行同步任务,将拉取所有配置信息,以键值对方式存储
     *
     * @param bool $is_long_pooling 是否为长轮询发起同步 是:则不抛出异常
     */
    public static function sync($is_long_pooling = false)
    {
        echo '-------执行同步' . self::getNowTime() . '-------' . PHP_EOL;
        $basic_configs = self::getApolloConfig();
        $client        = new Client();

        //1. 组装配置同步url
        $api_url_arr = [
            $basic_configs['host'],
            $basic_configs['module_config'],
            $basic_configs['appid'],
            $basic_configs['cluster'],
            $basic_configs['namespace'],
        ];
        $api_url     = implode('/', $api_url_arr);

        //2. 获取guzzle发送request请求选项数组
        $host    = $basic_configs['host'];
        $options = self::getGuzzleOptions($host);

        try {
            $res       = $client->request('GET', $api_url, $options);
            $response  = $res->getBody()->getContents();
            $http_code = $res->getStatusCode();
        } catch (\Exception $exception) {
            die($exception->getMessage());
        }

        //3. 发送同步请求并处理异常
        $results        = [];
        $config_results = [];

        if ($http_code >= 200 && $http_code < 300) {
            $configurations = json_decode($response, true);
            foreach ($configurations as $key => $configuration) {
                $results[$key]    = $configuration;
                $config_results[] = $key . '=' . $configuration;
            }
        } elseif (!$is_long_pooling) {
            throw new Exception("同步配置任务发生错误" . $http_code);
        }

        //4. 将同步下来的配置写入配置文件
        $file_name = $basic_configs['file_name'];
        $env_path  = base_path($file_name);
        if (!empty($config_results)) {
            file_put_contents($env_path, implode(PHP_EOL, $config_results));
        }
        echo '-------同步完成' . self::getNowTime() . '-------' . PHP_EOL;
    }

    /**
     *
     * 获取guzzle基本请求选项数组
     *
     * @param $host
     *
     * @return array
     */
    private static function getGuzzleOptions($host)
    {
        $options = [];
        if (starts_with($host, 'https:')) {
            $options['verify'] = false;
        }

        return $options;
    }

    /**
     * 获取阿波罗配置中心基础信息
     *
     * @return array
     */
    private static function getApolloConfig()
    {
        $host                = config('apollo.host', null);
        $module_config       = config('apollo.module_config', null);
        $module_notification = config('apollo.module_notification', null);
        $appid               = config('apollo.appid', null);
        $cluster             = config('apollo.cluster', null);
        $namespace           = config('apollo.namespace', null);
        $file_name           = config('apollo.file_name', null);

        if (is_null($host)
            || is_null($module_config)
            || is_null($module_notification)
            || is_null($appid)
            || is_null($cluster)
            || is_null($namespace)
            || is_null($file_name)) {
            throw new Exception('Apollo服务器配置信息不完整');
        }

        return [
            'host'                => $host,
            'module_config'       => $module_config,
            'module_notification' => $module_notification,
            'appid'               => $appid,
            'cluster'             => $cluster,
            'namespace'           => $namespace,
            'file_name'           => $file_name,
        ];
    }

    /**
     *
     * 根据notification_id获取通知接口链接
     *
     * @param int $notification_id
     *
     * @return string
     */
    private static function getNotificationUrl($notification_id = 0)
    {
        $basic_configs       = self::getApolloConfig();
        $host                = $basic_configs['host'];
        $appid               = $basic_configs['appid'];
        $cluster             = $basic_configs['cluster'];
        $namespace           = $basic_configs['namespace'];
        $module_notification = $basic_configs['module_notification'];

        $host_module = $host . '/' . $module_notification . '?';

        $params = [
            'appId=' . $appid,
            'cluster=' . $cluster,
            'notifications=' . json_encode(
                [
                    [
                        'namespaceName'  => $namespace,
                        'notificationId' => $notification_id,
                    ],
                ]
            ),
        ];

        $api_url = $host_module . implode("&", $params);

        return $api_url;
    }

    /**
     * 获取同步通知
     */
    public static function syncWithNotification()
    {
        $notification_id = 0;

        $basic_configs = self::getApolloConfig();
        $host          = $basic_configs['host'];

        $options = self::getGuzzleOptions($host);
        //1.长轮询,至少设置30秒以上请求超时
        $options['timeout'] = 40;

        //2.执行无限长轮询操作
        $client = new Client();

        while (true) {
            echo '长轮询 - 开始' . self::getNowTime() . PHP_EOL;
            try {
                $api_url   = self::getNotificationUrl($notification_id);
                $res       = $client->get($api_url, $options);
                $response  = $res->getBody()->getContents();
                $http_code = $res->getStatusCode();
                //3. 304,表示配置并没有更新,继续做下一次轮询
                if ($http_code == 304) {
                    continue;
                } elseif ($http_code >= 200 && $http_code < 300) {
                    //4. 有更新,则进行配置同步操作
                    $notifications          = json_decode($response, true);
                    $server_notification_id = array_get($notifications, '0.notificationId', null);
                    if (!is_null($notification_id) && $notification_id !== $server_notification_id) {
                        self::sync(true);
                        $notification_id = $server_notification_id;
                    }
                }
            } catch (ClientException $exception) {
                if ($exception->getCode() == 304) {
                    continue;
                };
            }
            echo '长轮询 - 结束' . self::getNowTime() . PHP_EOL;
        }
    }

    /**
     *
     * 获取当前中国时间格式
     * @return false|string
     */
    private static function getNowTime()
    {
        date_default_timezone_set('Asia/Shanghai');
        return date('Y-m-d H:i:s');
    }
}