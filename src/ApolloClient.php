<?php
/**
 * Created by PhpStorm.
 * User: mxj
 * Date: 2018/2/11
 * Time: 下午4:39
 */

namespace Diduweiwu\Papollo;


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

        $api_url_arr = [
            $host,
            $module,
            $appid,
            $cluster,
            $namespace,
        ];
        $api_url     = implode('/', $api_url_arr);
        $ch          = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if (starts_with($host, 'https:')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // https请求 不验证证书和hosts
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $response       = curl_exec($ch);
        $http_code      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $results        = [];
        $config_results = [];

        if ($http_code >= 200 && $http_code < 300) {
            $configurations = json_decode($response, true);
            foreach ($configurations as $key => $configuration) {
                $results[$key]    = $configuration;
                $config_results[] = $key . '=' . $configuration;
            }
        }

        curl_close($ch);
        $env_path = base_path($file_name);
        if (!empty($config_results)) {
            file_put_contents($env_path, implode(PHP_EOL, $config_results));
        }
    }
}