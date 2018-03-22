<?php
/**
 * Created by PhpStorm.
 * User: mxj
 * Date: 2018/2/11
 * Time: 下午4:39
 */

namespace Diduweiwu\Papollo;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Mockery\Exception;

class ApolloClient
{
    public static function sync()
    {
        $host      = config('apollo.host', null);
        $module    = config('apollo.module', null);
        $appid     = config('apollo.appid', null);
        $cluster   = config('apollo.cluster', null);
        $namespace = config('apollo.namespace', null);
        $file_name = config('apollo.file_name', null);

        if (is_null($file_name)
            || is_null($host)
            || is_null($module)
            || is_null($appid)
            || is_null($cluster)
            || is_null($namespace)) {
            throw new Exception('Apollo服务器配置信息不完整');
        }
        $client      = new Client();
        $api_url_arr = [
            $host,
            $module,
            $appid,
            $cluster,
            $namespace,
        ];
        $api_url     = implode('/', $api_url_arr);

        $options = [];
        if (starts_with($host, 'https:')) {
            $options['verify'] = false;
        }
        try {
            $res       = $client->request('GET', $api_url, $options);
            $response  = $res->getBody();
            $http_code = $res->getStatusCode();
        } catch (\Exception $exception) {
            die($exception->getMessage());
        }

        $results        = [];
        $config_results = [];

        if ($http_code >= 200 && $http_code < 300) {
            $configurations = json_decode($response, true);
            foreach ($configurations as $key => $configuration) {
                $results[$key]    = $configuration;
                $config_results[] = $key . '=' . $configuration;
            }
        } else {
            throw new Exception("连接错误" . $http_code);
        }

        $env_path = base_path($file_name);
        if (!empty($config_results)) {
            file_put_contents($env_path, implode(PHP_EOL, $config_results));
        }
    }

    private static function getNofiticationUrl($notification_id = 0)
    {
        $host      = config('apollo.host', null);
        $module    = 'notifications/v2?';
        $appid     = config('apollo.appid', null);
        $cluster   = config('apollo.cluster', null);
        $namespace = config('apollo.namespace', null);

        if (is_null($host)
            || is_null($module)
            || is_null($appid)
            || is_null($cluster)
            || is_null($namespace)) {
            throw new Exception('Apollo服务器配置信息不完整');
        }


        $client = new Client();

        $host_module = $host . '/' . $module;

        $params = [
            'appId=' . $appid,
            'cluster=' . $cluster,
            'notifications=' . json_encode(
                [
                    [
                        'namespaceName'  => 'application',
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

        $options = [
            'timeout' => 40,
        ];
//        if (starts_with($host, 'https:')) {
//            $options['verify'] = false;
//        }

        $client = new Client();
        while (true) {
            try {
                $api_url   = self::getNofiticationUrl($notification_id);
                $res       = $client->get($api_url, $options);
                $response  = $res->getBody();
                $http_code = $res->getStatusCode();
            } catch (ClientException $exception) {
                if ($exception->getCode() == 304) {
                    continue;
                };
            }

            if ($http_code == 304) {
                //304,表示配置并没有更新,继续做下一次轮询
                continue;
            } elseif ($http_code >= 200 && $http_code < 300) {
                $notifications          = json_decode($response, true);
                $server_notification_id = array_get($notifications, '0.notificationId', null);
                if (!is_null($notification_id) && $notification_id !== $server_notification_id) {
                    self::sync();
                    $notification_id = $server_notification_id;
                }
            }
        }
    }
}