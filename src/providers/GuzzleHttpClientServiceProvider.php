<?php
/**
 * @author ihipop@gmail.com @ 19-2-28 下午2:26 For youzan-sdk.
 */

namespace ihipop\TaobaoTop\providers;

use GuzzleHttp\Client;
use ihipop\TaobaoTop\Application;
use ihipop\TaobaoTop\client\Adapter\GuzzleAdapter;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class GuzzleHttpClientServiceProvider implements ServiceProviderInterface
{

    /**
     * Registers services on the given container.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     *
     * @param Container $pimple A container instance
     */
    public function register(Container $pimple)
    {
        $pimple->offsetSet('httpClient', function (Application $app) {
            $config = $app->getConfig('http.guzzle_config');
            if (empty($config['handler'])) {
                $config['force_handler_over_ride'] = true;
            }

            return new Client($config);
        });
        $pimple->offsetSet('httpClientAdapter', function (Application $app) {
            return new GuzzleAdapter($app->offsetGet('httpClient'));
        });

        $pimple->offsetGet('httpClient');
        $pimple->offsetGet('httpClientAdapter');
    }
}